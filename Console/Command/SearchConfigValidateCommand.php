<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Kkkonrad\VectorSearch\Model\Search\AttributeIntentConfigValidator;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchConfigValidateCommand extends Command
{
    private const OPTION_SAMPLE_SIZE = 'sample-size';

    public function __construct(
        private readonly AttributeIntentConfigValidator $validator,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:config:validate')
            ->setDescription('Validate VectorSearch attribute intent configuration against the OpenSearch index.')
            ->addOption(
                self::OPTION_SAMPLE_SIZE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of sample values to inspect per index field.',
                '5'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Throwable) {
            // Area code may already be set by Magento CLI bootstrap.
        }

        $sampleSize = max(1, (int)$input->getOption(self::OPTION_SAMPLE_SIZE));
        $report = $this->validator->validate($sampleSize);
        $summary = $report['summary'];

        $output->writeln(sprintf(
            '<info>VectorSearch config validation:</info> ok=%d warn=%d error=%d',
            $summary['ok'] ?? 0,
            $summary['warn'] ?? 0,
            $summary['error'] ?? 0
        ));

        foreach ($report['messages'] as $message) {
            $output->writeln('<error>ERROR</error> ' . $message);
        }

        if (!empty($report['aliases'])) {
            $output->writeln('<comment>Aliases:</comment>');
            foreach ($report['aliases'] as $attribute => $fields) {
                $output->writeln(sprintf('  %s -> %s', $attribute, implode(', ', array_map(
                    static fn(string $field): string => 'attr_' . $field,
                    $fields
                ))));
            }
        }

        if (!empty($report['modes'])) {
            $output->writeln('<comment>Modes:</comment>');
            foreach ($report['modes'] as $attribute => $mode) {
                $output->writeln(sprintf('  %s=%s', $attribute, $mode));
            }
        }

        if (empty($report['rules'])) {
            $output->writeln('<comment>No attribute intent rules configured.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Rules:</comment>');
        foreach ($report['rules'] as $rule) {
            $status = (string)$rule['status'];
            $label = $status === 'ok' ? '<info>OK</info>' : ($status === 'warn' ? '<comment>WARN</comment>' : '<error>ERROR</error>');
            $output->writeln(sprintf(
                '%s line=%d %s:%s mode=%s terms=%s',
                $label,
                $rule['line'],
                $rule['attribute'],
                $rule['group'],
                $rule['mode'],
                implode(', ', $rule['terms'])
            ));

            foreach ($rule['warnings'] as $warning) {
                $output->writeln('  - ' . $warning);
            }

            foreach ($rule['field_results'] as $field) {
                $samples = array_slice($field['samples'] ?? [], 0, $sampleSize);
                $output->writeln(sprintf(
                    '  %s exists=%s docs=%s term_matches=%d samples=%s',
                    $field['name'],
                    !empty($field['exists']) ? 'yes' : 'no',
                    $field['total'] === null ? 'n/a' : (string)$field['total'],
                    (int)($field['term_match_count'] ?? 0),
                    empty($samples) ? '-' : implode(' | ', $samples)
                ));
            }
        }

        return ((int)($summary['error'] ?? 0)) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
