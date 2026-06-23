<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;

class AttributeIntentResolver
{
    /**
     * @var array<int, array{attribute: string, group: string, terms: string[]}>|null
     */
    private ?array $rules = null;

    public function __construct(
        private readonly Config $config,
        private readonly PolishStemmer $stemmer
    ) {}

    /**
     * @return array<int, array{attribute: string, group: string, terms: string[]}>
     */
    public function resolve(string $queryText): array
    {
        $stemmedQuery = $this->stemmer->stemText($queryText);
        $matches = [];

        foreach ($this->getRules() as $rule) {
            foreach ($rule['terms'] as $term) {
                if ($this->matchesStemmedText($stemmedQuery, $term)) {
                    $matches[$rule['attribute']] = $rule;
                    break;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{attribute: string, group: string, terms: string[]}> $intents
     */
    public function matchesSource(array $source, array $intents): bool
    {
        foreach ($intents as $intent) {
            if (!$this->matchesAttribute($source, $intent['attribute'], $intent['terms'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     * @param string[] $terms
     */
    private function matchesAttribute(array $source, string $attribute, array $terms): bool
    {
        $field = 'attr_' . $attribute;
        return $this->matchesText($this->valueToText($source[$field] ?? ''), $terms);
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
     * @return array<int, array{attribute: string, group: string, terms: string[]}>
     */
    private function getRules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = [];
        $lines = preg_split('/\R/u', $this->config->getAttributeIntentRules()) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$attributeAndGroup, $rawTerms] = array_pad(explode('=', $line, 2), 2, '');
            [$attribute, $group] = array_pad(explode(':', trim($attributeAndGroup), 2), 2, '');
            $attribute = trim($attribute);
            $group = trim($group);
            $terms = array_values(array_filter(array_map(
                static fn(string $term): string => trim($term),
                explode(',', $rawTerms)
            )));

            if ($attribute === '' || $group === '' || empty($terms)) {
                continue;
            }

            $rules[] = [
                'attribute' => $attribute,
                'group' => $group,
                'terms' => $terms,
            ];
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

        $stemmedTerm = $this->stemmer->stemText($term);
        $termTokens = preg_split('/[^\p{L}\p{N}-]+/u', $stemmedTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($stemmedText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($termTokens) === 1) {
            return in_array($termTokens[0], $tokens, true);
        }

        return str_contains(' ' . implode(' ', $tokens) . ' ', ' ' . implode(' ', $termTokens) . ' ');
    }
}
