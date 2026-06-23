<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;

class ColorIntentResolver
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
        foreach ($this->getRules() as $name => $terms) {
            foreach ($terms as $term) {
                if ($this->matchesStemmedText($stemmedQuery, $term)) {
                    return [
                        'name' => $name,
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
     * @param array<string, mixed> $source
     * @param string[] $terms
     */
    public function matchesSource(array $source, array $terms): bool
    {
        if (empty($terms)) {
            return true;
        }

        return $this->matchesText($this->valueToText($source['attr_color'] ?? ''), $terms);
    }

    /**
     * @param string[] $terms
     */
    private function matchesText(string $text, array $terms): bool
    {
        $stemmedText = $this->stemmer->stemText($text);
        foreach ($terms as $term) {
            if ($this->matchesStemmedText($stemmedText, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string[]>
     */
    private function getRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = [];
        $lines = preg_split('/\R/u', $this->config->getColorIntentRules()) ?: [];
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
            if ($name !== '' && !empty($terms)) {
                $rules[$name] = $terms;
            }
        }

        return $this->rules = $rules;
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

    private function matchesStemmedText(string $stemmedText, string $term): bool
    {
        $term = trim(mb_strtolower($term));
        if ($term === '') {
            return false;
        }

        $termTokens = preg_split('/[^\p{L}\p{N}-]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($stemmedText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($termTokens) === 1) {
            return in_array($termTokens[0], $tokens, true);
        }

        return str_contains(' ' . implode(' ', $tokens) . ' ', ' ' . implode(' ', $termTokens) . ' ');
    }
}
