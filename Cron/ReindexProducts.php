<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Cron;

use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\Indexer\ProductVector;

class ReindexProducts
{
    public function __construct(
        private readonly ProductVector   $indexer,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        $this->logger->info('[VectorSearch] Cron: starting nightly full reindex.');
        try {
            $this->indexer->executeFull();
        } catch (\Exception $e) {
            $this->logger->error('[VectorSearch] Cron reindex failed: ' . $e->getMessage());
        }
    }
}
