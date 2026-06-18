<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;

/**
 * One-time setup: registers the RRF search pipeline in OpenSearch.
 * Run this after module install, before the first reindex.
 *
 * Usage: php bin/magento vectorsearch:setup
 */
class SetupCommand extends Command
{
    public function __construct(
        private readonly OpenSearchClient $openSearchClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:setup')
             ->setDescription('Register the RRF search pipeline in OpenSearch (run once after install)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Registering RRF normalization pipeline in OpenSearch...</info>');

        try {
            $this->openSearchClient->ensurePipeline();
            $output->writeln('<info>Pipeline "kkkonrad-vectorsearch-rrf" registered successfully.</info>');
            $output->writeln('');
            $output->writeln('Next step: run <comment>php bin/magento vectorsearch:reindex</comment> to index your products.');
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
