<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\OpenSearch;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\Config;

/**
 * Lightweight OpenSearch HTTP client (no SDK dependency).
 */
class Client
{
    private const PIPELINE_ID = 'kkkonrad-vectorsearch-rrf';

    /** Cached OpenSearch version string, e.g. "2.12.0" */
    private ?string $version = null;

    public function __construct(
        private readonly Curl            $curl,
        private readonly Config          $config,
        private readonly LoggerInterface $logger
    ) {}

    private function baseUrl(): string
    {
        return 'http://' . $this->config->getOpenSearchHost() . ':' . $this->config->getOpenSearchPort();
    }

    private function indexName(): string
    {
        return $this->config->getOpenSearchIndexName();
    }

    // -------------------------------------------------------------------------
    // Pipeline
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Version detection
    // -------------------------------------------------------------------------

    /**
     * Returns the OpenSearch version string (e.g. "2.12.0").
     * Result is cached for the lifetime of this object.
     */
    public function getVersion(): string
    {
        if ($this->version === null) {
            $response      = $this->request('GET', '/', [], false);
            $this->version = (string)($response['version']['number'] ?? '0.0.0');
        }
        return $this->version;
    }

    /**
     * Returns true when the connected OpenSearch supports native RRF
     * (normalization-processor technique="rrf"), available since 2.16.0.
     */
    public function supportsRrf(): bool
    {
        return version_compare($this->getVersion(), '2.16.0', '>=');
    }

    // -------------------------------------------------------------------------
    // Pipeline
    // -------------------------------------------------------------------------

    public function ensurePipeline(): void
    {
        $version = $this->getVersion();
        $useRrf  = $this->supportsRrf();

        if ($useRrf) {
            // Native RRF — available since OpenSearch 2.16
            $normalizationProcessor = [
                'normalization' => ['technique' => 'rrf'],
                'combination'   => ['technique' => 'rrf'],
            ];
            $this->logger->info("[VectorSearch] OpenSearch {$version}: using native RRF pipeline.");
        } else {
            // Fallback for < 2.16: l2 normalisation + harmonic mean
            // Better than min_max+arithmetic_mean for cosine-similarity embeddings.
            $normalizationProcessor = [
                'normalization' => ['technique' => 'l2'],
                'combination'   => ['technique' => 'harmonic_mean'],
            ];
            $this->logger->info(
                "[VectorSearch] OpenSearch {$version} < 2.16: RRF not supported, "
                . 'using l2+harmonic_mean pipeline.'
            );
        }

        $pipeline = [
            'description'              => 'Hybrid search pipeline for Kkkonrad_VectorSearch',
            'phase_results_processors' => [
                ['normalization-processor' => $normalizationProcessor],
            ],
        ];

        $response = $this->request('PUT', '/_search/pipeline/' . self::PIPELINE_ID, $pipeline);

        if (isset($response['acknowledged']) && $response['acknowledged'] === true) {
            $mode = $useRrf ? 'RRF' : 'l2+harmonic_mean';
            $this->logger->info(
                '[VectorSearch] Search pipeline "' . self::PIPELINE_ID . '" registered (' . $mode . ').'
            );
        } else {
            $this->logger->error('[VectorSearch] Failed to register pipeline: ' . json_encode($response));
            throw new \RuntimeException('Could not register OpenSearch search pipeline.');
        }
    }

    public function pipelineExists(): bool
    {
        $response = $this->request('GET', '/_search/pipeline/' . self::PIPELINE_ID, [], false);
        return isset($response[self::PIPELINE_ID]);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function ensureIndex(): void
    {
        $this->ensurePipeline();

        $mapping = [
            'settings' => [
                'index' => [
                    'knn'                     => true,
                    'number_of_shards'        => 1,
                    'number_of_replicas'      => 0,
                    'search.default_pipeline' => self::PIPELINE_ID,
                ],
                'analysis' => [
                    'analyzer' => [
                        'polish_asciifolding' => [
                            'tokenizer' => 'standard',
                            'filter'    => [
                                'lowercase',
                                'asciifolding',
                            ],
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'entity_id'   => ['type' => 'integer'],
                    'sku'         => ['type' => 'keyword'],
                    'store_id'    => ['type' => 'integer'],
                    'name'        => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                    'description' => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                    'status'      => ['type' => 'integer'],
                    'visibility'  => ['type' => 'integer'],
                    'embedding'   => [
                        'type'      => 'knn_vector',
                        'dimension' => 384,
                        'method'    => [
                            // lucene supports kNN filters natively (required for hybrid/RRF).
                            // nmslib does NOT support the 'filter' parameter and causes a 400
                            // error on every hybrid query, falling back to a slow full scan.
                            'name'       => 'hnsw',
                            'space_type' => 'cosinesimil',
                            'engine'     => 'lucene',
                            'parameters' => [
                                'ef_construction' => 128,
                                'm'               => 16,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->request('DELETE', '/' . $this->indexName(), [], false);
        $response = $this->request('PUT', '/' . $this->indexName(), $mapping);

        if (!isset($response['acknowledged']) || $response['acknowledged'] !== true) {
            throw new \RuntimeException('Could not create OpenSearch index: ' . json_encode($response));
        }

        $this->logger->info('[VectorSearch] Index "' . $this->indexName() . '" created with RRF pipeline.');
    }

    // -------------------------------------------------------------------------
    // Bulk indexing
    // -------------------------------------------------------------------------

    public function bulk(array $docs): void
    {
        if (empty($docs)) {
            return;
        }

        $body = '';
        foreach ($docs as $doc) {
            $body .= json_encode(['index' => ['_id' => (string)$doc['entity_id']]]) . "\n";
            $body .= json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        }

        $response = $this->rawPost('/' . $this->indexName() . '/_bulk', $body, 'application/x-ndjson');

        if (isset($response['errors']) && $response['errors'] === true) {
            $this->logger->error('[VectorSearch] Bulk index errors: ' . json_encode($response['items'] ?? []));
        }
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    public function hybridSearch(string $queryText, array $vector, int $size = 20, int $storeId = 1): array
    {
        $filters = [
            ['term'  => ['status'     => 1]],
            ['term'  => ['store_id'   => $storeId]],
            ['terms' => ['visibility' => [3, 4]]],
        ];

        // Process query text to append wildcards to each term for robust prefix/partial matching
        $words = array_filter(array_map('trim', explode(' ', $queryText)));
        $wildcardedWords = [];
        foreach ($words as $word) {
            if ($word !== '') {
                $wildcardedWords[] = (substr($word, -1) === '*') ? $word : $word . '*';
            }
        }
        $processedQuery = implode(' ', $wildcardedWords);

        $query = [
            'size'    => $size,
            '_source' => ['entity_id'],
            'query'   => [
                'hybrid' => [
                    'queries' => [
                        [
                            'bool' => [
                                'must'   => [[
                                    'simple_query_string' => [
                                        'query'            => $processedQuery,
                                        'fields'           => ['name^3', 'description'],
                                        'default_operator' => 'AND',
                                    ],
                                ]],
                                'filter' => $filters,
                            ],
                        ],
                        [
                            'knn' => [
                                'embedding' => [
                                    'vector' => $vector,
                                    'k'      => $size,
                                    'filter' => ['bool' => ['filter' => $filters]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', '/' . $this->indexName() . '/_search', $query);
        $hits     = $response['hits']['hits'] ?? [];

        return array_map(
            static fn(array $hit): int => (int)$hit['_source']['entity_id'],
            $hits
        );
    }

    public function deleteProduct(int $entityId): void
    {
        $this->request('DELETE', '/' . $this->indexName() . "/_doc/{$entityId}", [], false);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    private function request(string $method, string $path, array $body = [], bool $logErrors = true): array
    {
        $url     = $this->baseUrl() . $path;
        $payload = empty($body) ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        return $this->rawRequest($method, $url, $payload, 'application/json', $logErrors);
    }

    private function rawPost(string $path, string $body, string $contentType): array
    {
        return $this->rawRequest('POST', $this->baseUrl() . $path, $body, $contentType);
    }

    private function rawRequest(
        string $method,
        string $url,
        string $body        = '',
        string $contentType = 'application/json',
        bool   $logErrors   = true
    ): array {
        // Reset all accumulated curl options and headers from previous requests.
        // Magento's Curl stores CURLOPT_CUSTOMREQUEST etc. in $_curlUserOptions and
        // $_headers as instance state that is never cleared between makeRequest() calls.
        // Without this reset, a prior PUT/DELETE would corrupt subsequent POST requests.
        $this->curl->setOptions([]);
        $this->curl->setHeaders([]);

        $this->curl->setTimeout(15);
        $this->curl->addHeader('Content-Type', $contentType);
        $this->curl->addHeader('Accept', 'application/json');

        $username = $this->config->getOpenSearchUsername();
        $password = $this->config->getOpenSearchPassword();
        if ($username && $password) {
            $this->curl->addHeader('Authorization', 'Basic ' . base64_encode("{$username}:{$password}"));
        }

        if ($method === 'POST') {
            $this->curl->post($url, $body);
        } elseif ($method === 'PUT') {
            // Magento Curl::put() does not support a body, so we override via curlUserOptions.
            // setOptions() sets the entire _curlUserOptions map (replaces, not appends).
            $this->curl->setOptions([
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS    => $body,
            ]);
            $this->curl->get($url);
        } elseif ($method === 'DELETE') {
            $this->curl->setOptions([CURLOPT_CUSTOMREQUEST => 'DELETE']);
            $this->curl->get($url);
        } else {
            $this->curl->get($url);
        }

        $raw      = $this->curl->getBody();
        $response = json_decode($raw, true) ?? [];

        if ($logErrors && isset($response['error'])) {
            $this->logger->error('[VectorSearch] OpenSearch error: ' . $raw);
        }

        return $response;
    }
}
