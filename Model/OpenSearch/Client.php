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
    private const PIPELINE_ID = 'kkkonrad-vectorsearch-rrf';

    /** Cached OpenSearch version string, e.g. "2.12.0" */
    private ?string $version = null;

    private ?\CurlHandle $curlHandle = null;
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

        $exists = $this->indexExists();
        $dimensionMismatch = false;

        if ($exists) {
            try {
                $response = $this->request('GET', '/' . $this->indexName() . '/_mapping', [], false);
                $props = $response[$this->indexName()]['mappings']['properties'] ?? [];
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
            $body .= json_encode(['index' => ['_id' => $this->documentId((int)$doc['store_id'], (int)$doc['entity_id'])]]) . "\n";
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
            
            if ($field === 'category_ids' || $field === 'category_id' || $field === 'cat') {
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
        $sourceFields = $this->config->isRerankingEnabled() || $this->diagnostics()->isActive()
            ? ['entity_id', 'sku', 'name', 'description', 'category_ids', 'category_names', 'attr_*', 'embedding_text']
            : ['entity_id'];
        $this->diagnostics()->set('opensearch', [
            'search_type' => $searchType,
            'requested_size' => $size,
            'min_similarity' => $minSimilarity,
            'min_score' => $minScore,
            'filters_count' => count($filters),
            'reranking_enabled' => $this->config->isRerankingEnabled(),
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

        if ($this->config->isRerankingEnabled() && !empty($hits)) {
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
                        $matchesAttributeIntents = $this->attributeIntentResolver()->matchesSource(
                            $sourceById[$id] ?? [],
                            $attributeIntents
                        );
                        // Apply the product intent guard, the gap-cut and the absolute config floor.
                        if ($matchesProductIntent && $matchesAttributeIntents && $i <= $cutAfter && $score >= $configMinScore) {
                            $relevantIds[] = $id;
                            $decision = 'relevant';
                        } else {
                            if (!$matchesAttributeIntents && !empty($attributeIntents)) {
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
                                'matches_attributes' => $matchesAttributeIntents,
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

        return $this->request('POST', '/' . $this->indexName() . '/_search', $query, false);
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

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = json_decode((string)$raw, true) ?? [];

        if ($logErrors && ($statusCode >= 400 || isset($response['error']))) {
            $this->logger->error(
                '[VectorSearch] OpenSearch HTTP ' . $statusCode . ' error for ' . $method . ' ' . $url . ': ' . $raw
            );
        }

        return $response;
    }
}
