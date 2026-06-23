<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;

class QueryNormalizer
{
    /**
     * @var string[]|null
     */
    private ?array $stopWords = null;

    /**
     * @var array<int, string[]>|null
     */
    private ?array $synonymGroups = null;

    public function __construct(
        private readonly Config $config
    ) {}

    public function normalize(string $queryText): string
    {
        $tokens = $this->tokenize($queryText);
        if (empty($tokens)) {
            return trim($queryText);
        }

        $tokens = array_values(array_filter(
            $tokens,
            fn(string $token): bool => !in_array($token, $this->getStopWords(), true)
        ));

        $expanded = $tokens;
        $queryForPhraseMatch = ' ' . implode(' ', $tokens) . ' ';
        foreach ($this->getSynonymGroups() as $group) {
            if (!$this->groupMatches($group, $tokens, $queryForPhraseMatch)) {
                continue;
            }

            foreach ($group as $term) {
                foreach ($this->tokenize($term) as $termToken) {
                    if (!in_array($termToken, $expanded, true)) {
                        $expanded[] = $termToken;
                    }
                }
            }
        }

        return implode(' ', $expanded);
    }

    /**
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $tokens = preg_split('/[^\p{L}\p{N}-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_map('trim', $tokens));
    }

    /**
     * @param string[] $group
     * @param string[] $tokens
     */
    private function groupMatches(array $group, array $tokens, string $queryForPhraseMatch): bool
    {
        foreach ($group as $term) {
            $termTokens = $this->tokenize($term);
            if (empty($termTokens)) {
                continue;
            }
            if (count($termTokens) === 1 && in_array($termTokens[0], $tokens, true)) {
                return true;
            }
            if (str_contains($queryForPhraseMatch, ' ' . implode(' ', $termTokens) . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string[]>
     */
    private function getSynonymGroups(): array
    {
        if ($this->synonymGroups !== null) {
            return $this->synonymGroups;
        }

        $groups = [];
        $lines = preg_split('/\R/u', $this->config->getQuerySynonymRules()) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [, $line] = array_pad(explode('=', $line, 2), 2, '');
            }

            $terms = array_values(array_filter(array_map(
                static fn(string $term): string => mb_strtolower(trim($term)),
                explode(',', $line)
            )));
            if (count($terms) > 1) {
                $groups[] = $terms;
            }
        }

        return $this->synonymGroups = $groups;
    }

    /**
     * @return string[]
     */
    private function getStopWords(): array
    {
        if ($this->stopWords !== null) {
            return $this->stopWords;
        }

        return $this->stopWords = array_values(array_filter(array_map(
            static fn(string $word): string => mb_strtolower(trim($word)),
            explode(',', $this->config->getQueryStopWords())
        )));
    }
}
