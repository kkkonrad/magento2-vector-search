<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\RequestSearchResultStorage;
use Kkkonrad\VectorSearch\Model\Search\SearchDiagnostics;
use Kkkonrad\VectorSearch\Model\Search\SearchMetricsLogger;
use Kkkonrad\VectorSearch\Model\Search\SearchCandidateFilter;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Intercepts search service calls to substitute search results early,
 * before Magento's search collection loads or paginates them.
 */
class SearchResultPlugin
{
    private bool $shouldFlushDiagnostics = false;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly DocumentFactory $documentFactory,
        private readonly ?VectorSearchService $vectorSearchService = null,
        private readonly ?RequestSearchResultStorage $requestSearchResultStorage = null,
        private readonly ?Config $config = null,
        private readonly ?SearchDiagnostics $searchDiagnostics = null,
        private readonly ?SearchMetricsLogger $searchMetricsLogger = null,
        private readonly ?ResponseInterface $response = null,
        private readonly ?SearchCandidateFilter $searchCandidateFilter = null
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
            $this->startDiagnostics($queryText, $storeId, $criteriaFilters, $requestedLimit, $pageSize, $currentPage);
            $entityIds = $service->getEntityIds($queryText, $storeId, $criteriaFilters, $requestedLimit);
            $entityIds = $this->getSearchCandidateFilter()->filter(
                $entityIds,
                $storeId,
                $this->request->getParams()
            );

            if (empty($entityIds)) {
                $this->markSearchHandled($queryText, $storeId, []);
                $result->setItems([]);
                $result->setTotalCount(0);
                $this->finishDiagnostics($entityIds, []);
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
            $this->finishDiagnostics($entityIds, $pageIds);

            $this->logger->debug(
                '[VectorSearch] Injected ' . count($documents) . '/' . $totalCount
                . ' hybrid search items into SearchResult for: ' . $queryText
            );
        } catch (\Exception $e) {
            if (isset($queryText, $storeId)) {
                $this->getRequestSearchResultStorage()->markFailed($queryText, $storeId);
            }
            $this->logger->error('[VectorSearch] SearchResultPlugin error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param int[] $entityIds
     */
    private function markSearchHandled(string $queryText, int $storeId, array $entityIds): void
    {
        $this->getRequestSearchResultStorage()->mark($queryText, $storeId, $entityIds);
    }


    private function getRequestSearchResultStorage(): RequestSearchResultStorage
    {
        return $this->requestSearchResultStorage
            ?? ObjectManager::getInstance()->get(RequestSearchResultStorage::class);
    }


    /**
     * @param array<int, array{field: string, value: mixed}> $criteriaFilters
     */
    private function startDiagnostics(
        string $queryText,
        int $storeId,
        array $criteriaFilters,
        ?int $requestedLimit,
        int $pageSize,
        int $currentPage
    ): void {
        $config = $this->getConfig();
        $this->shouldFlushDiagnostics = false;
        $configuredToken = $config->getDiagnosticsToken();
        $requestToken = (string)$this->request->getParam('vector_debug_token', '');
        $debugRequested = $config->isDiagnosticsEnabled()
            && $configuredToken !== ''
            && hash_equals($configuredToken, $requestToken)
            && (string)$this->request->getParam('vector_debug', '') === '1';
        $metricsEnabled = $config->isMetricsEnabled();
        if (!$debugRequested && !$metricsEnabled) {
            return;
        }

        $this->shouldFlushDiagnostics = $debugRequested;
        $this->getSearchDiagnostics()->start(
            $queryText,
            $storeId,
            $criteriaFilters,
            $requestedLimit,
            $pageSize,
            $currentPage
        );
    }


    /**
     * @param int[] $entityIds
     * @param int[] $pageIds
     */
    private function finishDiagnostics(array $entityIds, array $pageIds): void
    {
        $diagnostics = $this->getSearchDiagnostics();
        if (!$diagnostics->isActive()) {
            return;
        }

        $diagnostics->set('total_count', count($entityIds));
        $diagnostics->set('top_ids', array_slice($entityIds, 0, 25));
        $diagnostics->set('page_ids', $pageIds);
        if ($this->getConfig()->isMetricsEnabled()) {
            $this->getSearchMetricsLogger()->record($diagnostics->getData());
        }
        if ($this->shouldFlushDiagnostics) {
            $diagnostics->flush($this->logger, $this->getResponse());
        }
    }


    private function getConfig(): Config
    {
        return $this->config
            ?? ObjectManager::getInstance()->get(Config::class);
    }


    private function getSearchDiagnostics(): SearchDiagnostics
    {
        return $this->searchDiagnostics
            ?? ObjectManager::getInstance()->get(SearchDiagnostics::class);
    }


    private function getSearchMetricsLogger(): SearchMetricsLogger
    {
        return $this->searchMetricsLogger
            ?? ObjectManager::getInstance()->get(SearchMetricsLogger::class);
    }


    private function getResponse(): ?ResponseInterface
    {
        return $this->response
            ?? ObjectManager::getInstance()->get(ResponseInterface::class);
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

    private function getSearchCandidateFilter(): SearchCandidateFilter
    {
        return $this->searchCandidateFilter
            ?? ObjectManager::getInstance()->get(SearchCandidateFilter::class);
    }
}
