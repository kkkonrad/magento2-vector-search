<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Kkkonrad\VectorSearch\Model\Search\ProductIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\SearchDiagnostics;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchExplainCommand extends Command
{
    private const ARG_QUERY = 'query';
    private const OPTION_STORE = 'store';
    private const OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly SearchDiagnostics $diagnostics,
        private readonly ProductIntentResolver $productIntentResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:explain')
            ->setDescription('Explain VectorSearch ranking for one query.')
            ->addArgument(self::ARG_QUERY, InputArgument::REQUIRED, 'Search query to explain.')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_OPTIONAL, 'Store ID.', '1')
            ->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Requested result limit.', '72');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('frontend');
        } catch (\Throwable) {
            // Area code may already be set by Magento CLI bootstrap.
        }

        $query = trim((string)$input->getArgument(self::ARG_QUERY));
        $storeId = max(1, (int)$input->getOption(self::OPTION_STORE));
        $limit = max(1, (int)$input->getOption(self::OPTION_LIMIT));
        $intent = $this->productIntentResolver->resolve($query);

        $this->diagnostics->start($query, $storeId, [], $limit, 0, 1);
        $ids = $this->vectorSearchService->getEntityIds($query, $storeId, [], $limit);
        $this->diagnostics->set('total_count', count($ids));
        $this->diagnostics->set('top_ids', array_slice($ids, 0, 25));
        $data = $this->diagnostics->getData();

        $output->writeln(sprintf('<info>Query:</info> %s', $query));
        $output->writeln(sprintf('<info>Store:</info> %d', $storeId));
        $output->writeln(sprintf(
            '<info>Intent:</info> %s [%s]',
            $intent['name'] !== '' ? $intent['name'] : 'none',
            implode(', ', $intent['terms'])
        ));
        $output->writeln(sprintf('<info>Total:</info> %d', count($ids)));

        if (isset($data['service'])) {
            $service = $data['service'];
            $output->writeln(sprintf(
                '<info>Service:</info> limit=%s model=%s index_version=%s cache=%s',
                $service['limit'] ?? '',
                $service['model'] ?? '',
                $service['index_version'] ?? '',
                !empty($service['cache_enabled']) ? 'enabled' : 'disabled'
            ));
        }

        if (!empty($data['timings_ms'])) {
            $timings = [];
            foreach ($data['timings_ms'] as $name => $ms) {
                $timings[] = $name . '=' . $ms . 'ms';
            }
            $output->writeln('<info>Timings:</info> ' . implode(', ', $timings));
        }

        $normalizedQuery = $this->getNormalizedQuery($data['events'] ?? []);
        if ($normalizedQuery !== null) {
            $output->writeln('<info>Normalized:</info> ' . $normalizedQuery);
        }

        $this->writeEvents($output, $data['events'] ?? []);
        $this->writeFinalTop($output, $ids, $storeId);

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function writeEvents(OutputInterface $output, array $events): void
    {
        foreach ($events as $event) {
            $name = (string)($event['name'] ?? '');
            $eventData = $event['data'] ?? [];
            if ($name === 'opensearch_raw_hits') {
                $output->writeln('<comment>Raw OpenSearch top:</comment>');
                foreach (array_slice($eventData['top'] ?? [], 0, 10) as $item) {
                    $output->writeln(sprintf(
                        '  %d | score=%s | %s | %s',
                        $item['id'] ?? 0,
                        $item['score'] ?? '',
                        $item['sku'] ?? '',
                        $item['name'] ?? ''
                    ));
                }
            } elseif ($name === 'reranking_result') {
                $output->writeln(sprintf(
                    '<comment>Reranking:</comment> relevant=%d demoted=%d remaining_relevant=%d remaining_demoted=%d cut_after=%s',
                    $eventData['relevant_count'] ?? 0,
                    $eventData['demoted_count'] ?? 0,
                    $eventData['remaining_relevant_count'] ?? 0,
                    $eventData['remaining_demoted_count'] ?? 0,
                    $eventData['cut_after'] ?? ''
                ));
                foreach (array_slice($eventData['reranked'] ?? [], 0, 10) as $item) {
                    $output->writeln(sprintf(
                        '  %d | score=%s | intent=%s | %s',
                        $item['id'] ?? 0,
                        $item['score'] ?? '',
                        !empty($item['matches_intent']) ? 'yes' : 'no',
                        $item['decision'] ?? ''
                    ));
                }
            } elseif ($name === 'reranking_failed' || $name === 'reranking_circuit_open') {
                $output->writeln('<error>' . $name . '</error> ' . json_encode($eventData, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function getNormalizedQuery(array $events): ?string
    {
        foreach ($events as $event) {
            if (($event['name'] ?? '') === 'query_normalized') {
                return (string)($event['data']['normalized'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param int[] $ids
     */
    private function writeFinalTop(OutputInterface $output, array $ids, int $storeId): void
    {
        $output->writeln('<comment>Final top:</comment>');
        foreach (array_slice($ids, 0, 10) as $index => $id) {
            try {
                $product = $this->productRepository->getById((int)$id, false, $storeId);
                $sku = (string)$product->getSku();
                $name = (string)$product->getName();
            } catch (\Throwable) {
                $sku = '';
                $name = '';
            }

            $output->writeln(sprintf('  %2d. %d | %s | %s', $index + 1, $id, $sku, $name));
        }
    }
}
