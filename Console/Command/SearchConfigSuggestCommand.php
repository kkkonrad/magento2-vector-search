<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Console\Command;

use Kkkonrad\VectorSearch\Model\Search\AttributeIntentConfigSuggester;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SearchConfigSuggestCommand extends Command
{
    private const OPTION_ATTRIBUTE = 'attribute';
    private const OPTION_SAMPLE_SIZE = 'sample-size';
    private const OPTION_MAX_TERMS = 'max-terms';

    public function __construct(
        private readonly AttributeIntentConfigSuggester $suggester,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vectorsearch:config:suggest')
            ->setDescription('Suggest VectorSearch attribute intent rules from indexed OpenSearch attribute values.')
            ->addOption(
                self::OPTION_ATTRIBUTE,
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Attribute code to inspect. Can be passed multiple times. Defaults to all attr_* fields.'
            )
            ->addOption(
                self::OPTION_SAMPLE_SIZE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of indexed documents to sample per attribute field.',
                '25'
            )
            ->addOption(
                self::OPTION_MAX_TERMS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum suggested terms per attribute.',
                '12'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Throwable) {
            // Area code may already be set by Magento CLI bootstrap.
        }

        $attributes = array_map('trim', (array)$input->getOption(self::OPTION_ATTRIBUTE));
        $sampleSize = max(1, (int)$input->getOption(self::OPTION_SAMPLE_SIZE));
        $maxTerms = max(1, (int)$input->getOption(self::OPTION_MAX_TERMS));
        $suggestions = $this->suggester->suggest($attributes, $sampleSize, $maxTerms);

        if (empty($suggestions['fields'])) {
            $output->writeln('<comment>No matching attr_* fields found in the VectorSearch index.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>VectorSearch config suggestions:</info> fields=%d sample_size=%d max_terms=%d',
            count($suggestions['fields']),
            $sampleSize,
            $maxTerms
        ));

        $output->writeln('<comment>Inspected fields:</comment>');
        foreach ($suggestions['fields'] as $field) {
            $output->writeln(sprintf(
                '  %s docs=%s terms=%s',
                $field['field'],
                $field['docs'] === null ? 'n/a' : (string)$field['docs'],
                $this->formatFieldTerms($field)
            ));
        }

        $output->writeln('<comment>Suggested aliases:</comment>');
        foreach ($suggestions['aliases'] as $alias) {
            $output->writeln($alias);
        }

        $output->writeln('<comment>Suggested modes:</comment>');
        foreach ($suggestions['modes'] as $mode) {
            $output->writeln($mode);
        }

        $output->writeln('<comment>Suggested rules:</comment>');
        foreach ($suggestions['rules'] as $rule) {
            $output->writeln($rule);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function formatFieldTerms(array $field): string
    {
        if (empty($field['suggestions']) || !is_array($field['suggestions'])) {
            return empty($field['terms']) ? '-' : implode(', ', $field['terms']);
        }

        $parts = [];
        foreach ($field['suggestions'] as $suggestion) {
            $matchedTerms = $suggestion['matched_terms'] ?? [];
            $parts[] = sprintf(
                '%s(%d%s)',
                $suggestion['name'] ?? '',
                (int)($suggestion['count'] ?? 0),
                !empty($matchedTerms) ? ': ' . implode('/', $matchedTerms) : ''
            );
        }

        return empty($parts) ? '-' : implode(', ', $parts);
    }
}
