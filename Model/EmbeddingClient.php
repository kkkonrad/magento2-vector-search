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
    private const TIMEOUT_REINDEX = 120;

    /**
     * Timeout for live search queries (1 text).
     * Must be short so a slow/restarting embedding service does NOT block
     * PHP-FPM workers — Magento falls back to default search on failure.
     */
    private const TIMEOUT_SEARCH = 3;

    private ?\CurlHandle $curlHandle = null;

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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            $this->logger->error('[VectorSearch] EmbeddingClient error: ' . $error);
            throw new \RuntimeException('Embedding service unavailable: ' . $error);
        }

        $data = json_decode((string)$body, true);

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
