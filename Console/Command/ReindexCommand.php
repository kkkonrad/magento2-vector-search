<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Kkkonrad\VectorSearch\Model\Indexer\ProductVector;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;

class ReindexCommand extends Command
{
    public function __construct(
        private readonly ProductVector   $indexer,
        private readonly OpenSearchClient $openSearchClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:reindex')
             ->setDescription('Reindex all products into OpenSearch kNN vector index (also registers RRF pipeline)')
             ->addOption('ids', null, InputOption::VALUE_OPTIONAL, 'Comma-separated product IDs for partial reindex');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idsOption = $input->getOption('ids');

        if ($idsOption) {
            // Partial reindex — ensure pipeline exists but skip index recreation
            if (!$this->openSearchClient->pipelineExists()) {
                $output->writeln('<comment>RRF pipeline not found, registering...</comment>');
                $this->openSearchClient->ensurePipeline();
                $output->writeln('<info>Pipeline registered.</info>');
            }

            $ids = array_map('intval', explode(',', $idsOption));
            $output->writeln('<info>Partial reindex for IDs: ' . implode(', ', $ids) . '</info>');
            $this->indexer->executeList($ids);
        } else {
            $output->writeln('<info>Registering RRF pipeline...</info>');
            // ensureIndex() calls ensurePipeline() internally
            $output->writeln('<info>Starting full vector reindex...</info>');
            $this->indexer->executeFull();
        }

        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }
}
