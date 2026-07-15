<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Plugin;

use Kkkonrad\VectorSearch\Plugin\CategoryRepositoryPlugin;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CategoryRepositoryPluginTest extends TestCase
{
    public function testCategorySaveInvalidatesVectorIndexerWithoutSynchronousReindex(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('invalidate');
        $indexer->expects(self::never())->method('reindexList');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects(self::once())->method('get')->with('vector_search_products')->willReturn($indexer);

        $plugin = new CategoryRepositoryPlugin($registry, new NullLogger());
        $category = $this->createMock(CategoryInterface::class);

        self::assertSame(
            $category,
            $plugin->afterSave($this->createMock(CategoryRepositoryInterface::class), $category)
        );
    }
}
