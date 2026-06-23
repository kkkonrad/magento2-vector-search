<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Cache\Type as VectorSearchCacheType;
use Kkkonrad\VectorSearch\Model\Config;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

class RerankingCircuitBreaker
{
    private const FAILURE_COUNT_KEY = 'vectorsearch_reranker_failure_count';
    private const OPEN_UNTIL_KEY = 'vectorsearch_reranker_open_until';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    public function canAttempt(): bool
    {
        $openUntil = (int)($this->cache->load(self::OPEN_UNTIL_KEY) ?: 0);
        if ($openUntil <= time()) {
            return true;
        }

        $this->logger->warning(
            '[VectorSearch] Reranking circuit breaker is open until ' . date('c', $openUntil) . '.'
        );
        return false;
    }

    public function recordSuccess(): void
    {
        $this->cache->remove(self::FAILURE_COUNT_KEY);
        $this->cache->remove(self::OPEN_UNTIL_KEY);
    }

    public function recordFailure(string $reason): void
    {
        $failures = (int)($this->cache->load(self::FAILURE_COUNT_KEY) ?: 0) + 1;
        $threshold = $this->config->getRerankingCircuitFailureThreshold();

        $this->cache->save(
            (string)$failures,
            self::FAILURE_COUNT_KEY,
            [VectorSearchCacheType::CACHE_TAG],
            $this->config->getRerankingCircuitCooldownSeconds()
        );

        if ($failures < $threshold) {
            $this->logger->warning(
                sprintf('[VectorSearch] Reranking failure %d/%d: %s', $failures, $threshold, $reason)
            );
            return;
        }

        $openUntil = time() + $this->config->getRerankingCircuitCooldownSeconds();
        $this->cache->save(
            (string)$openUntil,
            self::OPEN_UNTIL_KEY,
            [VectorSearchCacheType::CACHE_TAG],
            $this->config->getRerankingCircuitCooldownSeconds()
        );
        $this->cache->remove(self::FAILURE_COUNT_KEY);

        $this->logger->error(
            sprintf('[VectorSearch] Reranking circuit breaker opened until %s after %d failures. Last error: %s',
                date('c', $openUntil),
                $failures,
                $reason
            )
        );
    }
}
