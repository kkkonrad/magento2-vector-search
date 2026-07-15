<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Plugin;

use Kkkonrad\VectorSearch\Model\Search\RequestSearchResultStorage;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Kkkonrad\VectorSearch\Plugin\SearchResultPlugin;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SearchResultPluginTest extends TestCase
{
    public function testBackendFailurePreservesNativeMagentoResult(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => $name === 'q' ? 'plecak' : $default
        );
        $request->method('getParams')->willReturn(['q' => 'plecak']);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $service = $this->createMock(VectorSearchService::class);
        $service->method('extractRequestFilters')->willReturn([]);
        $service->method('getEntityIds')->willThrowException(new \RuntimeException('backend down'));
        $storage = new RequestSearchResultStorage();

        $plugin = new SearchResultPlugin(
            $request,
            $storeManager,
            new NullLogger(),
            $this->createMock(DocumentFactory::class),
            $service,
            $storage
        );

        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $criteria->method('getRequestName')->willReturn('quick_search_container');
        $criteria->method('getCurrentPage')->willReturn(1);
        $criteria->method('getPageSize')->willReturn(24);
        $result = $this->createMock(SearchResultInterface::class);
        $result->expects(self::never())->method('setItems');
        $result->expects(self::never())->method('setTotalCount');

        self::assertSame(
            $result,
            $plugin->afterSearch($this->createMock(SearchInterface::class), $result, $criteria)
        );
        self::assertTrue($storage->hasFailed('plecak', 1));
    }
}
