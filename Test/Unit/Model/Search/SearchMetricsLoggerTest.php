<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\Search;

use Kkkonrad\VectorSearch\Model\Search\SearchMetricsLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SearchMetricsLoggerTest extends TestCase
{
    public function testSummarizesDiagnosticsIntoStableMetricsPayload(): void
    {
        $metrics = new SearchMetricsLogger($this->createMock(LoggerInterface::class));

        $summary = $metrics->summarize([
            'query' => 'niebieskie szorty',
            'store_id' => 1,
            'filters' => [['field' => 'color', 'value' => 'blue']],
            'requested_limit' => 72,
            'page_size' => 12,
            'current_page' => 1,
            'total_count' => 72,
            'top_ids' => [1002, 963, 976],
            'page_ids' => [1002, 963],
            'timings_ms' => [
                'query_embedding' => 20.5,
                'opensearch_search' => 300.0,
                'reranker' => 120.2,
            ],
            'service' => [
                'limit' => 72,
                'model' => 'Xenova/multilingual-e5-small',
                'cache_enabled' => true,
            ],
            'events' => [
                ['name' => 'entity_ids_cache_miss', 'data' => []],
                ['name' => 'product_intent_detected', 'data' => ['group' => 'shorts', 'terms' => ['szort', 'spoden']]],
                ['name' => 'attribute_intents_detected', 'data' => ['intents' => [
                    ['attribute' => 'color', 'group' => 'blue', 'groups' => ['blue'], 'mode' => 'strict', 'fields' => ['color']],
                ]]],
                ['name' => 'reranking_result', 'data' => [
                    'relevant_count' => 3,
                    'soft_attribute_demoted_count' => 0,
                    'attribute_mismatched_demoted_count' => 11,
                    'demoted_count' => 6,
                    'remaining_relevant_count' => 6,
                    'remaining_demoted_count' => 46,
                    'cut_after' => 19,
                ]],
            ],
        ]);

        self::assertSame('niebieskie szorty', $summary['query']);
        self::assertSame(1, $summary['store_id']);
        self::assertSame(1, $summary['filters_count']);
        self::assertSame([1002, 963, 976], $summary['top_ids']);
        self::assertTrue($summary['cache']['miss']);
        self::assertFalse($summary['cache']['ids_process_hit']);
        self::assertSame('shorts', $summary['product_intent']['group']);
        self::assertSame(2, $summary['product_intent']['terms_count']);
        self::assertSame([
            [
                'attribute' => 'color',
                'group' => 'blue',
                'groups' => ['blue'],
                'mode' => 'strict',
                'fields' => ['color'],
            ],
        ], $summary['attribute_intents']);
        self::assertTrue($summary['reranking']['used']);
        self::assertSame(11, $summary['reranking']['attribute_mismatched_demoted_count']);
        self::assertFalse($summary['reranking_failed']);
        self::assertFalse($summary['reranking_circuit_open']);
    }

    public function testRecordWritesMetricsLogLine(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('[VectorSearch][metrics]'));

        (new SearchMetricsLogger($logger))->record([
            'query' => 'plecak',
            'store_id' => 1,
            'events' => [],
        ]);
    }
}
