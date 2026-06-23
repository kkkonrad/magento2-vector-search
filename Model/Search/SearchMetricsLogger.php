<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Psr\Log\LoggerInterface;

class SearchMetricsLogger
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string, mixed> $diagnostics
     */
    public function record(array $diagnostics): void
    {
        $summary = $this->summarize($diagnostics);
        $this->logger->info('[VectorSearch][metrics] ' . json_encode(
            $summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    public function summarize(array $diagnostics): array
    {
        $events = $diagnostics['events'] ?? [];
        $reranking = $this->lastEventData($events, 'reranking_result');
        $productIntent = $this->lastEventData($events, 'product_intent_detected');
        $attributeIntents = $this->lastEventData($events, 'attribute_intents_detected');
        $searchResult = $this->lastEventData($events, 'entity_ids_search_result');

        return [
            'query' => $diagnostics['query'] ?? '',
            'store_id' => $diagnostics['store_id'] ?? null,
            'filters_count' => is_array($diagnostics['filters'] ?? null) ? count($diagnostics['filters']) : 0,
            'requested_limit' => $diagnostics['requested_limit'] ?? null,
            'page_size' => $diagnostics['page_size'] ?? null,
            'current_page' => $diagnostics['current_page'] ?? null,
            'total_count' => $diagnostics['total_count'] ?? ($searchResult['count'] ?? null),
            'top_ids' => array_slice($diagnostics['top_ids'] ?? ($searchResult['top_ids'] ?? []), 0, 10),
            'page_ids' => array_slice($diagnostics['page_ids'] ?? [], 0, 10),
            'timings_ms' => $diagnostics['timings_ms'] ?? [],
            'service' => $diagnostics['service'] ?? [],
            'cache' => $this->cacheSummary($events),
            'product_intent' => [
                'group' => $productIntent['group'] ?? '',
                'terms_count' => is_array($productIntent['terms'] ?? null) ? count($productIntent['terms']) : 0,
            ],
            'attribute_intents' => $this->attributeIntentSummary($attributeIntents['intents'] ?? []),
            'reranking' => [
                'used' => !empty($reranking),
                'relevant_count' => $reranking['relevant_count'] ?? null,
                'soft_attribute_demoted_count' => $reranking['soft_attribute_demoted_count'] ?? null,
                'attribute_mismatched_demoted_count' => $reranking['attribute_mismatched_demoted_count'] ?? null,
                'demoted_count' => $reranking['demoted_count'] ?? null,
                'remaining_relevant_count' => $reranking['remaining_relevant_count'] ?? null,
                'remaining_demoted_count' => $reranking['remaining_demoted_count'] ?? null,
                'cut_after' => $reranking['cut_after'] ?? null,
            ],
            'reranking_circuit_open' => $this->hasEvent($events, 'reranking_circuit_open'),
            'reranking_failed' => $this->hasEvent($events, 'reranking_failed'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, bool>
     */
    private function cacheSummary(array $events): array
    {
        return [
            'ids_process_hit' => $this->hasEvent($events, 'entity_ids_process_cache_hit'),
            'ids_magento_hit' => $this->hasEvent($events, 'entity_ids_magento_cache_hit'),
            'vector_process_hit' => $this->hasEvent($events, 'query_vector_process_cache_hit'),
            'vector_magento_hit' => $this->hasEvent($events, 'query_vector_magento_cache_hit'),
            'miss' => $this->hasEvent($events, 'entity_ids_cache_miss'),
        ];
    }

    /**
     * @param mixed $intents
     * @return array<int, array{attribute: string, group: string, groups: array<int, mixed>, mode: string, fields: array<int, mixed>}>
     */
    private function attributeIntentSummary($intents): array
    {
        if (!is_array($intents)) {
            return [];
        }

        $summary = [];
        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            $summary[] = [
                'attribute' => (string)($intent['attribute'] ?? ''),
                'group' => (string)($intent['group'] ?? ''),
                'groups' => is_array($intent['groups'] ?? null) ? $intent['groups'] : [($intent['group'] ?? '')],
                'mode' => (string)($intent['mode'] ?? 'strict'),
                'fields' => is_array($intent['fields'] ?? null) ? $intent['fields'] : [],
            ];
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function lastEventData(array $events, string $name): array
    {
        $result = [];
        foreach ($events as $event) {
            if (($event['name'] ?? '') === $name && is_array($event['data'] ?? null)) {
                $result = $event['data'];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function hasEvent(array $events, string $name): bool
    {
        foreach ($events as $event) {
            if (($event['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }
}
