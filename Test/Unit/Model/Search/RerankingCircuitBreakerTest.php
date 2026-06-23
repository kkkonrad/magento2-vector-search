<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\RerankingCircuitBreaker;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RerankingCircuitBreakerTest extends TestCase
{
    public function testOpensAfterConfiguredFailureThreshold(): void
    {
        $cache = $this->createCache();
        $breaker = new RerankingCircuitBreaker($cache, $this->createConfig(2, 60), new NullLogger());

        self::assertTrue($breaker->canAttempt());

        $breaker->recordFailure('timeout');
        self::assertTrue($breaker->canAttempt());

        $breaker->recordFailure('timeout');
        self::assertFalse($breaker->canAttempt());
    }

    public function testSuccessClosesOpenCircuit(): void
    {
        $cache = $this->createCache();
        $breaker = new RerankingCircuitBreaker($cache, $this->createConfig(1, 60), new NullLogger());

        $breaker->recordFailure('timeout');
        self::assertFalse($breaker->canAttempt());

        $breaker->recordSuccess();
        self::assertTrue($breaker->canAttempt());
    }

    private function createConfig(int $threshold, int $cooldownSeconds): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getRerankingCircuitFailureThreshold')->willReturn($threshold);
        $config->method('getRerankingCircuitCooldownSeconds')->willReturn($cooldownSeconds);
        return $config;
    }

    private function createCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /**
             * @var array<string, string>
             */
            private array $storage = [];

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
                $this->storage = [];
                return true;
            }
        };
    }
}
