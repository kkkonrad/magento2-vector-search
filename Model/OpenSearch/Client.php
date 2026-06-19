<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\OpenSearch;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\AttributeWeightProvider;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;

/**
 * Lightweight OpenSearch HTTP client (no SDK dependency).
 */
class Client
{
    private const PIPELINE_ID = 'kkkonrad-vectorsearch-rrf';

    /** Cached OpenSearch version string, e.g. "2.12.0" */
    private ?string $version = null;

    private ?\CurlHandle $curlHandle = null;

    public function __construct(
        private readonly Config                  $config,
        private readonly LoggerInterface         $logger,
        private readonly AttributeWeightProvider $weightProvider,
        private readonly PolishStemmer           $stemmer,
        private readonly \Kkkonrad\VectorSearch\Model\EmbeddingClient $embeddingClient
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
            curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        }
        return $this->curlHandle;
    }

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
            $technique = $this->config->getOpenSearchCombinationTechnique();
            if ($technique === 'arithmetic_mean') {
                $lexicalWeight = $this->config->getOpenSearchLexicalWeight();
                $knnWeight     = $this->config->getOpenSearchKnnWeight();
                // Ensure weights sum up to exactly 1.0
                $sum = $lexicalWeight + $knnWeight;
                if ($sum > 0.0) {
                    $lexicalWeight /= $sum;
                    $knnWeight     /= $sum;
                } else {
                    $lexicalWeight = 0.7;
                    $knnWeight     = 0.3;
                }

                $normalizationProcessor = [
                    'normalization' => ['technique' => 'min_max'],
                    'combination'   => [
                        'technique'  => 'arithmetic_mean',
                        'parameters' => [
                            'weights' => [
                                round($lexicalWeight, 4),
                                round($knnWeight, 4)
                            ]
                        ]
                    ],
                ];
                $this->logger->info(
                    "[VectorSearch] OpenSearch {$version}: using Arithmetic Mean pipeline with weights "
                    . "[lexical: {$lexicalWeight}, knn: {$knnWeight}]."
                );
            } else {
                // Native RRF
                $normalizationProcessor = [
                    'normalization' => ['technique' => 'rrf'],
                    'combination'   => ['technique' => 'rrf'],
                ];
                $this->logger->info("[VectorSearch] OpenSearch {$version}: using native RRF pipeline.");
            }
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

    public function indexExists(): bool
    {
        $response = $this->request('GET', '/' . $this->indexName(), [], false);
        return isset($response[$this->indexName()]);
    }

    public function ensureIndex(bool $forceRecreate = false): void
    {
        $this->ensurePipeline();

        if (!$forceRecreate && $this->indexExists()) {
            return;
        }

        // Build per-attribute field mappings (attr_color, attr_material, …)
        $attrProperties = [];
        foreach (array_keys($this->weightProvider->getWeightedAttributes()) as $code) {
            $fieldName                  = AttributeWeightProvider::fieldName($code);
            $attrProperties[$fieldName] = ['type' => 'text', 'analyzer' => 'polish_asciifolding'];
            $attrProperties[$fieldName . '_id'] = ['type' => 'keyword'];
        }

        $isHybrid = $this->config->getOpenSearchSearchType() === 'hybrid';
        $engine   = $isHybrid ? 'lucene' : 'nmslib';

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
                'properties' => array_merge(
                    [
                        'entity_id'    => ['type' => 'integer'],
                        'sku'          => ['type' => 'keyword'],
                        'store_id'     => ['type' => 'integer'],
                        'category_ids' => ['type' => 'integer'],
                        'name'         => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                        'description'  => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                        'status'       => ['type' => 'integer'],
                        'visibility'   => ['type' => 'integer'],
                        'embedding'    => [
                            'type'      => 'knn_vector',
                            'dimension' => $this->embeddingClient->getDimension(),
                            'method'    => [
                                'name'       => 'hnsw',
                                'space_type' => 'cosinesimil',
                                'engine'     => $engine,
                                'parameters' => [
                                    'ef_construction' => 128,
                                    'm'               => 16,
                                ],
                            ],
                        ],
                    ],
                    $attrProperties
                ),
            ],
        ];

        $this->request('DELETE', '/' . $this->indexName(), [], false);
        $response = $this->request('PUT', '/' . $this->indexName(), $mapping);

        if (!isset($response['acknowledged']) || $response['acknowledged'] !== true) {
            throw new \RuntimeException('Could not create OpenSearch index: ' . json_encode($response));
        }

        $attrCount = count($attrProperties);
        $this->logger->info(
            '[VectorSearch] Index "' . $this->indexName() . '" created with RRF pipeline '
            . "and {$attrCount} attribute field(s)."
        );
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

    public function hybridSearch(
        string $queryText,
        array $vector,
        int $size = 20,
        int $storeId = 1,
        array $criteriaFilters = []
    ): array {
        $filters = [
            ['term'  => ['status'     => 1]],
            ['term'  => ['store_id'   => $storeId]],
            ['terms' => ['visibility' => [3, 4]]],
        ];

        // Apply criteria filters dynamically
        foreach ($criteriaFilters as $filter) {
            $field = $filter['field'];
            $value = $filter['value'];
            
            if ($field === 'category_ids' || $field === 'category_id') {
                $valArray = is_array($value) ? $value : [$value];
                $filters[] = ['terms' => ['category_ids' => array_map('intval', $valArray)]];
            } else {
                // EAV attributes, mapped to attr_{code}_id
                $mappedField = 'attr_' . $field . '_id';
                if (is_array($value)) {
                    $filters[] = ['terms' => [$mappedField => $value]];
                } else {
                    $filters[] = ['term' => [$mappedField => $value]];
                }
            }
        }

        // Gender intent detection logic
        $lowerQuery = mb_strtolower($queryText);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lowerQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $malePrefixes = [
            'męsk', 'mesk', 'mężczyz', 'mezczyz', 'chłopak', 'chlopak', 'chłopiec', 'chlopiec', 'chłopcy', 'chlopcy',
            'męż', 'meż', 'mez'
        ];
        $maleExact = [
            'boy', 'men', 'man', 'pan', 'pana', 'panów', 'panow', 'panowie', 'facet', 'faceta', 'faceci', 'mąż', 'maz', 'mąz'
        ];

        $femalePrefixes = [
            'kobiet', 'damsk', 'dziewczyn', 'żon', 'zon'
        ];
        $femaleExact = [
            'girl', 'woman', 'women', 'pani', 'panie', 'pań'
        ];

        $isMale = false;
        foreach ($words as $word) {
            if (in_array($word, $maleExact, true)) {
                $isMale = true;
                break;
            }
            foreach ($malePrefixes as $prefix) {
                if (str_starts_with($word, $prefix)) {
                    $isMale = true;
                    break 2;
                }
            }
        }

        $isFemale = false;
        foreach ($words as $word) {
            if (in_array($word, $femaleExact, true)) {
                $isFemale = true;
                break;
            }
            foreach ($femalePrefixes as $prefix) {
                if (str_starts_with($word, $prefix)) {
                    $isFemale = true;
                    break 2;
                }
            }
        }

        if ($isMale && !$isFemale) {
            $filters[] = [
                'bool' => [
                    'must_not' => [
                        'bool' => [
                            'must' => [
                                ['match_phrase' => ['description' => $this->stemmer->stem('Kobiety')]]
                            ],
                            'must_not' => [
                                ['match_phrase' => ['description' => $this->stemmer->stem('Mężczyźni')]]
                            ]
                        ]
                    ]
                ]
            ];
        } elseif ($isFemale && !$isMale) {
            $filters[] = [
                'bool' => [
                    'must_not' => [
                        'bool' => [
                            'must' => [
                                ['match_phrase' => ['description' => $this->stemmer->stem('Mężczyźni')]]
                            ],
                            'must_not' => [
                                ['match_phrase' => ['description' => $this->stemmer->stem('Kobiety')]]
                            ]
                        ]
                    ]
                ]
            ];
        }

        // Build boosted fields list from Magento attribute search_weight settings.
        // description (which contains category names) and sku are added to ensure they are searched.
        $searchableWeights = $this->weightProvider->getSearchableWeights();
        $weightedAttrs     = $this->weightProvider->getWeightedAttributes();
        $fields            = [
            'name^' . ($searchableWeights['name'] ?? 5),
            'description^' . ($searchableWeights['description'] ?? 1),
            'sku^' . ($searchableWeights['sku'] ?? 6)
        ];
        foreach ($weightedAttrs as $code => $weight) {
            $fields[] = AttributeWeightProvider::fieldName($code) . '^' . $weight;
        }

        $stemmedQuery = $this->stemmer->stemText($queryText);

        $shouldClauses = [
            [
                'multi_match' => [
                    'query'    => $stemmedQuery,
                    'fields'   => $fields,
                    'type'     => 'best_fields',
                    'operator' => 'or',
                    'fuzziness' => 'AUTO',
                ],
            ]
        ];

        if ($isMale && !$isFemale) {
            $shouldClauses[] = [
                'multi_match' => [
                    'query'  => $this->stemmer->stem('Mężczyźni'),
                    'fields' => ['description^5', 'attr_gender^10'],
                ]
            ];
        } elseif ($isFemale && !$isMale) {
            $shouldClauses[] = [
                'multi_match' => [
                    'query'  => $this->stemmer->stem('Kobiety'),
                    'fields' => ['description^5', 'attr_gender^10'],
                ]
            ];
        }

        $minSimilarity = $this->config->getOpenSearchMinSimilarity();
        $minSimilarity = max(-1.0, min(1.0, $minSimilarity));
        $minScore = 1.0 / (2.0 - $minSimilarity);

        $searchType = $this->config->getOpenSearchSearchType();
        if ($searchType === 'pure_knn' || $searchType === 'knn') {
            // Pure kNN semantic search (ignores lexical shouldClauses)
            // Since nmslib does not support the 'filter' parameter inside the 'knn' clause,
            // we must use a bool query with post-filtering.
            $query = [
                'size'    => $size,
                '_source' => ['entity_id'],
                'query'   => [
                    'bool' => [
                        'must' => [
                            'knn' => [
                                'embedding' => [
                                    'vector' => $vector,
                                    'k'      => $size,
                                ],
                            ],
                        ],
                        'filter' => $filters,
                    ],
                ],
            ];
        } else {
            // Hybrid search: kNN (semantic) + multi_match with per-attribute weights.
            // RRF pipeline merges both rankings — kNN handles morphology/semantics,
            // multi_match boosts exact attribute matches (color, material, style…).
            $query = [
                'size'    => $size,
                '_source' => ['entity_id'],
                'query'   => [
                    'hybrid' => [
                        'queries' => [
                            [
                                'bool' => [
                                    'should' => $shouldClauses,
                                    'filter' => $filters,
                                ],
                            ],
                            [
                                'knn' => [
                                    'embedding' => [
                                        'vector' => $vector,
                                        'min_score' => $minScore,
                                        'filter' => ['bool' => ['filter' => $filters]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $response = $this->request('POST', '/' . $this->indexName() . '/_search', $query);
        $hits     = $response['hits']['hits'] ?? [];

        if ($searchType !== 'hybrid') {
            $hits = array_filter(
                $hits,
                static fn(array $hit): bool => ($hit['_score'] ?? 0.0) >= $minScore
            );
        }

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
        $ch = $this->getCurlHandle();
        
        // Reset specific options from previous requests
        curl_setopt($ch, CURLOPT_HTTPGET, false);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, null);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $headers = [
            'Content-Type: ' . $contentType,
            'Accept: application/json'
        ];

        $username = $this->config->getOpenSearchUsername();
        $password = $this->config->getOpenSearchPassword();
        if ($username && $password) {
            $headers[] = 'Authorization: Basic ' . base64_encode("{$username}:{$password}");
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            if ($logErrors) {
                $this->logger->error('[VectorSearch] OpenSearch curl error: ' . $error);
            }
            return [];
        }

        $response = json_decode((string)$raw, true) ?? [];

        if ($logErrors && isset($response['error'])) {
            $this->logger->error('[VectorSearch] OpenSearch error: ' . $raw);
        }

        return $response;
    }
}
