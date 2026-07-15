<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\OpenSearch;

use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\AttributeWeightProvider;
use Kkkonrad\VectorSearch\Model\Search\AttributeIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use Kkkonrad\VectorSearch\Model\Search\ProductIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\RerankingCircuitBreaker;
use Kkkonrad\VectorSearch\Model\Search\SearchDiagnostics;
use Magento\Framework\App\ObjectManager;

/**
 * Lightweight OpenSearch HTTP client (no SDK dependency).
 */
class Client
{
    /** Cached OpenSearch version string, e.g. "2.12.0" */
    private ?string $version = null;

    private ?\CurlHandle $curlHandle = null;
    private ?string $resolvedReadIndexName = null;
    private ?string $rebuildIndexName = null;
    private ?SearchDiagnostics $fallbackDiagnostics = null;
    private ?ProductIntentResolver $fallbackProductIntentResolver = null;
    private ?RerankingCircuitBreaker $fallbackRerankingCircuitBreaker = null;
    private ?AttributeIntentResolver $fallbackAttributeIntentResolver = null;

    public function __construct(
        private readonly Config                  $config,
        private readonly LoggerInterface         $logger,
        private readonly AttributeWeightProvider $weightProvider,
        private readonly PolishStemmer           $stemmer,
        private readonly \Kkkonrad\VectorSearch\Model\EmbeddingClient $embeddingClient,
        private readonly ?SearchDiagnostics      $searchDiagnostics = null,
        private readonly ?ProductIntentResolver  $productIntentResolver = null,
        private readonly ?RerankingCircuitBreaker $rerankingCircuitBreaker = null,
        private readonly ?AttributeIntentResolver $attributeIntentResolver = null
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
        if ($this->rebuildIndexName !== null) {
            return $this->rebuildIndexName;
        }

        return $this->readIndexName();
    }

    /**
     * Resolve the index currently serving searches.
     *
     * During a full rebuild indexName() intentionally points at the new staging
     * index. Reads used to reuse unchanged embeddings must keep targeting the
     * live alias, otherwise every full rebuild recomputes every vector.
     */
    private function readIndexName(): string
    {
        if ($this->resolvedReadIndexName !== null) {
            return $this->resolvedReadIndexName;
        }

        $baseName = $this->baseIndexName();
        $aliasName = $this->activeAliasName();
        $response = $this->request('GET', '/_alias/' . rawurlencode($aliasName), [], false);
        return $this->resolvedReadIndexName = $response !== [] ? $aliasName : $baseName;
    }

    private function baseIndexName(): string
    {
        return $this->config->getOpenSearchIndexName();
    }

    private function activeAliasName(): string
    {
        return $this->baseIndexName() . '_current';
    }

    private function pipelineId(): string
    {
        return 'kkkonrad-vectorsearch-' . substr(sha1($this->baseIndexName()), 0, 12);
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
            $norm = $this->config->getOpenSearchNormalizationTechnique();

            if (in_array($technique, ['arithmetic_mean', 'geometric_mean', 'harmonic_mean'], true)) {
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
                    'normalization' => ['technique' => $norm],
                    'combination'   => [
                        'technique'  => $technique,
                        'parameters' => [
                            'weights' => [
                                round($lexicalWeight, 4),
                                round($knnWeight, 4)
                            ]
                        ]
                    ],
                ];
                $this->logger->info(
                    "[VectorSearch] OpenSearch {$version}: using {$technique} pipeline with {$norm} normalization and weights "
                    . "[lexical: {$lexicalWeight}, knn: {$knnWeight}]."
                );
                $modeDescription = $technique . '+' . $norm;
            } else {
                // Native RRF
                $normalizationProcessor = [
                    'normalization' => ['technique' => 'rrf'],
                    'combination'   => ['technique' => 'rrf'],
                ];
                $this->logger->info("[VectorSearch] OpenSearch {$version}: using native RRF pipeline.");
                $modeDescription = 'RRF';
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
            $modeDescription = 'l2+harmonic_mean';
        }

        $pipeline = [
            'description'              => 'Hybrid search pipeline for Kkkonrad_VectorSearch',
            'phase_results_processors' => [
                ['normalization-processor' => $normalizationProcessor],
            ],
        ];

        $response = $this->request('PUT', '/_search/pipeline/' . $this->pipelineId(), $pipeline);

        if (isset($response['acknowledged']) && $response['acknowledged'] === true) {
            $this->logger->info(
                '[VectorSearch] Search pipeline "' . $this->pipelineId() . '" registered ('
                . $modeDescription . ').'
            );
        } else {
            $this->logger->error('[VectorSearch] Failed to register pipeline: ' . json_encode($response));
            throw new \RuntimeException('Could not register OpenSearch search pipeline.');
        }
    }

    public function pipelineExists(): bool
    {
        $response = $this->request('GET', '/_search/pipeline/' . $this->pipelineId(), [], false);
        return isset($response[$this->pipelineId()]);
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function indexExists(): bool
    {
        $response = $this->request('GET', '/' . $this->indexName(), [], false);
        return $response !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMappingProperties(): array
    {
        $response = $this->request('GET', '/' . $this->indexName() . '/_mapping', [], false);
        $indexData = reset($response);
        return is_array($indexData) ? ($indexData['mappings']['properties'] ?? []) : [];
    }

    /**
     * @return array{total: int|null, samples: string[]}
     */
    public function sampleFieldValues(string $field, int $sampleSize = 5): array
    {
        $query = [
            'size' => max(1, $sampleSize),
            '_source' => [$field],
            'query' => [
                'exists' => [
                    'field' => $field,
                ],
            ],
        ];

        $response = $this->request('POST', '/' . $this->indexName() . '/_search', $query, false);
        $samples = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $value = $hit['_source'][$field] ?? null;
            $text = $this->sampleValueToText($value);
            if ($text !== '') {
                $samples[] = $text;
            }
        }

        return [
            'total' => isset($response['hits']['total']['value'])
                ? (int)$response['hits']['total']['value']
                : null,
            'samples' => array_values(array_unique($samples)),
        ];
    }

    public function countFieldTermMatches(string $field, array $terms): int
    {
        $should = [];
        foreach ($terms as $term) {
            $term = trim((string)$term);
            if ($term === '') {
                continue;
            }

            $should[] = [
                'match' => [
                    $field => [
                        'query' => $term,
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        if (empty($should)) {
            return 0;
        }

        $query = [
            'size' => 0,
            'query' => [
                'bool' => [
                    'filter' => [
                        ['exists' => ['field' => $field]],
                    ],
                    'should' => $should,
                    'minimum_should_match' => 1,
                ],
            ],
        ];

        $response = $this->request('POST', '/' . $this->indexName() . '/_search', $query, false);
        return isset($response['hits']['total']['value']) ? (int)$response['hits']['total']['value'] : 0;
    }

    public function ensureIndex(bool $forceRecreate = false): void
    {
        // A strict preflight prevents creating an index with a guessed vector dimension.
        $this->embeddingClient->getHealth($forceRecreate);
        $this->ensurePipeline();

        if ($forceRecreate) {
            $this->rebuildIndexName = $this->baseIndexName()
                . '_v_' . gmdate('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $this->resolvedReadIndexName = null;
        }

        $exists = $this->indexExists();
        $dimensionMismatch = false;

        if ($exists) {
            try {
                $response = $this->request('GET', '/' . $this->indexName() . '/_mapping', [], false);
                $indexData = reset($response);
                $props = is_array($indexData) ? ($indexData['mappings']['properties'] ?? []) : [];
                $existingDim = $props['embedding']['dimension'] ?? null;
                $activeDim = $this->embeddingClient->getDimension();
                if ($existingDim !== null && (int)$existingDim !== $activeDim) {
                    $dimensionMismatch = true;
                    $this->logger->warning(
                        "[VectorSearch] Dimension mismatch detected (existing: {$existingDim}, active: {$activeDim}). "
                        . "Recreating index."
                    );
                }
            } catch (\Throwable $e) {
                // If checking mappings fails, do not force recreate
            }
        }

        if (!$forceRecreate && $exists && !$dimensionMismatch) {
            $this->request('PUT', '/' . $this->indexName() . '/_settings', [
                'index' => ['search.default_pipeline' => $this->pipelineId()],
            ]);
            return;
        }

        if (!$forceRecreate && $dimensionMismatch) {
            throw new \RuntimeException(
                'Vector dimension changed. Run a full vector_search_products reindex to rebuild safely.'
            );
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
                    'search.default_pipeline' => $this->pipelineId(),
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
                        'entity_id'           => ['type' => 'integer'],
                        'sku'                 => ['type' => 'keyword'],
                        'store_id'            => ['type' => 'integer'],
                        'category_ids'        => ['type' => 'integer'],
                        'category_names'      => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                        'name'                => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                        'description'         => ['type' => 'text', 'analyzer' => 'polish_asciifolding'],
                        'status'              => ['type' => 'integer'],
                        'visibility'          => ['type' => 'integer'],
                        'embedding_text_hash' => ['type' => 'keyword'],
                        'embedding_text'      => ['type' => 'text', 'index' => false],
                        'embedding'           => [
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

    /**
     * Atomically publishes the index built by ensureIndex(true).
     */
    public function activateRebuiltIndex(?int $expectedDocumentCount = null): void
    {
        if ($this->rebuildIndexName === null) {
            throw new \LogicException('No rebuilt index is waiting for activation.');
        }

        $this->request('POST', '/' . $this->rebuildIndexName . '/_refresh');
        $countResponse = $this->request('GET', '/' . $this->rebuildIndexName . '/_count');
        $actualDocumentCount = isset($countResponse['count']) ? (int)$countResponse['count'] : -1;
        if ($actualDocumentCount < 0 || ($expectedDocumentCount !== null && $actualDocumentCount !== $expectedDocumentCount)) {
            throw new \RuntimeException(sprintf(
                'Rebuilt vector index validation failed: expected %s document(s), OpenSearch reports %d.',
                $expectedDocumentCount === null ? 'a valid count' : (string)$expectedDocumentCount,
                $actualDocumentCount
            ));
        }

        $aliasName = $this->activeAliasName();
        $existing = $this->request('GET', '/_alias/' . rawurlencode($aliasName), [], false);
        $actions = [];
        foreach (array_keys($existing) as $indexName) {
            $actions[] = ['remove' => ['index' => $indexName, 'alias' => $aliasName]];
        }
        $actions[] = [
            'add' => [
                'index' => $this->rebuildIndexName,
                'alias' => $aliasName,
                'is_write_index' => true,
            ],
        ];

        $response = $this->request('POST', '/_aliases', ['actions' => $actions]);
        if (($response['acknowledged'] ?? false) !== true) {
            throw new \RuntimeException('OpenSearch did not acknowledge vector index alias activation.');
        }

        $activated = $this->rebuildIndexName;
        $this->rebuildIndexName = null;
        $this->resolvedReadIndexName = $aliasName;
        $this->logger->info('[VectorSearch] Activated rebuilt index ' . $activated . ' as ' . $aliasName . '.');
        $this->cleanupOldRebuildIndices();
    }

    public function abortRebuiltIndex(): void
    {
        if ($this->rebuildIndexName === null) {
            return;
        }
        $failedIndex = $this->rebuildIndexName;
        $this->rebuildIndexName = null;
        try {
            $this->request('DELETE', '/' . rawurlencode($failedIndex), [], false);
        } catch (\Throwable $exception) {
            $this->logger->warning('[VectorSearch] Could not remove failed rebuild index: ' . $exception->getMessage());
        }
        $this->logger->warning('[VectorSearch] Aborted rebuild; active index was not changed.');
    }

    private function cleanupOldRebuildIndices(): void
    {
        try {
            $response = $this->request('GET', '/' . $this->baseIndexName() . '_v_*/_alias', [], false);
            $indices = array_keys($response);
            rsort($indices, SORT_STRING);
            foreach (array_slice($indices, 2) as $indexName) {
                $this->request('DELETE', '/' . rawurlencode($indexName), [], false);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('[VectorSearch] Old vector index cleanup failed: ' . $exception->getMessage());
        }
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
            $body .= json_encode(['index' => ['_id' => $this->documentId((int)$doc['store_id'], (int)$doc['entity_id'])]]) . "\n";
            $body .= json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        }

        $response = $this->rawPost('/' . $this->indexName() . '/_bulk', $body, 'application/x-ndjson');

        if (!array_key_exists('errors', $response)) {
            throw new \RuntimeException('OpenSearch returned an invalid bulk response.');
        }
        if ($response['errors'] === true) {
            $failedItems = array_values(array_filter(
                $response['items'] ?? [],
                static function (array $item): bool {
                    $operation = reset($item);
                    return is_array($operation) && (int)($operation['status'] ?? 500) >= 300;
                }
            ));
            $this->logger->error('[VectorSearch] Bulk index errors: ' . json_encode($failedItems));
            throw new \RuntimeException('OpenSearch bulk indexing failed for ' . count($failedItems) . ' document(s).');
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
        array $criteriaFilters = [],
        bool $allowReranking = true
    ): array {
        $filters = [
            ['term'  => ['status'     => 1]],
            ['term'  => ['store_id'   => $storeId]],
            ['terms' => ['visibility' => [3, 4]]],
        ];
        $filterableAttributes = $this->weightProvider->getWeightedAttributes();

        // Apply criteria filters dynamically
        foreach ($criteriaFilters as $filter) {
            $field = (string)($filter['field'] ?? '');
            $value = $filter['value'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                continue;
            }
            
            if ($field === 'category_ids' || $field === 'category_id' || $field === 'cat') {
                $valArray = is_array($value) ? $value : [$value];
                $filters[] = ['terms' => ['category_ids' => array_map('intval', $valArray)]];
            } else {
                if (!isset($filterableAttributes[$field])) {
                    continue;
                }
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

        $femaleQuery = $this->stemmer->stemText('Kobiety Damski');
        $maleQuery   = $this->stemmer->stemText('Mężczyźni Męski');

        if ($isMale && !$isFemale) {
            $filters[] = [
                'bool' => [
                    'must_not' => [
                        'bool' => [
                            'must' => [
                                [
                                    'multi_match' => [
                                        'query' => $femaleQuery,
                                        'fields' => ['name', 'description', 'attr_gender']
                                    ]
                                ]
                            ],
                            'must_not' => [
                                [
                                    'multi_match' => [
                                        'query' => $maleQuery,
                                        'fields' => ['name', 'description', 'attr_gender']
                                    ]
                                ]
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
                                [
                                    'multi_match' => [
                                        'query' => $maleQuery,
                                        'fields' => ['name', 'description', 'attr_gender']
                                    ]
                                ]
                            ],
                            'must_not' => [
                                [
                                    'multi_match' => [
                                        'query' => $femaleQuery,
                                        'fields' => ['name', 'description', 'attr_gender']
                                    ]
                                ]
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
                    'query'  => $maleQuery,
                    'fields' => ['attr_gender^10'],
                ]
            ];
        } elseif ($isFemale && !$isMale) {
            $shouldClauses[] = [
                'multi_match' => [
                    'query'  => $femaleQuery,
                    'fields' => ['attr_gender^10'],
                ]
            ];
        }

        $minSimilarity = $this->config->getOpenSearchMinSimilarity();
        $minSimilarity = max(-1.0, min(1.0, $minSimilarity));
        $minScore = 1.0 / (2.0 - $minSimilarity);

        $searchType = $this->config->getOpenSearchSearchType();
        $rerankingEnabled = $allowReranking && $this->config->isRerankingEnabled();
        $sourceFields = $rerankingEnabled || $this->diagnostics()->isActive()
            ? ['entity_id', 'sku', 'name', 'description', 'category_ids', 'category_names', 'attr_*', 'embedding_text']
            : ['entity_id'];
        $this->diagnostics()->set('opensearch', [
            'search_type' => $searchType,
            'requested_size' => $size,
            'min_similarity' => $minSimilarity,
            'min_score' => $minScore,
            'filters_count' => count($filters),
            'reranking_enabled' => $rerankingEnabled,
            'reranking_limit' => $this->config->getRerankingLimit(),
        ]);

        if ($searchType === 'pure_knn' || $searchType === 'knn') {
            // Pure kNN semantic search (ignores lexical shouldClauses).
            // Overfetch before bool filtering so store/category/attribute filters do not leave
            // sparse pages when the nearest global vectors are filtered out afterwards.
            $knnCandidateSize = max($size * 5, 100);
            $query = [
                'size'    => $size,
                '_source' => $sourceFields,
                'query'   => [
                    'bool' => [
                        'must' => [
                            'knn' => [
                                'embedding' => [
                                    'vector' => $vector,
                                    'k'      => $knnCandidateSize,
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
                '_source' => $sourceFields,
                'query'   => [
                    'hybrid' => [
                        'queries' => [
                            [
                                'bool' => [
                                    'should' => $shouldClauses,
                                    'minimum_should_match' => 1,
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
        $this->diagnostics()->event('opensearch_raw_hits', [
            'total' => $response['hits']['total']['value'] ?? null,
            'returned' => count($hits),
            'top' => $this->summarizeHits($hits, 15),
        ]);

        if ($searchType !== 'hybrid') {
            $hits = array_filter(
                $hits,
                static fn(array $hit): bool => ($hit['_score'] ?? 0.0) >= $minScore
            );
        }

        $entityIds = array_map(
            static fn(array $hit): int => (int)$hit['_source']['entity_id'],
            $hits
        );

        if ($rerankingEnabled && !empty($hits)) {
            $rerankLimit = $this->config->getRerankingLimit();
            $candidatesToRerank = array_slice($hits, 0, $rerankLimit);
            $remainingHits = array_slice($hits, $rerankLimit);
            $productIntent = $this->productIntentResolver()->resolve($queryText);
            $productIntentTerms = $productIntent['terms'];
            $attributeIntents = $this->attributeIntentResolver()->resolve($queryText);
            $this->diagnostics()->event('product_intent_detected', [
                'group' => $productIntent['name'],
                'terms' => $productIntentTerms,
            ]);
            $this->diagnostics()->event('attribute_intents_detected', [
                'intents' => $attributeIntents,
            ]);

            $documents = [];
            $documentTextsById = [];
            $sourceById = [];
            foreach ($candidatesToRerank as $hit) {
                $src = $hit['_source'] ?? [];
                $id = (int)($src['entity_id'] ?? 0);
                $text = mb_substr((string)($src['embedding_text'] ?? ''), 0, 1000);
                $documents[] = [
                    'id' => $id,
                    'text' => $text
                ];
                $documentTextsById[$id] = $text;
                $sourceById[$id] = $src;
            }

            if (!$this->rerankingCircuitBreaker()->canAttempt()) {
                $this->diagnostics()->event('reranking_circuit_open');
                $this->logger->warning('[VectorSearch] Reranking skipped because circuit breaker is open.');
                return $entityIds;
            }

            try {
                $startedAt = microtime(true);
                $ranked = $this->embeddingClient->rerank($queryText, $documents);
                $this->diagnostics()->timing('reranker', $startedAt);
                if (!empty($ranked)) {
                    $this->rerankingCircuitBreaker()->recordSuccess();
                    // Find the largest relative score drop between consecutive reranker results.
                    // This approach works for both positive-score models (ms-marco-TinyBERT) and
                    // negative-score models (bge-reranker-base) where a percentage of max is broken.
                    //
                    // Algorithm:
                    //  1. Walk through ranked results (already ordered best→worst).
                    //  2. Compute the absolute gap between each consecutive pair.
                    //  3. If the largest gap exceeds minGapFraction × total score range, cut there.
                    //  4. Everything after the cut is demoted to the tail of the result list.
                    //
                    // Config "min_score" still acts as an absolute floor (useful when you know the
                    // model's typical relevance range, e.g. set to 0 to always exclude negatives).
                    $configMinScore = (float)($this->config->getRerankingMinScore() ?? -999.0);
                    $minGapFraction = 0.35; // cut only when the drop is ≥35% of total score range

                    $scores = array_column($ranked, 'score');
                    $topScore = count($scores) > 0 ? max($scores) : 0.0;
                    $botScore = count($scores) > 0 ? min($scores) : 0.0;
                    $scoreRange = $topScore - $botScore; // always ≥ 0

                    // Find the cut-point index (after which we demote)
                    $cutAfter = count($ranked) - 1; // default: keep all
                    if ($scoreRange > 0.0001) {
                        $minGapAbs = $scoreRange * $minGapFraction;
                        $prevScore = $topScore;
                        for ($i = 0; $i < count($scores); $i++) {
                            $gap = $prevScore - $scores[$i];
                            if ($gap >= $minGapAbs) {
                                $cutAfter = $i - 1;
                                break;
                            }
                            $prevScore = $scores[$i];
                        }
                    }

                    $relevantIds = [];
                    $softAttributeMismatchedIds = [];
                    $poorIds = [];
                    $attributeMismatchedPoorIds = [];
                    $rerankedDiagnostics = [];
                    foreach ($ranked as $i => $item) {
                        $id = (int)($item['id'] ?? 0);
                        $score = (float)($item['score'] ?? 0.0);
                        $matchesProductIntent = $this->productIntentResolver()->matchesSource(
                            $sourceById[$id] ?? ['embedding_text' => $documentTextsById[$id] ?? ''],
                            $productIntentTerms
                        );
                        $attributeMatchDetails = $this->attributeIntentResolver()->matchDetails(
                            $sourceById[$id] ?? [],
                            $attributeIntents
                        );
                        $matchesStrictAttributeIntents = true;
                        $hasSoftAttributeMismatch = false;
                        foreach ($attributeMatchDetails as $attributeMatchDetail) {
                            if (!empty($attributeMatchDetail['matched'])) {
                                continue;
                            }

                            if (($attributeMatchDetail['mode'] ?? 'strict') === 'soft') {
                                $hasSoftAttributeMismatch = true;
                            } else {
                                $matchesStrictAttributeIntents = false;
                            }
                        }
                        // Apply the product intent guard, the gap-cut and the absolute config floor.
                        if ($matchesProductIntent && $matchesStrictAttributeIntents && $i <= $cutAfter && $score >= $configMinScore) {
                            if ($hasSoftAttributeMismatch) {
                                $softAttributeMismatchedIds[] = $id;
                                $decision = 'soft_attribute_demoted';
                            } else {
                                $relevantIds[] = $id;
                                $decision = 'relevant';
                            }
                        } else {
                            if (!$matchesStrictAttributeIntents && !empty($attributeIntents)) {
                                $attributeMismatchedPoorIds[] = $id;
                            } else {
                                $poorIds[] = $id;
                            }
                            $decision = 'demoted';
                        }

                        if (count($rerankedDiagnostics) < 25) {
                            $rerankedDiagnostics[] = [
                                'id' => $id,
                                'score' => $score,
                                'matches_intent' => $matchesProductIntent,
                                'matches_attributes' => $matchesStrictAttributeIntents && !$hasSoftAttributeMismatch,
                                'matches_strict_attributes' => $matchesStrictAttributeIntents,
                                'has_soft_attribute_mismatch' => $hasSoftAttributeMismatch,
                                'attribute_details' => $attributeMatchDetails,
                                'decision' => $decision,
                            ];
                        }
                    }

                    $remainingRelevantIds = [];
                    $remainingPoorIds = [];
                    foreach ($remainingHits as $hit) {
                        $src = $hit['_source'] ?? [];
                        $id = (int)($src['entity_id'] ?? 0);
                        if (
                            $this->productIntentResolver()->matchesSource($src, $productIntentTerms)
                            && $this->attributeIntentResolver()->matchesSource($src, $attributeIntents)
                        ) {
                            $remainingRelevantIds[] = $id;
                        } else {
                            $remainingPoorIds[] = $id;
                        }
                    }

                    $finalIds = array_values(array_unique(array_merge(
                        $relevantIds,
                        $softAttributeMismatchedIds,
                        $remainingRelevantIds,
                        $poorIds,
                        $attributeMismatchedPoorIds,
                        $remainingPoorIds
                    )));
                    $this->diagnostics()->event('reranking_result', [
                        'score_range' => $scoreRange,
                        'cut_after' => $cutAfter,
                        'config_min_score' => $configMinScore,
                        'reranked' => $rerankedDiagnostics,
                        'relevant_count' => count($relevantIds),
                        'soft_attribute_demoted_count' => count($softAttributeMismatchedIds),
                        'demoted_count' => count($poorIds),
                        'attribute_mismatched_demoted_count' => count($attributeMismatchedPoorIds),
                        'remaining_relevant_count' => count($remainingRelevantIds),
                        'remaining_demoted_count' => count($remainingPoorIds),
                        'final_top_ids' => array_slice($finalIds, 0, 25),
                    ]);

                    // Order: good reranked → matching OpenSearch hits → demoted reranked → non-matching hits.
                    return $finalIds;
                }
            } catch (\Throwable $e) {
                $this->rerankingCircuitBreaker()->recordFailure($e->getMessage());
                $this->diagnostics()->event('reranking_failed', [
                    'message' => $e->getMessage(),
                ]);
                $this->logger->error('[VectorSearch] Reranking failed during search, falling back to OpenSearch order: ' . $e->getMessage());
            }
        }

        $this->diagnostics()->event('opensearch_final_without_reranking', [
            'top_ids' => array_slice($entityIds, 0, 25),
        ]);
        return $entityIds;
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     * @return array<int, array<string, mixed>>
     */
    private function summarizeHits(array $hits, int $limit): array
    {
        $summary = [];
        foreach (array_slice($hits, 0, $limit) as $hit) {
            $src = $hit['_source'] ?? [];
            $summary[] = [
                'id' => (int)($src['entity_id'] ?? 0),
                'score' => isset($hit['_score']) ? (float)$hit['_score'] : null,
                'sku' => (string)($src['sku'] ?? ''),
                'name' => (string)($src['name'] ?? ''),
            ];
        }

        return $summary;
    }

    private function diagnostics(): SearchDiagnostics
    {
        if ($this->searchDiagnostics !== null) {
            return $this->searchDiagnostics;
        }

        try {
            return ObjectManager::getInstance()->get(SearchDiagnostics::class);
        } catch (\RuntimeException) {
            return $this->fallbackDiagnostics ??= new SearchDiagnostics();
        }
    }


    private function productIntentResolver(): ProductIntentResolver
    {
        if ($this->productIntentResolver !== null) {
            return $this->productIntentResolver;
        }

        try {
            return ObjectManager::getInstance()->get(ProductIntentResolver::class);
        } catch (\RuntimeException) {
            return $this->fallbackProductIntentResolver ??= new ProductIntentResolver($this->config, $this->stemmer);
        }
    }


    private function rerankingCircuitBreaker(): RerankingCircuitBreaker
    {
        if ($this->rerankingCircuitBreaker !== null) {
            return $this->rerankingCircuitBreaker;
        }

        return $this->fallbackRerankingCircuitBreaker
            ??= ObjectManager::getInstance()->get(RerankingCircuitBreaker::class);
    }


    private function attributeIntentResolver(): AttributeIntentResolver
    {
        if ($this->attributeIntentResolver !== null) {
            return $this->attributeIntentResolver;
        }

        try {
            return ObjectManager::getInstance()->get(AttributeIntentResolver::class);
        } catch (\RuntimeException) {
            return $this->fallbackAttributeIntentResolver ??= new AttributeIntentResolver($this->config, $this->stemmer);
        }
    }


    private function documentId(int $storeId, int $entityId): string
    {
        return $storeId . '_' . $entityId;
    }


    public function deleteProduct(int $entityId, ?int $storeId = null): void
    {
        if ($storeId !== null) {
            $this->request('DELETE', '/' . $this->indexName() . '/_doc/' . $this->documentId($storeId, $entityId), [], false);
            return;
        }

        $this->request('POST', '/' . $this->indexName() . '/_delete_by_query', [
            'query' => [
                'term' => ['entity_id' => $entityId]
            ]
        ], false);
    }

    /**
     * Retrieve existing documents from OpenSearch to fetch current hashes and embeddings.
     *
     * @param int[] $entityIds
     * @return array
     */
    public function getDocsForHashCheck(array $entityIds, ?int $storeId = null): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $filters = [
            ['terms' => ['entity_id' => array_map('intval', $entityIds)]]
        ];
        if ($storeId !== null) {
            $filters[] = ['term' => ['store_id' => $storeId]];
        }

        $query = [
            'size' => count($entityIds),
            '_source' => ['entity_id', 'store_id', 'embedding_text_hash', 'embedding'],
            'query' => [
                'bool' => [
                    'filter' => $filters
                ]
            ]
        ];

        return $this->request('POST', '/' . $this->readIndexName() . '/_search', $query, false);
    }

    /**
     * Delete from OpenSearch any products for this store_id that were NOT processed (i.e. deleted/disabled in Magento)
     *
     * @param int $storeId
     * @param int[] $processedIds
     * @return void
     */
    public function deleteOrphanedProducts(int $storeId, array $processedIds): void
    {
        if (empty($processedIds)) {
            // Delete everything for this store if nothing was processed
            $query = [
                'query' => [
                    'term' => ['store_id' => $storeId]
                ]
            ];
        } else {
            $query = [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['store_id' => $storeId]]
                        ],
                        'must_not' => [
                            ['terms' => ['entity_id' => array_map('intval', $processedIds)]]
                        ]
                    ]
                ]
            ];
        }

        $this->request('POST', '/' . $this->indexName() . '/_delete_by_query', $query, false);
    }



    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    protected function request(string $method, string $path, array $body = [], bool $logErrors = true): array
    {
        $url     = $this->baseUrl() . $path;
        $payload = empty($body) ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        return $this->rawRequest($method, $url, $payload, 'application/json', $logErrors);
    }

    private function rawPost(string $path, string $body, string $contentType): array
    {
        return $this->rawRequest('POST', $this->baseUrl() . $path, $body, $contentType);
    }

    /**
     * @param mixed $value
     */
    private function sampleValueToText($value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map(fn($item): string => $this->sampleValueToText($item), $value)));
        }

        return trim((string)$value);
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
            throw new \RuntimeException('OpenSearch unavailable: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = json_decode((string)$raw, true);
        if ($raw !== '' && !is_array($response)) {
            throw new \RuntimeException('OpenSearch returned invalid JSON for ' . $method . ' ' . $url);
        }
        $response = $response ?? [];

        if ($statusCode >= 400 || isset($response['error'])) {
            if ($logErrors) {
                $this->logger->error(
                    '[VectorSearch] OpenSearch HTTP ' . $statusCode . ' error for ' . $method . ' ' . $url . ': ' . $raw
                );
            }
            if (!$logErrors && $statusCode === 404) {
                return [];
            }
            throw new \RuntimeException('OpenSearch request failed with HTTP ' . $statusCode);
        }

        return $response;
    }
}
