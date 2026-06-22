import { pipeline, AutoTokenizer, AutoModelForSequenceClassification } from '@xenova/transformers';
import express from 'express';

const PORT = process.env.PORT || 3000;
const MODEL = process.env.MODEL || 'Xenova/multilingual-e5-small';
const RERANKER_MODEL = process.env.RERANKER_MODEL || 'Xenova/ms-marco-TinyBERT-L-2-v2';
const MAX_BATCH_SIZE = parseInt(process.env.MAX_BATCH_SIZE || '64', 10);
const ENABLE_RERANKER = process.env.ENABLE_RERANKER !== '0';

const app = express();
app.use(express.json({ limit: '10mb' }));

let embedder = null;
let dimension = 384; // default fallback
let rerankerModel = null;
let rerankerTokenizer = null;

// ─── Priority queue ───────────────────────────────────────────────────────────
//
// All /embed work runs through a two-tier queue so that:
//   HIGH  – single-text search queries answer in ~40–100 ms regardless of
//            what reindex batches are in flight.
//   NORMAL – reindex / bulk requests run in the background.
//
// The dispatcher picks one HIGH task before every NORMAL task.
// If there is nothing in HIGH it falls through to NORMAL immediately.
//
// Node.js is single-threaded, so this is purely logical ordering —
// there is no actual preemption.  A NORMAL chunk that has already
// started will finish before the next HIGH task runs.  Keeping
// MAX_BATCH_SIZE at 32 means the worst-case wait for a HIGH task is
// one chunk (~500 ms), not an entire 50-text reindex batch.

const queues = { high: [], normal: [] };
let running = false;

function enqueue(fn, priority = 'normal') {
    return new Promise((resolve, reject) => {
        queues[priority].push(async () => {
            try { resolve(await fn()); }
            catch (err) { reject(err); }
        });
        kick();
    });
}

async function kick() {
    if (running) return;
    const task = queues.high.shift() ?? queues.normal.shift();
    if (!task) return;
    running = true;
    try { await task(); }
    finally { running = false; kick(); }
}

// ─── Model loading ───────────────────────────────────────────────────────────

async function loadModel() {
    console.log(`[embedding-service] Loading model ${MODEL}...`);
    embedder = await pipeline('feature-extraction', MODEL, {
        cache_dir: './models',
        session_options: {
            intraOpNumThreads: 4,
            interOpNumThreads: 4,
        }
    });
    // Auto-detect actual vector dimension
    try {
        const dummy = await embedBatch(['dummy']);
        dimension = dummy[0].length;
        console.log(`[embedding-service] Model ready. Detected dimension: ${dimension}`);
    } catch (err) {
        console.error('[embedding-service] Error detecting dimension, using fallback:', err.message);
    }
}

async function loadReranker() {
    if (!ENABLE_RERANKER) {
        console.log('[embedding-service] Reranker disabled by ENABLE_RERANKER=0.');
        return;
    }

    console.log(`[embedding-service] Loading reranker model ${RERANKER_MODEL}...`);
    rerankerModel = await AutoModelForSequenceClassification.from_pretrained(RERANKER_MODEL, {
        cache_dir: './models',
        session_options: {
            intraOpNumThreads: 4,
            interOpNumThreads: 4,
        }
    });
    rerankerTokenizer = await AutoTokenizer.from_pretrained(RERANKER_MODEL, {
        cache_dir: './models',
    });
    console.log(`[embedding-service] Reranker model ${RERANKER_MODEL} ready.`);
}

// ─── Core inference ──────────────────────────────────────────────────────────

/**
 * Single forward pass for an array of texts (must be ≤ MAX_BATCH_SIZE).
 * Returns number[][] (one row per text).
 */
async function embedBatch(texts) {
    const output = await embedder(texts, { pooling: 'mean', normalize: true });
    const dim = output.data.length / texts.length;
    const result = [];
    for (let i = 0; i < texts.length; i++) {
        result.push(Array.from(output.data.slice(i * dim, (i + 1) * dim)));
    }
    return result;
}

// ─── Routes ──────────────────────────────────────────────────────────────────

/**
 * POST /embed
 * Body:    { texts: string[], priority?: 'high' | 'normal' }
 * Headers: X-Embed-Priority: high   (alternative)
 *
 * Priority is automatically set to 'high' when texts.length === 1
 * (i.e. a live search query).  Bulk reindex requests use 'normal'.
 *
 * Large arrays are chunked into MAX_BATCH_SIZE slices; each slice is
 * enqueued individually so high-priority single-text queries can slip
 * in between chunks.
 */
app.post('/embed', async (req, res) => {
    if (!embedder) {
        return res.status(503).json({ error: 'Model not loaded yet, retry in a moment.' });
    }

    const { texts, priority: bodyPriority } = req.body;
    if (!Array.isArray(texts) || texts.length === 0) {
        return res.status(400).json({ error: 'texts must be a non-empty array of strings.' });
    }

    // Determine priority:
    //   1. Explicit body field            { priority: 'high' }
    //   2. Request header                 X-Embed-Priority: high
    //   3. Auto-detect: 1 text → high (search query), >1 → normal (reindex)
    const headerPriority = req.headers['x-embed-priority'];
    const priority =
        (bodyPriority === 'high' || headerPriority === 'high') ? 'high'
            : (bodyPriority === 'normal' || headerPriority === 'normal') ? 'normal'
                : texts.length === 1 ? 'high'
                    : 'normal';

    try {
        // Split into chunks and enqueue each one separately.
        // This lets high-priority queries jump between chunks of a bulk request.
        const chunks = [];
        for (let i = 0; i < texts.length; i += MAX_BATCH_SIZE) {
            chunks.push(texts.slice(i, i + MAX_BATCH_SIZE));
        }

        const allEmbeddings = [];
        for (const chunk of chunks) {
            const embeddings = await enqueue(() => embedBatch(chunk), priority);
            allEmbeddings.push(...embeddings);
        }

        return res.json({
            embeddings: allEmbeddings,
            model: MODEL,
            count: allEmbeddings.length,
            priority,
        });
    } catch (err) {
        console.error('[embedding-service] Error:', err);
        return res.status(500).json({ error: err.message });
    }
});

/**
 * POST /rerank
 * Body: { query: string, documents: { id: number, text: string }[] }
 */
app.post('/rerank', async (req, res) => {
    if (!rerankerModel || !rerankerTokenizer) {
        return res.status(503).json({ error: 'Reranker model not loaded yet, retry in a moment.' });
    }

    const { query, documents } = req.body;
    if (!query || typeof query !== 'string') {
        return res.status(400).json({ error: 'query must be a non-empty string.' });
    }
    if (!Array.isArray(documents) || documents.length === 0) {
        return res.status(400).json({ error: 'documents must be a non-empty array of objects.' });
    }

    try {
        const start = Date.now();
        const queries = Array(documents.length).fill(query);
        const docs = documents.map(d => String(d.text || ''));

        const inputs = rerankerTokenizer(queries, {
            text_pair: docs,
            padding: true,
            truncation: true,
        });

        const output = await rerankerModel(inputs);
        const logits = output.logits.data; // Flat array of scores

        const results = documents.map((doc, idx) => ({
            id: doc.id,
            score: parseFloat(logits[idx])
        }));

        // Sort descending by score
        results.sort((a, b) => b.score - a.score);

        const elapsed = Date.now() - start;
        console.log(`[embedding-service] Reranked ${documents.length} documents in ${elapsed} ms.`);

        return res.json({
            ranked: results,
            model: RERANKER_MODEL,
            time_ms: elapsed
        });
    } catch (err) {
        console.error('[embedding-service] Rerank error:', err);
        return res.status(500).json({ error: err.message });
    }
});

/** GET /health */
app.get('/health', (_req, res) => {
    res.json({
        status: embedder ? 'ok' : 'loading',
        model: MODEL,
        dimension: dimension,
        batchSize: MAX_BATCH_SIZE,
        queued: { high: queues.high.length, normal: queues.normal.length },
        workerBusy: running,
        rerankerEnabled: ENABLE_RERANKER,
        rerankerReady: !!(rerankerModel && rerankerTokenizer),
    });
});

// ─── Boot ─────────────────────────────────────────────────────────────────────

Promise.all([loadModel(), loadReranker()])
    .then(() => {
        app.listen(PORT, () => {
            console.log(
                `[embedding-service] Listening on http://localhost:${PORT}` +
                `  model=${MODEL}  reranker=${RERANKER_MODEL}  batchSize=${MAX_BATCH_SIZE}`
            );
        });
    })
    .catch((err) => {
        console.error('[embedding-service] Failed to load models:', err);
        process.exit(1);
    });
