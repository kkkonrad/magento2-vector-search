<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class EmbeddingClient
{
    /**
     * Timeout for bulk reindex calls (many texts, slow is acceptable).
     */
    private const TIMEOUT_REINDEX = 30;

    /**
     * Timeout for live search queries (1 text).
     * Must be short so a slow/restarting embedding service does NOT block
     * PHP-FPM workers — Magento falls back to default search on failure.
     */
    private const TIMEOUT_SEARCH = 3;

    public function __construct(
        private readonly Curl            $curl,
        private readonly Config          $config,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate embeddings for an array of texts.
     *
     * @param string[] $texts
     * @param string   $type  'passage' for indexed docs, 'query' for search queries
     * @return float[][]
     * @throws \RuntimeException
     */
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

        $this->curl->setTimeout($timeout);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        try {
            $this->curl->post($url, $payload);
        } catch (\Exception $e) {
            $this->logger->error('[VectorSearch] EmbeddingClient error: ' . $e->getMessage());
            throw new \RuntimeException('Embedding service unavailable: ' . $e->getMessage(), 0, $e);
        }

        $body = $this->curl->getBody();
        $data = json_decode($body, true);

        if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
            $this->logger->error('[VectorSearch] Invalid embedding response: ' . $body);
            throw new \RuntimeException('Invalid response from embedding service');
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
}
