<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Console\Command;

use Kkkonrad\VectorSearch\Console\Command\SearchExplainCommand;
use Kkkonrad\VectorSearch\Model\Search\ProductIntentResolver;
use Kkkonrad\VectorSearch\Model\Search\SearchDiagnostics;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchExplainCommandTest extends TestCase
{
    public function testPrintsExplainReportWithAttributeDetails(): void
    {
        $diagnostics = new SearchDiagnostics();

        $vectorSearchService = $this->createMock(VectorSearchService::class);
        $vectorSearchService->expects(self::once())
            ->method('getEntityIds')
            ->with('niebieskie szorty', 1, [], 72)
            ->willReturnCallback(static function () use ($diagnostics): array {
                $diagnostics->set('service', [
                    'limit' => 72,
                    'model' => 'Xenova/multilingual-e5-small',
                    'index_version' => '123',
                    'cache_enabled' => false,
                ]);
                $diagnostics->event('query_normalized', [
                    'original' => 'niebieskie szorty',
                    'normalized' => 'niebieskie szorty spodenki shorts',
                ]);
                $diagnostics->event('attribute_intents_detected', [
                    'intents' => [
                        [
                            'attribute' => 'color',
                            'group' => 'blue',
                            'fields' => ['color'],
                            'mode' => 'strict',
                        ],
                    ],
                ]);
                $diagnostics->event('reranking_result', [
                    'relevant_count' => 1,
                    'soft_attribute_demoted_count' => 0,
                    'demoted_count' => 0,
                    'attribute_mismatched_demoted_count' => 1,
                    'remaining_relevant_count' => 0,
                    'remaining_demoted_count' => 0,
                    'cut_after' => 2,
                    'reranked' => [
                        [
                            'id' => 1002,
                            'score' => 1.7,
                            'matches_intent' => true,
                            'attribute_details' => [
                                [
                                    'attribute' => 'color',
                                    'group' => 'blue',
                                    'mode' => 'strict',
                                    'matched' => true,
                                    'matched_fields' => ['attr_color'],
                                ],
                            ],
                            'decision' => 'relevant',
                        ],
                        [
                            'id' => 1919,
                            'score' => 0.3,
                            'matches_intent' => true,
                            'attribute_details' => [
                                [
                                    'attribute' => 'color',
                                    'group' => 'blue',
                                    'mode' => 'strict',
                                    'matched' => false,
                                    'matched_fields' => [],
                                ],
                            ],
                            'decision' => 'demoted',
                        ],
                    ],
                ]);

                return [1002, 1919];
            });

        $productIntentResolver = $this->createMock(ProductIntentResolver::class);
        $productIntentResolver->expects(self::once())
            ->method('resolve')
            ->with('niebieskie szorty')
            ->willReturn([
                'name' => 'shorts',
                'terms' => ['szort', 'spoden'],
            ]);

        $tester = new CommandTester(new SearchExplainCommand(
            $vectorSearchService,
            $diagnostics,
            $productIntentResolver,
            $this->createProductRepositoryMock(),
            $this->createStateMock()
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'query' => 'niebieskie szorty',
            '--store' => '1',
            '--limit' => '72',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Query: niebieskie szorty', $output);
        self::assertStringContainsString('Intent: shorts [szort, spoden]', $output);
        self::assertStringContainsString('Normalized: niebieskie szorty spodenki shorts', $output);
        self::assertStringContainsString('Attributes: color:blue fields=attr_color', $output);
        self::assertStringContainsString('Reranking: relevant=1 soft_attr=0 demoted=0 attr_demoted=1', $output);
        self::assertStringContainsString('1002 | score=1.7 | product=yes | attr=color:blue[strict]=yes(attr_color) | relevant', $output);
        self::assertStringContainsString('1919 | score=0.3 | product=yes | attr=color:blue[strict]=no | demoted', $output);
        self::assertStringContainsString('1. 1002 | MSH10 | Szorty sportowe Sol Active', $output);
        self::assertStringContainsString('2. 1919 | WSH01 | Szorty treningowe Fiona', $output);
    }

    private function createProductRepositoryMock(): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function (int $id): ProductInterface {
            $product = $this->createMock(ProductInterface::class);
            if ($id === 1002) {
                $product->method('getSku')->willReturn('MSH10');
                $product->method('getName')->willReturn('Szorty sportowe Sol Active');
            } else {
                $product->method('getSku')->willReturn('WSH01');
                $product->method('getName')->willReturn('Szorty treningowe Fiona');
            }

            return $product;
        });

        return $repository;
    }

    private function createStateMock(): State
    {
        $state = $this->createMock(State::class);
        $state->method('setAreaCode')->willReturn(null);

        return $state;
    }
}
