<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Psr\Log\LoggerInterface;

class EmbeddingClient
{
    /**
     * Timeout for bulk reindex calls (many texts, slow is acceptable).
     */
    private const TIMEOUT_REINDEX = 600;

    /**
     * Timeout for live search queries (1 text).
     * Must be short so a slow/restarting embedding service does NOT block
     * PHP-FPM workers — Magento falls back to default search on failure.
     */
    private const TIMEOUT_SEARCH = 3;

    private ?\CurlHandle $curlHandle = null;
    /** @var array{status:string,model:string,dimension:int}|null */
    private ?array $health = null;

    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger
    ) {}

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    private function getCurlHandle(): \CurlHandle
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
            // HTTP Keep-Alive is enabled by default in native curl as long as the handle is reused.
            curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        }
        return $this->curlHandle;
    }

    /**
     * Generate embeddings for an array of texts.
     *
     * @param string[] $texts
     * @param string   $type     'passage' for indexed docs, 'query' for search queries
     * @param int      $timeout  HTTP timeout in seconds
     * @return float[][]
     * @throws \RuntimeException
     */
    public function embed(array $texts, string $type = 'passage', int $timeout = self::TIMEOUT_REINDEX): array
    {
        if (empty($texts)) {
            return [];
        }

        $prefixed = array_map(
            static fn(string $t): string => "$type: " . trim($t),
            $texts
        );

        $payload = json_encode(
            ['texts' => $prefixed, 'priority' => $timeout <= self::TIMEOUT_SEARCH ? 'high' : 'normal'],
            JSON_UNESCAPED_UNICODE
        );
        $url = $this->config->getEmbeddingServiceUrl() . '/embed';

        $ch = $this->getCurlHandle();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers([
            'Content-Type: application/json',
            'Accept: application/json'
        ]));

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            $this->logger->error('[VectorSearch] EmbeddingClient error: ' . $error);
            throw new \RuntimeException('Embedding service unavailable: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('[VectorSearch] Embedding service HTTP ' . $statusCode . ': ' . $body);
            throw new \RuntimeException('Embedding service returned HTTP ' . $statusCode);
        }

        $data = json_decode((string)$body, true);

        if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
            $this->logger->error('[VectorSearch] Invalid embedding response: ' . $body);
            throw new \RuntimeException('Invalid response from embedding service');
        }

        if (count($data['embeddings']) !== count($texts)) {
            throw new \RuntimeException('Embedding service returned an unexpected embedding count');
        }

        foreach ($data['embeddings'] as $embedding) {
            if (!is_array($embedding) || $embedding === []) {
                throw new \RuntimeException('Embedding service returned an empty embedding');
            }
        }

        return $data['embeddings'];
    }

    /**
     * Embed a single query text for live search.
     * Uses a short timeout (TIMEOUT_SEARCH) so a slow or restarting
     * embedding service fails fast and Magento falls back to default search.
     */
    public function embedOne(string $text, string $type = 'query'): array
    {
        $result = $this->embed([$text], $type, self::TIMEOUT_SEARCH);
        return $result[0] ?? [];
    }

    private ?int $dimension = null;
    private ?string $modelName = null;

    /**
     * Fetch the active dimension of the loaded embedding model from the service health check.
     */
    public function getDimension(): int
    {
        if ($this->dimension !== null) {
            return $this->dimension;
        }

        $health = $this->getHealth();
        return $this->dimension = $health['dimension'];
    }

    /**
     * Fetch the active model name from the embedding service health check.
     */
    public function getModelName(): string
    {
        if ($this->modelName !== null) {
            return $this->modelName;
        }

        $health = $this->getHealth();
        return $this->modelName = $health['model'];
    }

    /**
     * A strict readiness check used before any destructive or expensive indexing work.
     *
     * @return array{status:string,model:string,dimension:int}
     */
    public function getHealth(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->health !== null) {
            return $this->health;
        }
        if ($forceRefresh) {
            $this->dimension = null;
            $this->modelName = null;
            $this->health = null;
        }

        $ch = curl_init($this->config->getEmbeddingServiceUrl() . '/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers(['Accept: application/json']));
        $res = curl_exec($ch);
        if ($res === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Embedding service health check failed: ' . $error);
        }
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$res, true);
        $status = is_array($data) ? (string)($data['status'] ?? '') : '';
        $model = is_array($data) ? trim((string)($data['model'] ?? '')) : '';
        $dimension = is_array($data) && is_numeric($data['dimension'] ?? null)
            ? (int)$data['dimension']
            : 0;
        if ($statusCode !== 200 || $status !== 'ok' || $model === '' || $dimension <= 0) {
            throw new \RuntimeException('Embedding service is not ready or returned invalid health metadata');
        }

        return $this->health = [
            'status' => $status,
            'model' => $model,
            'dimension' => $dimension,
        ];
    }

    /**
     * Rerank a set of documents using the Cross-Encoder model.
     *
     * @param string $query
     * @param array[] $documents  Each document is ['id' => int, 'text' => string]
     * @return array[]            List of ['id' => int, 'score' => float] sorted descending by score
     */
    public function rerank(string $query, array $documents): array
    {
        if (empty($documents)) {
            return [];
        }

        $payload = json_encode([
            'query' => $query,
            'documents' => $documents
        ], JSON_UNESCAPED_UNICODE);

        $url = $this->config->getEmbeddingServiceUrl() . '/rerank';

        $ch = $this->getCurlHandle();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $timeoutMs = $this->config->getRerankingTimeoutMs();
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(1000, $timeoutMs));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers([
            'Content-Type: application/json',
            'Accept: application/json'
        ]));

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            $this->logger->error('[VectorSearch] Reranking failed: ' . $error);
            throw new \RuntimeException('Reranking service unavailable: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Reranking service returned HTTP ' . $statusCode);
        }

        $data = json_decode((string)$body, true);
        if (!isset($data['ranked']) || !is_array($data['ranked'])) {
            $this->logger->error('[VectorSearch] Invalid reranking response: ' . $body);
            throw new \RuntimeException('Invalid response from reranking service');
        }

        return $data['ranked'];
    }

    /** @param string[] $headers @return string[] */
    private function headers(array $headers): array
    {
        $apiKey = $this->config->getEmbeddingServiceApiKey();
        if ($apiKey !== '') {
            $headers[] = 'X-Embedding-Api-Key: ' . $apiKey;
        }
        return $headers;
    }
}
