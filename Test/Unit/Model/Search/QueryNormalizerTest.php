<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\QueryNormalizer;
use PHPUnit\Framework\TestCase;

class QueryNormalizerTest extends TestCase
{
    public function testRemovesStopWordsAndExpandsSynonyms(): void
    {
        $normalizer = $this->createNormalizer(
            "spodenki,szorty,shorts\nzegarek,zegarki,watch",
            'dla,do,z'
        );

        self::assertSame(
            'spodenki kobiet szorty shorts',
            $normalizer->normalize('spodenki dla kobiet')
        );
    }

    public function testDoesNotDuplicateExistingSynonymTokens(): void
    {
        $normalizer = $this->createNormalizer('spodenki,szorty,shorts', 'dla');

        self::assertSame(
            'szorty spodenki shorts',
            $normalizer->normalize('szorty spodenki')
        );
    }

    public function testSupportsEqualsSyntaxForSynonymGroups(): void
    {
        $normalizer = $this->createNormalizer('shorts=spodenki,szorty,shorts', '');

        self::assertSame(
            'shorts spodenki szorty',
            $normalizer->normalize('shorts')
        );
    }

    private function createNormalizer(string $synonymRules, string $stopWords): QueryNormalizer
    {
        $config = $this->createMock(Config::class);
        $config->method('getQuerySynonymRules')->willReturn($synonymRules);
        $config->method('getQueryStopWords')->willReturn($stopWords);

        return new QueryNormalizer($config);
    }
}
