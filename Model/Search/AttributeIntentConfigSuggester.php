<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\OpenSearch\Client;

class AttributeIntentConfigSuggester
{
    /**
     * Attribute fields that are useful internally, but noisy as human-facing intent rules.
     */
    private const SKIPPED_FIELDS = [
        'attr_category_names',
    ];

    /**
     * Curated groups turn indexed stems into portable configuration rules.
     * Unknown attributes still fall back to raw indexed tokens.
     */
    private const KNOWN_GROUPS = [
        'color' => [
            'blue' => ['niebiesk', 'niebieski', 'niebieskie', 'blue', 'granat', 'granatowy', 'navy'],
            'black' => ['czarn', 'czarne', 'czarny', 'black'],
            'red' => ['czerwon', 'czerwone', 'czerwony', 'red'],
            'green' => ['zielon', 'zielone', 'zielony', 'green'],
            'yellow' => ['żółt', 'zolt', 'żółty', 'zolty', 'yellow'],
            'purple' => ['fiolet', 'fioletowy', 'purple'],
            'gray' => ['szar', 'szare', 'szary', 'grey', 'gray'],
            'orange' => ['pomarańcz', 'pomarancz', 'pomarańczowy', 'pomaranczowy', 'orange'],
            'white' => ['biał', 'bial', 'biały', 'bialy', 'white'],
            'brown' => ['brąz', 'braz', 'brązowy', 'brazowy', 'brown'],
            'pink' => ['róż', 'roz', 'różowy', 'rozowy', 'pink'],
        ],
        'material' => [
            'cotton' => ['bawełn', 'bawełna', 'bawełniany', 'bawełniana', 'cotton'],
            'polyester' => ['poliester', 'polyester'],
            'wool' => ['wełn', 'welna', 'wełna', 'wool'],
            'leather' => ['skór', 'skora', 'skóra', 'leather'],
            'denim' => ['denim', 'jeans', 'dżins', 'dzins'],
        ],
    ];

    public function __construct(
        private readonly Client $client
    ) {}

    /**
     * @param string[] $attributes
     * @return array{aliases: string[], modes: string[], rules: string[], fields: array<int, array<string, mixed>>}
     */
    public function suggest(array $attributes = [], int $sampleSize = 25, int $maxTermsPerAttribute = 12): array
    {
        $attributeFilter = array_flip(array_filter(array_map('trim', $attributes)));
        $fields = $this->getAttributeFields();
        $aliases = [];
        $modes = [];
        $rules = [];
        $fieldReports = [];

        foreach ($fields as $field) {
            $attribute = substr($field, 5);
            if (!empty($attributeFilter) && !isset($attributeFilter[$attribute])) {
                continue;
            }

            $inspection = $this->client->sampleFieldValues($field, $sampleSize);
            $suggestedGroups = $this->extractGroups($attribute, $inspection['samples'], $maxTermsPerAttribute);
            $aliases[] = $attribute . '=' . $attribute;
            $modes[] = $attribute . '=strict';

            foreach ($suggestedGroups as $group) {
                $rules[] = sprintf('%s:%s=%s', $attribute, $group['name'], implode(',', $group['terms']));
            }

            $fieldReports[] = [
                'attribute' => $attribute,
                'field' => $field,
                'docs' => $inspection['total'],
                'samples' => $inspection['samples'],
                'terms' => array_column($suggestedGroups, 'name'),
                'suggestions' => $suggestedGroups,
            ];
        }

        return [
            'aliases' => array_values(array_unique($aliases)),
            'modes' => array_values(array_unique($modes ?? [])),
            'rules' => array_values(array_unique($rules)),
            'fields' => $fieldReports,
        ];
    }

    /**
     * @return string[]
     */
    private function getAttributeFields(): array
    {
        $properties = $this->client->getMappingProperties();
        $fields = [];
        foreach (array_keys($properties) as $field) {
            if (
                str_starts_with($field, 'attr_')
                && !str_ends_with($field, '_id')
                && !in_array($field, self::SKIPPED_FIELDS, true)
            ) {
                $fields[] = $field;
            }
        }

        sort($fields);
        return $fields;
    }

    /**
     * @param string[] $samples
     * @return array<int, array{name: string, terms: string[], count: int, matched_terms: string[]}>
     */
    private function extractGroups(string $attribute, array $samples, int $limit): array
    {
        $tokens = [];
        foreach ($samples as $sample) {
            foreach ($this->tokens($sample) as $token) {
                if ($this->isUsefulToken($token)) {
                    $tokens[$token] = ($tokens[$token] ?? 0) + 1;
                }
            }
        }

        if (isset(self::KNOWN_GROUPS[$attribute])) {
            return $this->extractKnownGroups($attribute, $tokens, $limit);
        }

        arsort($tokens);
        $groups = [];
        foreach (array_slice($tokens, 0, max(1, $limit), true) as $token => $count) {
            $groups[] = [
                'name' => $token,
                'terms' => [$token],
                'count' => $count,
                'matched_terms' => [$token],
            ];
        }

        return $groups;
    }

    /**
     * @param array<string, int> $tokens
     * @return array<int, array{name: string, terms: string[], count: int, matched_terms: string[]}>
     */
    private function extractKnownGroups(string $attribute, array $tokens, int $limit): array
    {
        $groups = [];
        foreach (self::KNOWN_GROUPS[$attribute] as $groupName => $terms) {
            $count = 0;
            $matchedTerms = [];
            foreach ($terms as $term) {
                $term = mb_strtolower($term);
                if (isset($tokens[$term])) {
                    $count += $tokens[$term];
                    $matchedTerms[] = $term;
                }
            }

            if ($count <= 0) {
                continue;
            }

            $groups[] = [
                'name' => $groupName,
                'terms' => $terms,
                'count' => $count,
                'matched_terms' => array_values(array_unique($matchedTerms)),
            ];
        }

        usort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        return array_slice($groups, 0, max(1, $limit));
    }

    /**
     * @return string[]
     */
    private function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function isUsefulToken(string $token): bool
    {
        return mb_strlen($token) >= 3 && !is_numeric($token);
    }
}
