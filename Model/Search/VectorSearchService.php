<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Cache\Type as VectorSearchCacheType;
use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

class VectorSearchService
{
    public const INDEX_VERSION_CACHE_KEY = 'vectorsearch_index_version';
    private const CACHE_LIFETIME = 3600;

    /**
     * @var array<string, int[]>
     */
    private static array $idsProcessCache = [];

    /**
     * @var array<string, float[]>
     */
    private static array $vectorProcessCache = [];

    private ?SearchDiagnostics $fallbackDiagnostics = null;
    private ?QueryNormalizer $fallbackQueryNormalizer = null;

    public function __construct(
        private readonly EmbeddingClient $embeddingClient,
        private readonly OpenSearchClient $openSearchClient,
        private readonly CacheInterface $cache,
        private readonly StateInterface $cacheState,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?SearchDiagnostics $searchDiagnostics = null,
        private readonly ?QueryNormalizer $queryNormalizer = null
    ) {}

    /**
     * Extract layered navigation filters from request params.
     *
     * @param array<string, mixed> $params
     * @return array<int, array{field: string, value: mixed}>
     */
    public function extractRequestFilters(array $params): array
    {
        $excluded = [
            'q', 'p', 'product_list_order', 'product_list_dir',
            'product_list_limit', 'product_list_mode', 'id', 'ajax', 'price',
            'form_key', '___store', '___from_store', 'uenc', 'isAjax',
            '__vectorsearch_query', '__vectorsearch_store_id', '__vectorsearch_ids',
            'vector_debug', 'vector_debug_token'
        ];

        $filters = [];
        foreach ($params as $field => $value) {
            if (in_array((string)$field, $excluded, true)) {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $filters[] = [
                'field' => (string)$field,
                'value' => $value
            ];
        }

        return $filters;
    }

    /**
     * @param array<int, array{field: string, value: mixed}> $criteriaFilters
     * @return int[]
     */
    public function getEntityIds(
        string $queryText,
        int $storeId,
        array $criteriaFilters = [],
        ?int $requestedLimit = null
    ): array {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return [];
        }
        $originalQueryText = $queryText;
        $queryText = $this->normalizeQuery($queryText);
        if ($queryText === '') {
            return [];
        }
        if ($queryText !== $originalQueryText) {
            $this->diagnostics()->event('query_normalized', [
                'original' => $originalQueryText,
                'normalized' => $queryText,
            ]);
        }

        $limit = $this->resolveSearchLimit($requestedLimit);
        $filterHash = md5((string)json_encode($criteriaFilters));
        $modelName = $this->embeddingClient->getModelName();
        $indexVersion = $this->getIndexVersion();
        $cacheKey = implode(':', [$storeId, $queryText, $filterHash, $modelName, $limit, $indexVersion]);
        $this->diagnostics()->set('service', [
            'limit' => $limit,
            'filter_hash' => $filterHash,
            'model' => $modelName,
            'index_version' => $indexVersion,
            'cache_enabled' => $this->isCacheEnabled(),
        ]);

        if (array_key_exists($cacheKey, self::$idsProcessCache)) {
            $this->logger->debug('[VectorSearch] Entity ID process-cache hit for: ' . $queryText);
            $this->diagnostics()->event('entity_ids_process_cache_hit', [
                'count' => count(self::$idsProcessCache[$cacheKey]),
            ]);
            return self::$idsProcessCache[$cacheKey];
        }

        $cacheEnabled = $this->isCacheEnabled();
        $magentoCacheKey = 'vectorsearch_ids_' . md5($cacheKey);
        if ($cacheEnabled) {
            $cached = $this->cache->load($magentoCacheKey);
            if ($cached !== false) {
                $ids = $this->decodeIntArray((string)$cached);
                $this->logger->debug('[VectorSearch] Entity ID Magento-cache hit for: ' . $queryText);
                $this->diagnostics()->event('entity_ids_magento_cache_hit', [
                    'count' => count($ids),
                ]);
                return self::$idsProcessCache[$cacheKey] = $ids;
            }
        }

        $this->diagnostics()->event('entity_ids_cache_miss');
        $vector = $this->getQueryVector($queryText, $modelName);
        if (empty($vector)) {
            $this->diagnostics()->event('empty_query_vector');
            return self::$idsProcessCache[$cacheKey] = [];
        }

        $startedAt = microtime(true);
        $ids = $this->openSearchClient->hybridSearch(
            $queryText,
            $vector,
            $limit,
            $storeId,
            $criteriaFilters
        );
        $this->diagnostics()->timing('opensearch_search', $startedAt);
        $this->diagnostics()->event('entity_ids_search_result', [
            'count' => count($ids),
            'top_ids' => array_slice($ids, 0, 25),
        ]);

        self::$idsProcessCache[$cacheKey] = $ids;
        if ($cacheEnabled) {
            $this->cache->save(
                json_encode($ids),
                $magentoCacheKey,
                [VectorSearchCacheType::CACHE_TAG],
                self::CACHE_LIFETIME
            );
        }

        return $ids;
    }

    public function bumpIndexVersion(): string
    {
        $version = sprintf('%.6F', microtime(true));
        $this->cache->save(
            $version,
            self::INDEX_VERSION_CACHE_KEY,
            [VectorSearchCacheType::CACHE_TAG]
        );
        self::$idsProcessCache = [];
        return $version;
    }

    public function getIndexVersion(): string
    {
        $version = $this->cache->load(self::INDEX_VERSION_CACHE_KEY);
        if ($version !== false && $version !== '') {
            return (string)$version;
        }

        return '0';
    }


    private function resolveSearchLimit(?int $requestedLimit): int
    {
        $configuredLimit = max(1, $this->config->getOpenSearchSearchLimit());
        if ($requestedLimit === null || $requestedLimit <= 0) {
            return $configuredLimit;
        }

        $minimum = max(20, $this->config->getRerankingLimit());
        return min($configuredLimit, max($requestedLimit, $minimum));
    }

    /**
     * @return float[]
     */
    private function getQueryVector(string $queryText, string $modelName): array
    {
        $cacheKey = $modelName . ':query:' . $queryText;
        if (array_key_exists($cacheKey, self::$vectorProcessCache)) {
            $this->logger->debug('[VectorSearch] Query vector process-cache hit for: ' . $queryText);
            $this->diagnostics()->event('query_vector_process_cache_hit', [
                'dimensions' => count(self::$vectorProcessCache[$cacheKey]),
            ]);
            return self::$vectorProcessCache[$cacheKey];
        }

        $cacheEnabled = $this->isCacheEnabled();
        $magentoCacheKey = 'vectorsearch_vector_' . md5($cacheKey);
        if ($cacheEnabled) {
            $cached = $this->cache->load($magentoCacheKey);
            if ($cached !== false) {
                $vector = $this->decodeFloatArray((string)$cached);
                if (!empty($vector)) {
                    $this->logger->debug('[VectorSearch] Query vector Magento-cache hit for: ' . $queryText);
                    $this->diagnostics()->event('query_vector_magento_cache_hit', [
                        'dimensions' => count($vector),
                    ]);
                    return self::$vectorProcessCache[$cacheKey] = $vector;
                }
            }
        }

        $startedAt = microtime(true);
        $vector = $this->embeddingClient->embedOne($queryText, 'query');
        $this->diagnostics()->timing('query_embedding', $startedAt);
        $this->diagnostics()->event('query_vector_created', [
            'dimensions' => count($vector),
        ]);
        self::$vectorProcessCache[$cacheKey] = $vector;

        if ($cacheEnabled && !empty($vector)) {
            $this->cache->save(
                json_encode($vector),
                $magentoCacheKey,
                [VectorSearchCacheType::CACHE_TAG],
                self::CACHE_LIFETIME
            );
        }

        return $vector;
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

    private function normalizeQuery(string $queryText): string
    {
        if ($this->queryNormalizer !== null) {
            return $this->queryNormalizer->normalize($queryText);
        }

        try {
            return ObjectManager::getInstance()->get(QueryNormalizer::class)->normalize($queryText);
        } catch (\RuntimeException) {
            return $this->fallbackQueryNormalizer?->normalize($queryText) ?? $queryText;
        }
    }

    private function isCacheEnabled(): bool
    {
        return $this->cacheState->isEnabled(VectorSearchCacheType::TYPE_IDENTIFIER);
    }

    /**
     * @return int[]
     */
    private function decodeIntArray(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_map('intval', $data));
    }

    /**
     * @return float[]
     */
    private function decodeFloatArray(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_map('floatval', $data));
    }
}
