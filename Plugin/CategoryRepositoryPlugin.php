<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Plugin;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

/** Keeps category names embedded in product documents synchronized. */
class CategoryRepositoryPlugin
{
    private const INDEXER_ID = 'vector_search_products';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly LoggerInterface $logger
    ) {}

    public function afterSave(
        CategoryRepositoryInterface $subject,
        CategoryInterface $result
    ): CategoryInterface {
        $this->invalidateIndexer();
        return $result;
    }

    public function aroundDelete(
        CategoryRepositoryInterface $subject,
        callable $proceed,
        CategoryInterface $category
    ): bool {
        $result = (bool)$proceed($category);
        $this->invalidateIndexer();
        return $result;
    }

    private function invalidateIndexer(): void
    {
        try {
            $this->indexerRegistry->get(self::INDEXER_ID)->invalidate();
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[VectorSearch] Could not invalidate vector index after category change: '
                . $exception->getMessage()
            );
        }
    }
}
