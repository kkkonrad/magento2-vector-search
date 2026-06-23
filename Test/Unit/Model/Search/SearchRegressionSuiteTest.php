<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\Search\SearchRegressionSuite;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

class SearchRegressionSuiteTest extends TestCase
{
    public function testParsesRegressionRules(): void
    {
        $suite = $this->createSuite();

        $cases = $suite->parseRules(
            "spodenki dla kobiet | store=2 | limit=72 | min_results=12 | must_top=2040:3,1951:5 | must_not_top=21:10\n"
            . "# ignored\n"
            . "zegarek luma | must_top=41:3"
        );

        self::assertCount(2, $cases);
        self::assertSame('spodenki dla kobiet', $cases[0]['query']);
        self::assertSame(2, $cases[0]['store']);
        self::assertSame(72, $cases[0]['limit']);
        self::assertSame(12, $cases[0]['min_results']);
        self::assertSame([
            ['id' => 2040, 'position' => 3],
            ['id' => 1951, 'position' => 5],
        ], $cases[0]['must_top']);
        self::assertSame([
            ['id' => 21, 'position' => 10],
        ], $cases[0]['must_not_top']);
    }

    public function testEvaluatePassesAndFailsPositionAssertions(): void
    {
        $suite = $this->createSuite();
        $case = [
            'query' => 'spodenki dla kobiet',
            'store' => 1,
            'limit' => 72,
            'min_results' => 3,
            'must_top' => [
                ['id' => 2040, 'position' => 2],
            ],
            'must_not_top' => [
                ['id' => 21, 'position' => 3],
            ],
        ];

        self::assertSame([], $suite->evaluate($case, [2040, 1951, 1875, 21]));

        $failures = $suite->evaluate($case, [1951, 21, 2040]);
        self::assertCount(2, $failures);
        self::assertStringContainsString('Expected product 2040 in top 2', $failures[0]);
        self::assertStringContainsString('Expected product 21 outside top 3', $failures[1]);
    }

    private function createSuite(): SearchRegressionSuite
    {
        return new SearchRegressionSuite(
            $this->createMock(Config::class),
            $this->createMock(VectorSearchService::class),
            $this->createMock(ProductRepositoryInterface::class)
        );
    }
}
