<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;
use Magento\Framework\App\CacheInterface;

/**
 * Intercepts search service calls to substitute search results early,
 * before Magento's search collection loads or paginates them.
 */
class SearchResultPlugin
{
    private const CACHE_TAG      = 'vectorsearch_embedding';
    private const CACHE_LIFETIME = 3600; // 1 hour

    /**
     * In-process cache: query string → entity_id[]
     * Keyed by "{storeId}:{queryText}" so store switching is safe.
     *
     * @var array<string, int[]>
     */
    private static array $processCache = [];

    public function __construct(
        private readonly EmbeddingClient       $embeddingClient,
        private readonly OpenSearchClient      $openSearchClient,
        private readonly RequestInterface      $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface        $cache,
        private readonly LoggerInterface       $logger,
        private readonly DocumentFactory       $documentFactory,
        private readonly \Magento\Framework\App\Cache\StateInterface $cacheState,
        private readonly \Kkkonrad\VectorSearch\Model\Config $config
    ) {}

    /**
     * Intercept the search execution to swap standard search items with hybrid search results.
     */
    public function afterSearch(
        SearchInterface $subject,
        SearchResultInterface $result,
        SearchCriteriaInterface $searchCriteria
    ): SearchResultInterface {
        if ($searchCriteria->getRequestName() !== 'quick_search_container') {
            return $result;
        }

        $queryText = trim((string)$this->request->getParam('q'));
        if ($queryText === '') {
            return $result;
        }

        try {
            $storeId   = (int)$this->storeManager->getStore()->getId();
            $criteriaFilters = $this->getRequestFilters();
            $entityIds = $this->getEntityIds($queryText, $storeId, $criteriaFilters);

            if (empty($entityIds)) {
                $result->setItems([]);
                $result->setTotalCount(0);
                return $result;
            }

            $totalCount = count($entityIds);
            $pageSize = (int)($searchCriteria->getPageSize() ?: $totalCount);
            $currentPage = max(1, (int)($searchCriteria->getCurrentPage() ?: 1));
            $pageIds = $pageSize > 0
                ? array_slice($entityIds, ($currentPage - 1) * $pageSize, $pageSize)
                : $entityIds;

            // Construct Document objects matching the requested page of hybrid results.
            $documents = [];
            foreach ($pageIds as $id) {
                $document = $this->documentFactory->create();
                $document->setId($id);
                $document->setCustomAttributes([]);
                $documents[] = $document;
            }

            $result->setItems($documents);
            $result->setTotalCount($totalCount);

            $this->logger->debug(
                '[VectorSearch] Injected ' . count($entityIds) . ' hybrid search items into SearchResult for: ' . $queryText
            );
        } catch (\Exception $e) {
            $this->logger->error('[VectorSearch] SearchResultPlugin error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Extracts layered navigation filters from request parameters.
     */
    private function getRequestFilters(): array
    {
        $params = $this->request->getParams();
        $excluded = [
            'q', 'p', 'product_list_order', 'product_list_dir', 
            'product_list_limit', 'product_list_mode', 'id', 'ajax', 'price'
        ];
        $filters = [];
        foreach ($params as $field => $value) {
            if (in_array($field, $excluded, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $filters[] = [
                'field' => $field,
                'value' => $value
            ];
        }
        return $filters;
    }

    /**
     * Retrieve entity IDs from hybrid search, using two-level cache.
     *
     * @return int[]
     */
    private function getEntityIds(string $queryText, int $storeId, array $criteriaFilters): array
    {
        $filterHash = md5(json_encode($criteriaFilters));
        $modelName  = $this->embeddingClient->getModelName();
        $cacheKey   = $storeId . ':' . $queryText . ':' . $filterHash . ':' . $modelName;

        // Level 1: in-process static cache
        if (array_key_exists($cacheKey, self::$processCache)) {
            $this->logger->debug('[VectorSearch] Process-cache hit (SearchResultPlugin) for: ' . $queryText);
            return self::$processCache[$cacheKey];
        }

        $cacheEnabled = $this->cacheState->isEnabled(\Kkkonrad\VectorSearch\Model\Cache\Type::TYPE_IDENTIFIER);

        // Level 2: Magento persistent cache
        $magentoCacheKey = 'vectorsearch_ids_' . md5($cacheKey);
        if ($cacheEnabled) {
            $cached = $this->cache->load($magentoCacheKey);
            if ($cached !== false) {
                $ids = json_decode($cached, true) ?? [];
                $this->logger->debug('[VectorSearch] Magento-cache hit (SearchResultPlugin) for: ' . $queryText);
                return self::$processCache[$cacheKey] = $ids;
            }
        }

        // Level 3: Live query (embed + hybrid search)
        $vector = $this->embeddingClient->embedOne($queryText, 'query');
        if (empty($vector)) {
            return self::$processCache[$cacheKey] = [];
        }

        // Query up to configured limit to support pagination
        $ids = $this->openSearchClient->hybridSearch(
            $queryText,
            $vector,
            $this->config->getOpenSearchSearchLimit(),
            $storeId,
            $criteriaFilters
        );

        self::$processCache[$cacheKey] = $ids;
        if ($cacheEnabled) {
            $this->cache->save(
                json_encode($ids),
                $magentoCacheKey,
                [\Kkkonrad\VectorSearch\Model\Cache\Type::CACHE_TAG],
                self::CACHE_LIFETIME
            );
        }

        return $ids;
    }
}
