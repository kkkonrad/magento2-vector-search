<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

/**
 * Keeps vector search IDs available to later plugins during the same request
 * without adding technical parameters to generated frontend URLs.
 */
class RequestSearchResultStorage
{
    private ?string $queryText = null;
    private ?int $storeId = null;

    /**
     * @var int[]
     */
    private array $entityIds = [];
    private bool $failed = false;

    /**
     * @param int[] $entityIds
     */
    public function mark(string $queryText, int $storeId, array $entityIds): void
    {
        $this->queryText = $queryText;
        $this->storeId = $storeId;
        $this->entityIds = array_values(array_map('intval', $entityIds));
        $this->failed = false;
    }

    public function markFailed(string $queryText, int $storeId): void
    {
        $this->queryText = $queryText;
        $this->storeId = $storeId;
        $this->entityIds = [];
        $this->failed = true;
    }

    public function hasFailed(string $queryText, int $storeId): bool
    {
        return $this->failed && $this->queryText === $queryText && $this->storeId === $storeId;
    }

    /**
     * @return int[]|null
     */
    public function get(string $queryText, int $storeId): ?array
    {
        if ($this->queryText !== $queryText || $this->storeId !== $storeId) {
            return null;
        }

        return $this->entityIds;
    }
}
