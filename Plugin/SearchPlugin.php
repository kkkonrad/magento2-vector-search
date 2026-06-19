<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;

/**
 * Intercepts the catalog search collection and replaces result IDs
 * with those from the vector kNN + BM25 hybrid search.
 *
 * afterLoad() is called by Magento ~15–20 times per search-results page
 * (main results, layered navigation counts, widget blocks, etc.).
 * To avoid hitting the embedding service once per call we cache the
 * query vector at two levels:
 *
 *   1. Static (in-memory) cache — instant lookup within the same PHP process.
 *   2. Magento cache (tag: vectorsearch_embedding) — survives across requests
 *      so the same query phrase is only embedded once per cache lifetime.
 */
class SearchPlugin
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

    /**
     * Track processed collections to prevent infinite recursion when calling getItems().
     *
     * @var array<string, bool>
     */
    private static array $processedCollections = [];

    public function __construct(
        private readonly EmbeddingClient       $embeddingClient,
        private readonly OpenSearchClient      $openSearchClient,
        private readonly RequestInterface      $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface        $cache,
        private readonly LoggerInterface       $logger
    ) {}


    /**
     * After the collection loads, if we are on a search results page,
     * re-order / filter by vector search results.
     */
    public function afterLoad(Collection $subject, Collection $result): Collection
    {
        $queryText = trim((string)$this->request->getParam('q'));
        if ($queryText === '') {
            return $result;
        }

        $hash = spl_object_hash($result);
        if (isset(self::$processedCollections[$hash])) {
            return $result;
        }
        self::$processedCollections[$hash] = true;

        try {
            $storeId  = (int)$this->storeManager->getStore()->getId();
            $entityIds = $this->getEntityIds($queryText, $storeId);

            if (empty($entityIds)) {
                return $result;
            }

            // Reorder the loaded collection items to match vector search ranking.
            $positions = array_flip($entityIds); // entity_id => rank
            $items     = $result->getItems();
            $ranked    = [];

            foreach ($items as $item) {
                $id          = (int)$item->getId();
                $ranked[$id] = $positions[$id] ?? PHP_INT_MAX;
            }
            asort($ranked);

            $result->removeAllItems();

            foreach (array_keys($ranked) as $entityId) {
                if (isset($items[$entityId])) {
                    $result->addItem($items[$entityId]);
                }
            }

            $this->logger->debug(
                '[VectorSearch] Reranked ' . count($ranked) . ' results for: ' . $queryText
            );
        } catch (\Exception $e) {
            // Fail gracefully — fall back to default Magento search.
            $this->logger->error('[VectorSearch] Plugin error: ' . $e->getMessage());
        }

        return $result;
    }

    // -------------------------------------------------------------------------

    /**
     * Returns entity IDs from hybrid search, using a two-level cache
     * to avoid redundant embedding calls when afterLoad() fires many times
     * per page for the same query.
     *
     * @return int[]
     */
    private function getEntityIds(string $queryText, int $storeId): array
    {
        $cacheKey = $storeId . ':' . $queryText;

        // Level 1: in-process static cache (same PHP process / request).
        if (array_key_exists($cacheKey, self::$processCache)) {
            $this->logger->debug('[VectorSearch] Process-cache hit for: ' . $queryText);
            return self::$processCache[$cacheKey];
        }

        // Level 2: Magento persistent cache (survives across requests).
        $magentoCacheKey = 'vectorsearch_ids_' . md5($cacheKey);
        $cached          = $this->cache->load($magentoCacheKey);
        if ($cached !== false) {
            $ids = json_decode($cached, true) ?? [];
            $this->logger->debug('[VectorSearch] Magento-cache hit for: ' . $queryText);
            return self::$processCache[$cacheKey] = $ids;
        }

        // Level 3: Live query — embed + hybrid search.
        $vector = $this->embeddingClient->embedOne($queryText, 'query');
        if (empty($vector)) {
            return self::$processCache[$cacheKey] = [];
        }

        $ids = $this->openSearchClient->hybridSearch($queryText, $vector, 50, $storeId);

        // Persist to both caches.
        self::$processCache[$cacheKey] = $ids;
        $this->cache->save(
            json_encode($ids),
            $magentoCacheKey,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $ids;
    }
}
