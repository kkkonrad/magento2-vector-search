<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Intercepts search service calls to substitute search results early,
 * before Magento's search collection loads or paginates them.
 */
class SearchResultPlugin
{
    private const REQUEST_QUERY_PARAM = '__vectorsearch_query';
    private const REQUEST_STORE_ID_PARAM = '__vectorsearch_store_id';
    private const REQUEST_IDS_PARAM = '__vectorsearch_ids';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly DocumentFactory $documentFactory,
        private readonly ?VectorSearchService $vectorSearchService = null
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
            $service = $this->getVectorSearchService();
            $storeId = (int)$this->storeManager->getStore()->getId();
            $criteriaFilters = $service->extractRequestFilters($this->request->getParams());
            $pageSize = (int)($searchCriteria->getPageSize() ?: 0);
            $currentPage = max(1, (int)($searchCriteria->getCurrentPage() ?: 1));
            $requestedLimit = $this->calculateRequestedLimit($pageSize, $currentPage);
            $entityIds = $service->getEntityIds($queryText, $storeId, $criteriaFilters, $requestedLimit);

            if (empty($entityIds)) {
                $this->markSearchHandled($queryText, $storeId, []);
                $result->setItems([]);
                $result->setTotalCount(0);
                return $result;
            }

            $this->markSearchHandled($queryText, $storeId, $entityIds);
            $totalCount = count($entityIds);
            $pageSize = $pageSize > 0 ? $pageSize : $totalCount;
            $pageIds = $pageSize > 0
                ? array_slice($entityIds, ($currentPage - 1) * $pageSize, $pageSize)
                : $entityIds;

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
                '[VectorSearch] Injected ' . count($documents) . '/' . $totalCount
                . ' hybrid search items into SearchResult for: ' . $queryText
            );
        } catch (\Exception $e) {
            $this->logger->error('[VectorSearch] SearchResultPlugin error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param int[] $entityIds
     */
    private function markSearchHandled(string $queryText, int $storeId, array $entityIds): void
    {
        if (!method_exists($this->request, 'setParam')) {
            return;
        }

        $this->request->setParam(self::REQUEST_QUERY_PARAM, $queryText);
        $this->request->setParam(self::REQUEST_STORE_ID_PARAM, $storeId);
        $this->request->setParam(self::REQUEST_IDS_PARAM, json_encode(array_values($entityIds)));
    }


    private function calculateRequestedLimit(int $pageSize, int $currentPage): ?int
    {
        if ($pageSize <= 0) {
            return null;
        }

        $neededForPage = $pageSize * max(1, $currentPage);
        $buffer = max($pageSize * 2, 60);
        return $neededForPage + $buffer;
    }


    private function getVectorSearchService(): VectorSearchService
    {
        return $this->vectorSearchService
            ?? ObjectManager::getInstance()->get(VectorSearchService::class);
    }
}
