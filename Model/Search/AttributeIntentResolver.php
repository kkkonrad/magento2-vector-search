<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;

class AttributeIntentResolver
{
    /**
     * @var array<int, array{attribute: string, group: string, groups: string[], terms: string[], fields: string[], mode: string}>|null
     */
    private ?array $rules = null;

    /**
     * @var array<string, string[]>|null
     */
    private ?array $aliases = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $modes = null;

    public function __construct(
        private readonly Config $config,
        private readonly PolishStemmer $stemmer
    ) {}

    /**
     * @return array<int, array{attribute: string, group: string, groups: string[], terms: string[], fields: string[], mode: string}>
     */
    public function resolve(string $queryText): array
    {
        $stemmedQuery = $this->stemmer->stemText($queryText);
        $matches = [];

        foreach ($this->getRules() as $rule) {
            foreach ($rule['terms'] as $term) {
                if ($this->matchesStemmedText($stemmedQuery, $term)) {
                    $attribute = $rule['attribute'];
                    if (isset($matches[$attribute])) {
                        $matches[$attribute]['groups'][] = $rule['group'];
                        $matches[$attribute]['group'] = implode('|', $matches[$attribute]['groups']);
                        $matches[$attribute]['terms'] = array_values(array_unique(array_merge(
                            $matches[$attribute]['terms'],
                            $rule['terms']
                        )));
                    } else {
                        $matches[$attribute] = $rule;
                    }
                    break;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{attribute: string, group: string, groups?: string[], terms: string[], fields: string[], mode: string}> $intents
     */
    public function matchesSource(array $source, array $intents): bool
    {
        foreach ($this->matchDetails($source, $intents) as $detail) {
            if (!$detail['matched'] && $detail['mode'] === 'strict') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array{attribute: string, group: string, groups?: string[], terms: string[], fields: string[], mode: string}> $intents
     * @return array<int, array{attribute: string, group: string, groups: string[], mode: string, fields: string[], matched: bool, matched_fields: string[]}>
     */
    public function matchDetails(array $source, array $intents): array
    {
        $details = [];
        foreach ($intents as $intent) {
            $matchedFields = [];
            foreach ($intent['fields'] as $attributeField) {
                $field = 'attr_' . $attributeField;
                if ($this->matchesText($this->valueToText($source[$field] ?? ''), $intent['terms'])) {
                    $matchedFields[] = $field;
                }
            }

            $details[] = [
                'attribute' => $intent['attribute'],
                'group' => $intent['group'],
                'groups' => $intent['groups'] ?? [$intent['group']],
                'mode' => $intent['mode'],
                'fields' => array_map(static fn(string $field): string => 'attr_' . $field, $intent['fields']),
                'matched' => !empty($matchedFields),
                'matched_fields' => $matchedFields,
            ];
        }

        return $details;
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
     * @return array<int, array{attribute: string, group: string, groups: string[], terms: string[], fields: string[], mode: string}>
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

            $mode = $this->getAttributeMode($attribute);
            if ($mode === 'off') {
                continue;
            }

            $rules[] = [
                'attribute' => $attribute,
                'group' => $group,
                'groups' => [$group],
                'terms' => $terms,
                'fields' => $this->getAttributeFields($attribute),
                'mode' => $mode,
            ];
        }

        return $this->rules = $rules;
    }

    /**
     * @return string[]
     */
    private function getAttributeFields(string $attribute): array
    {
        $aliases = $this->getAliases();
        if (!isset($aliases[$attribute])) {
            return [$attribute];
        }

        return $aliases[$attribute];
    }

    private function getAttributeMode(string $attribute): string
    {
        return $this->getModes()[$attribute] ?? 'strict';
    }

    /**
     * @return array<string, string[]>
     */
    private function getAliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }

        $aliases = [];
        $lines = preg_split('/\R/u', $this->config->getAttributeIntentAliases()) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$attribute, $rawFields] = array_pad(explode('=', $line, 2), 2, '');
            $attribute = trim($attribute);
            $fields = array_values(array_filter(array_map(
                static fn(string $field): string => trim($field),
                explode(',', $rawFields)
            )));

            if ($attribute === '') {
                continue;
            }

            $aliases[$attribute] = array_values(array_unique(array_merge([$attribute], $fields)));
        }

        return $this->aliases = $aliases;
    }

    /**
     * @return array<string, string>
     */
    private function getModes(): array
    {
        if ($this->modes !== null) {
            return $this->modes;
        }

        $modes = [];
        $lines = preg_split('/\R/u', $this->config->getAttributeIntentModes()) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$attribute, $mode] = array_pad(explode('=', $line, 2), 2, '');
            $attribute = trim($attribute);
            $mode = trim(mb_strtolower($mode));
            if ($attribute === '' || !in_array($mode, ['strict', 'soft', 'off'], true)) {
                continue;
            }

            $modes[$attribute] = $mode;
        }

        return $this->modes = $modes;
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
