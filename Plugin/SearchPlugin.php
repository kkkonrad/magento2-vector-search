<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Kkkonrad\VectorSearch\Model\Search\RequestSearchResultStorage;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the catalog search collection and reorders loaded products using
 * the shared vector search service.
 */
class SearchPlugin
{
    /**
     * Track processed collections to prevent infinite recursion when calling getItems().
     *
     * @var array<string, bool>
     */
    private static array $processedCollections = [];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ?VectorSearchService $vectorSearchService = null,
        private readonly ?RequestSearchResultStorage $requestSearchResultStorage = null
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
            $storeId = (int)$this->storeManager->getStore()->getId();
            $entityIds = $this->getMarkedEntityIds($queryText, $storeId);
            if ($entityIds === null) {
                $service = $this->getVectorSearchService();
                $criteriaFilters = $service->extractRequestFilters($this->request->getParams());
                $entityIds = $service->getEntityIds($queryText, $storeId, $criteriaFilters);
            }

            if (empty($entityIds)) {
                return $result;
            }

            $positions = array_flip($entityIds);
            $items = $result->getItems();
            $ranked = [];

            foreach ($items as $item) {
                $id = (int)$item->getId();
                $ranked[$id] = $positions[$id] ?? PHP_INT_MAX;
            }
            asort($ranked);

            $result->removeAllItems();
            foreach (array_keys($ranked) as $entityId) {
                if (isset($items[$entityId])) {
                    $result->addItem($items[$entityId]);
                }
            }

            $this->logger->debug('[VectorSearch] Reranked ' . count($ranked) . ' collection items for: ' . $queryText);
        } catch (\Exception $e) {
            $this->logger->error('[VectorSearch] Collection plugin error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @return int[]|null
     */
    private function getMarkedEntityIds(string $queryText, int $storeId): ?array
    {
        $ids = $this->getRequestSearchResultStorage()->get($queryText, $storeId);
        if ($ids === null) {
            return null;
        }

        $this->logger->debug('[VectorSearch] Reusing request-marked IDs for collection ordering: ' . $queryText);
        return $ids;
    }


    private function getRequestSearchResultStorage(): RequestSearchResultStorage
    {
        return $this->requestSearchResultStorage
            ?? ObjectManager::getInstance()->get(RequestSearchResultStorage::class);
    }


    private function getVectorSearchService(): VectorSearchService
    {
        return $this->vectorSearchService
            ?? ObjectManager::getInstance()->get(VectorSearchService::class);
    }
}
