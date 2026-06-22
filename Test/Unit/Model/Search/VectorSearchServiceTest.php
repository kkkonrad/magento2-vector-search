<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Cache\Type as VectorSearchCacheType;
use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VectorSearchServiceTest extends TestCase
{
    public function testExtractRequestFiltersIgnoresTechnicalVectorSearchParams(): void
    {
        $service = $this->createService();

        $filters = $service->extractRequestFilters([
            'q' => 'spodnie',
            'color' => '50',
            'price' => '10-20',
            '__vectorsearch_query' => 'spodnie',
            '__vectorsearch_store_id' => '1',
            '__vectorsearch_ids' => '[1,2,3]',
        ]);

        self::assertSame([
            ['field' => 'color', 'value' => '50'],
        ], $filters);
    }

    public function testQueryVectorIsReusedAcrossDifferentFilters(): void
    {
        $embeddingClient = $this->createMock(EmbeddingClient::class);
        $embeddingClient->method('getModelName')->willReturn('model-a');
        $embeddingClient->expects(self::once())
            ->method('embedOne')
            ->with('blue pants', 'query')
            ->willReturn([0.1, 0.2, 0.3]);

        $searchCalls = 0;
        $openSearchClient = $this->createMock(OpenSearchClient::class);
        $openSearchClient->expects(self::exactly(2))
            ->method('hybridSearch')
            ->willReturnCallback(function (string $query, array $vector, int $limit, int $storeId, array $filters) use (&$searchCalls): array {
                $searchCalls++;
                self::assertSame('blue pants', $query);
                self::assertSame([0.1, 0.2, 0.3], $vector);
                self::assertSame(100, $limit);
                self::assertSame(1, $storeId);

                if ($searchCalls === 1) {
                    self::assertSame([['field' => 'color', 'value' => '50']], $filters);
                    return [10, 11];
                }

                self::assertSame([['field' => 'size', 'value' => 'M']], $filters);
                return [20, 21];
            });

        $service = $this->createService(
            embeddingClient: $embeddingClient,
            openSearchClient: $openSearchClient,
            cacheState: new FakeCacheState(false)
        );

        self::assertSame([10, 11], $service->getEntityIds('blue pants', 1, [['field' => 'color', 'value' => '50']]));
        self::assertSame([20, 21], $service->getEntityIds('blue pants', 1, [['field' => 'size', 'value' => 'M']]));
    }

    public function testBumpingIndexVersionInvalidatesCachedEntityIds(): void
    {
        $cache = new FakeCache();

        $embeddingClient = $this->createMock(EmbeddingClient::class);
        $embeddingClient->method('getModelName')->willReturn('model-b');
        $embeddingClient->expects(self::once())
            ->method('embedOne')
            ->with('women watch', 'query')
            ->willReturn([0.9, 0.8]);

        $searchCalls = 0;
        $openSearchClient = $this->createMock(OpenSearchClient::class);
        $openSearchClient->expects(self::exactly(2))
            ->method('hybridSearch')
            ->willReturnCallback(function () use (&$searchCalls): array {
                $searchCalls++;
                return $searchCalls === 1 ? [41, 42] : [44, 41];
            });

        $service = $this->createService(
            embeddingClient: $embeddingClient,
            openSearchClient: $openSearchClient,
            cache: $cache,
            cacheState: new FakeCacheState(true)
        );

        self::assertSame('0', $service->getIndexVersion());
        self::assertSame([41, 42], $service->getEntityIds('women watch', 1, []));
        self::assertSame([41, 42], $service->getEntityIds('women watch', 1, []));

        $newVersion = $service->bumpIndexVersion();

        self::assertNotSame('0', $newVersion);
        self::assertSame($newVersion, $service->getIndexVersion());
        self::assertSame([44, 41], $service->getEntityIds('women watch', 1, []));
    }

    private function createService(
        ?EmbeddingClient $embeddingClient = null,
        ?OpenSearchClient $openSearchClient = null,
        ?CacheInterface $cache = null,
        ?StateInterface $cacheState = null,
        ?Config $config = null
    ): VectorSearchService {
        $config ??= $this->createConfigMock();
        $embeddingClient ??= $this->createEmbeddingClientMock();
        $openSearchClient ??= $this->createOpenSearchClientMock();
        $cache ??= new FakeCache();
        $cacheState ??= new FakeCacheState(true);

        return new VectorSearchService(
            $embeddingClient,
            $openSearchClient,
            $cache,
            $cacheState,
            $config,
            new NullLogger()
        );
    }

    private function createConfigMock(): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getOpenSearchSearchLimit')->willReturn(100);
        $config->method('getRerankingLimit')->willReturn(20);
        return $config;
    }

    private function createEmbeddingClientMock(): EmbeddingClient&MockObject
    {
        $embeddingClient = $this->createMock(EmbeddingClient::class);
        $embeddingClient->method('getModelName')->willReturn('default-model');
        $embeddingClient->method('embedOne')->willReturn([0.1]);
        return $embeddingClient;
    }

    private function createOpenSearchClientMock(): OpenSearchClient&MockObject
    {
        $openSearchClient = $this->createMock(OpenSearchClient::class);
        $openSearchClient->method('hybridSearch')->willReturn([]);
        return $openSearchClient;
    }
}

class FakeCache implements CacheInterface
{
    /**
     * @var array<string, string>
     */
    public array $storage = [];

    public function getFrontend()
    {
        return null;
    }

    public function load($identifier)
    {
        return $this->storage[(string)$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->storage[(string)$identifier] = (string)$data;
        return true;
    }

    public function remove($identifier)
    {
        unset($this->storage[(string)$identifier]);
        return true;
    }

    public function clean($tags = [])
    {
        if (in_array(VectorSearchCacheType::CACHE_TAG, $tags, true)) {
            $this->storage = [];
        }
        return true;
    }
}

class FakeCacheState implements StateInterface
{
    public function __construct(private bool $enabled) {}

    public function isEnabled($cacheType)
    {
        return $this->enabled;
    }

    public function setEnabled($cacheType, $isEnabled)
    {
        $this->enabled = (bool)$isEnabled;
    }

    public function persist()
    {
    }
}
