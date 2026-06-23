<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;

class ProductIntentResolver
{
    /**
     * @var array<string, string[]>|null
     */
    private ?array $rules = null;

    public function __construct(
        private readonly Config $config,
        private readonly PolishStemmer $stemmer
    ) {}

    /**
     * @return array{name: string, terms: string[]}
     */
    public function resolve(string $queryText): array
    {
        $stemmedQuery = $this->stemmer->stemText($queryText);
        foreach ($this->getRules() as $groupName => $terms) {
            foreach ($terms as $term) {
                if ($this->matchesStemmedText($stemmedQuery, $term)) {
                    return [
                        'name' => $groupName,
                        'terms' => $terms,
                    ];
                }
            }
        }

        return [
            'name' => '',
            'terms' => [],
        ];
    }

    /**
     * Returns true when no concrete product intent was detected, or when the
     * candidate text contains at least one term from the detected product family.
     *
     * @param string[] $intentTerms
     */
    public function matches(string $documentText, array $intentTerms): bool
    {
        if (empty($intentTerms)) {
            return true;
        }

        $stemmedText = $this->stemmer->stemText($documentText);
        foreach ($intentTerms as $term) {
            if ($this->matchesStemmedText($stemmedText, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a product intent against structured OpenSearch fields first:
     * categories, product name/description and indexed EAV attribute labels.
     * embedding_text is only a compatibility fallback for older index documents.
     *
     * @param array<string, mixed> $source
     * @param string[] $intentTerms
     */
    public function matchesSource(array $source, array $intentTerms): bool
    {
        if (empty($intentTerms)) {
            return true;
        }

        $structuredText = $this->buildStructuredText($source);
        if ($structuredText !== '' && $this->matches($structuredText, $intentTerms)) {
            return true;
        }

        return $structuredText === '' && $this->matches((string)($source['embedding_text'] ?? ''), $intentTerms);
    }

    /**
     * @return array<string, string[]>
     */
    public function getRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = $this->parseRules($this->config->getProductIntentRules());
        return $this->rules = !empty($rules) ? $rules : $this->getDefaultRules();
    }

    /**
     * @return array<string, string[]>
     */
    private function parseRules(string $rawRules): array
    {
        $rules = [];
        $lines = preg_split('/\R/u', $rawRules) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$name, $rawTerms] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            $terms = array_values(array_filter(array_map(
                static fn(string $term): string => trim($term),
                explode(',', $rawTerms)
            )));

            if ($name === '' || empty($terms)) {
                continue;
            }

            $rules[$name] = $terms;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function buildStructuredText(array $source): string
    {
        $parts = [];
        foreach (['name', 'description', 'category_names'] as $field) {
            if (isset($source[$field])) {
                $parts[] = $this->valueToText($source[$field]);
            }
        }

        foreach ($source as $field => $value) {
            if (!str_starts_with((string)$field, 'attr_') || str_ends_with((string)$field, '_id')) {
                continue;
            }
            $parts[] = $this->valueToText($value);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param mixed $value
     */
    private function valueToText($value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map(fn($item): string => $this->valueToText($item), $value)));
        }

        return trim((string)$value);
    }

    /**
     * @return array<string, string[]>
     */
    private function getDefaultRules(): array
    {
        return [
            'pants' => ['spodn', 'leggins', 'rybacz', 'capri'],
            'shorts' => ['szort', 'spoden'],
            'shirts' => ['koszulk', 'shirt', 't-shirt', 'bez rękaw', 'bez rekaw', 'tank'],
            'jackets' => ['kurtk', 'jacket'],
            'hoodies' => ['bluz', 'hoodie'],
            'bras' => ['stanik', 'biustonosz', 'bra'],
            'shoes' => ['buty', 'shoe'],
            'bags' => ['torb', 'plecak', 'bag', 'backpack'],
            'bottles' => ['butel', 'bidon'],
            'mats' => ['mata'],
            'balls' => ['piłk', 'pilk', 'ball'],
            'blocks' => ['kloc', 'klocek', 'blok', 'block'],
            'watches' => ['zegar', 'zegarek', 'watch'],
        ];
    }

    private function matchesStemmedText(string $stemmedText, string $term): bool
    {
        $term = trim(mb_strtolower($term));
        if ($term === '') {
            return false;
        }

        $termTokens = preg_split('/[^\p{L}\p{N}-]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($termTokens)) {
            return false;
        }

        $tokens = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($stemmedText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($termTokens) === 1) {
            return in_array($termTokens[0], $tokens, true);
        }

        return str_contains(' ' . implode(' ', $tokens) . ' ', ' ' . implode(' ', $termTokens) . ' ');
    }
}
