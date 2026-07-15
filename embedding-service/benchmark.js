import { pipeline } from '@xenova/transformers';

const modelCacheDir = process.env.MODEL_CACHE_DIR || './models';

const models = [
    'Xenova/multilingual-e5-small',
    'Xenova/multilingual-e5-base',
    'Xenova/bge-m3'
];

async function run() {
    for (const model of models) {
        try {
            console.log(`Loading ${model}...`);
            const startLoad = Date.now();
            const embedder = await pipeline('feature-extraction', model, {
                cache_dir: modelCacheDir,
                session_options: {
                    intraOpNumThreads: 4,
                    interOpNumThreads: 4,
                }
            });
            console.log(`Loaded in ${((Date.now() - startLoad) / 1000).toFixed(2)}s`);

            // Warmup
            const output = await embedder(['query: test query'], { pooling: 'mean', normalize: true });
            
            // Benchmark
            const startInf = Date.now();
            const iterations = 5;
            for (let i = 0; i < iterations; i++) {
                await embedder(['query: test query in Polish language for search benchmarking'], { pooling: 'mean', normalize: true });
            }
            const infTime = (Date.now() - startInf) / iterations;
            console.log(`Average inference time for ${model}: ${infTime.toFixed(1)}ms. Dimension: ${output.data.length}\n`);
        } catch (err) {
            console.error(`Error benchmarking ${model}:`, err.message);
        }
    }
}

run().catch(console.error);
