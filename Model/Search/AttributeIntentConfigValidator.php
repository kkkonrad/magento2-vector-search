<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client;

class AttributeIntentConfigValidator
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client
    ) {}

    /**
     * @return array{summary: array<string, int>, aliases: array<string, string[]>, modes: array<string, string>, rules: array<int, array<string, mixed>>, messages: string[]}
     */
    public function validate(int $sampleSize = 5): array
    {
        $messages = [];
        $aliases = $this->parseAliases($this->config->getAttributeIntentAliases(), $messages);
        $modes = $this->parseModes($this->config->getAttributeIntentModes(), $messages);
        $rules = $this->parseRules($this->config->getAttributeIntentRules(), $aliases, $modes, $messages);

        $properties = $this->client->getMappingProperties();
        $fieldCache = [];
        foreach ($rules as $index => $rule) {
            $fieldResults = [];
            $anyFieldExists = false;
            $anyTermMatched = false;

            foreach ($rule['fields'] as $attributeField) {
                $field = 'attr_' . $attributeField;
                $exists = isset($properties[$field]);
                $anyFieldExists = $anyFieldExists || $exists;
                $inspection = ['total' => null, 'samples' => []];
                $termMatchCount = 0;

                if ($exists) {
                    if (!isset($fieldCache[$field])) {
                        $fieldCache[$field] = $this->client->sampleFieldValues($field, $sampleSize);
                    }
                    $inspection = $fieldCache[$field];
                    $termMatchCount = $this->client->countFieldTermMatches($field, $rule['terms']);
                    $termMatched = $termMatchCount > 0;
                    $anyTermMatched = $anyTermMatched || $termMatched;
                } else {
                    $termMatched = false;
                }

                $fieldResults[] = [
                    'name' => $field,
                    'exists' => $exists,
                    'total' => $inspection['total'],
                    'samples' => $inspection['samples'],
                    'term_matched' => $termMatched,
                    'term_match_count' => $termMatchCount,
                ];
            }

            $status = 'ok';
            $warnings = [];
            if (!$anyFieldExists) {
                $status = 'error';
                $warnings[] = 'No configured index field exists.';
            } elseif (!$anyTermMatched) {
                $status = 'warn';
                $warnings[] = 'No configured term matched sampled values.';
            }

            $rules[$index]['status'] = $status;
            $rules[$index]['warnings'] = $warnings;
            $rules[$index]['field_results'] = $fieldResults;
        }

        return [
            'summary' => $this->summarize($rules, $messages),
            'aliases' => $aliases,
            'modes' => $modes,
            'rules' => $rules,
            'messages' => $messages,
        ];
    }

    /**
     * @param string[] $messages
     * @return array<string, string[]>
     */
    private function parseAliases(string $rawAliases, array &$messages): array
    {
        $aliases = [];
        $lines = preg_split('/\R/u', $rawAliases) ?: [];
        foreach ($lines as $lineNumber => $line) {
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

            if ($attribute === '' || empty($fields)) {
                $messages[] = sprintf('Invalid alias line %d: "%s".', $lineNumber + 1, $line);
                continue;
            }

            $aliases[$attribute] = array_values(array_unique(array_merge([$attribute], $fields)));
        }

        return $aliases;
    }

    /**
     * @param array<string, string[]> $aliases
     * @param array<string, string> $modes
     * @param string[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function parseRules(string $rawRules, array $aliases, array $modes, array &$messages): array
    {
        $rules = [];
        $lines = preg_split('/\R/u', $rawRules) ?: [];
        foreach ($lines as $lineNumber => $line) {
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
                $messages[] = sprintf('Invalid rule line %d: "%s".', $lineNumber + 1, $line);
                continue;
            }

            $rules[] = [
                'line' => $lineNumber + 1,
                'attribute' => $attribute,
                'group' => $group,
                'mode' => $modes[$attribute] ?? 'strict',
                'terms' => $terms,
                'fields' => $aliases[$attribute] ?? [$attribute],
                'status' => 'ok',
                'warnings' => [],
                'field_results' => [],
            ];
        }

        return $rules;
    }

    /**
     * @param string[] $messages
     * @return array<string, string>
     */
    private function parseModes(string $rawModes, array &$messages): array
    {
        $modes = [];
        $lines = preg_split('/\R/u', $rawModes) ?: [];
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$attribute, $mode] = array_pad(explode('=', $line, 2), 2, '');
            $attribute = trim($attribute);
            $mode = trim(mb_strtolower($mode));
            if ($attribute === '' || !in_array($mode, ['strict', 'soft', 'off'], true)) {
                $messages[] = sprintf('Invalid mode line %d: "%s". Use attribute=strict|soft|off.', $lineNumber + 1, $line);
                continue;
            }

            $modes[$attribute] = $mode;
        }

        return $modes;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param string[] $messages
     * @return array<string, int>
     */
    private function summarize(array $rules, array $messages): array
    {
        $summary = [
            'ok' => 0,
            'warn' => 0,
            'error' => count($messages),
        ];

        foreach ($rules as $rule) {
            $status = (string)($rule['status'] ?? 'ok');
            if (!isset($summary[$status])) {
                $summary[$status] = 0;
            }
            $summary[$status]++;
        }

        return $summary;
    }

}
