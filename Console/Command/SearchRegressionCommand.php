<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Kkkonrad\VectorSearch\Model\Search\SearchRegressionSuite;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchRegressionCommand extends Command
{
    private const OPTION_RULE = 'rule';

    public function __construct(
        private readonly SearchRegressionSuite $suite,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:regression:run')
            ->setDescription('Run VectorSearch ranking regression checks.')
            ->addOption(
                self::OPTION_RULE,
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Ad-hoc rule line. Can be passed multiple times.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('frontend');
        } catch (\Throwable) {
            // Area code may already be set by Magento CLI bootstrap.
        }

        $rules = $input->getOption(self::OPTION_RULE);
        $results = $this->suite->run(!empty($rules) ? implode("\n", $rules) : null);
        if (empty($results)) {
            $output->writeln('<comment>No VectorSearch regression rules configured.</comment>');
            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($results as $result) {
            $case = $result['case'];
            $status = $result['passed'] ? '<info>PASS</info>' : '<error>FAIL</error>';
            $output->writeln(sprintf(
                '%s "%s" store=%d count=%d',
                $status,
                $case['query'],
                $case['store'],
                $result['count']
            ));

            foreach ($result['failures'] as $failure) {
                $output->writeln('  - ' . $failure);
            }

            foreach ($result['top'] as $item) {
                $output->writeln(sprintf(
                    '  %2d. %d | %s | %s',
                    $item['position'],
                    $item['id'],
                    $item['sku'],
                    $item['name']
                ));
            }

            if (!$result['passed']) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $output->writeln(sprintf('<error>%d regression case(s) failed.</error>', $failed));
            return Command::FAILURE;
        }

        $output->writeln('<info>All VectorSearch regression cases passed.</info>');
        return Command::SUCCESS;
    }
}
