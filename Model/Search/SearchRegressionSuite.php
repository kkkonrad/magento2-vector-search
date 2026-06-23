<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Kkkonrad\VectorSearch\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;

class SearchRegressionSuite
{
    public function __construct(
        private readonly Config $config,
        private readonly VectorSearchService $vectorSearchService,
        private readonly ProductRepositoryInterface $productRepository
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function run(?string $rules = null): array
    {
        $results = [];
        foreach ($this->parseRules($rules ?? $this->config->getRegressionRules()) as $case) {
            $ids = $this->vectorSearchService->getEntityIds(
                $case['query'],
                $case['store'],
                [],
                $case['limit']
            );

            $failures = $this->evaluate($case, $ids);
            $results[] = [
                'case' => $case,
                'passed' => empty($failures),
                'failures' => $failures,
                'count' => count($ids),
                'top' => $this->summarizeTop($ids, $case['store'], 10),
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseRules(string $rules): array
    {
        $cases = [];
        $lines = preg_split('/\R/u', $rules) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $query = array_shift($parts);
            if ($query === null || $query === '') {
                continue;
            }

            $case = [
                'query' => $query,
                'store' => 1,
                'limit' => null,
                'min_results' => 1,
                'must_top' => [],
                'must_not_top' => [],
            ];

            foreach ($parts as $part) {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                $key = trim($key);
                $value = trim($value);
                if ($key === '' || $value === '') {
                    continue;
                }

                if ($key === 'store') {
                    $case['store'] = max(1, (int)$value);
                } elseif ($key === 'limit') {
                    $case['limit'] = max(1, (int)$value);
                } elseif ($key === 'min_results') {
                    $case['min_results'] = max(0, (int)$value);
                } elseif ($key === 'must_top' || $key === 'must_not_top') {
                    $case[$key] = $this->parsePositionAssertions($value, $key === 'must_top' ? 10 : 10);
                }
            }

            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     * @param int[] $ids
     * @return string[]
     */
    public function evaluate(array $case, array $ids): array
    {
        $failures = [];
        $positions = array_flip($ids);
        $resultCount = count($ids);

        if ($resultCount < (int)$case['min_results']) {
            $failures[] = sprintf(
                'Expected at least %d result(s), got %d.',
                (int)$case['min_results'],
                $resultCount
            );
        }

        foreach ($case['must_top'] as $assertion) {
            $id = (int)$assertion['id'];
            $maxPosition = (int)$assertion['position'];
            $position = isset($positions[$id]) ? $positions[$id] + 1 : null;
            if ($position === null || $position > $maxPosition) {
                $failures[] = sprintf(
                    'Expected product %d in top %d, got %s.',
                    $id,
                    $maxPosition,
                    $position === null ? 'not found' : 'position ' . $position
                );
            }
        }

        foreach ($case['must_not_top'] as $assertion) {
            $id = (int)$assertion['id'];
            $maxPosition = (int)$assertion['position'];
            $position = isset($positions[$id]) ? $positions[$id] + 1 : null;
            if ($position !== null && $position <= $maxPosition) {
                $failures[] = sprintf(
                    'Expected product %d outside top %d, got position %d.',
                    $id,
                    $maxPosition,
                    $position
                );
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{id: int, position: int}>
     */
    private function parsePositionAssertions(string $value, int $defaultPosition): array
    {
        $assertions = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $rawAssertion) {
            [$rawId, $rawPosition] = array_pad(explode(':', $rawAssertion, 2), 2, (string)$defaultPosition);
            $id = (int)trim($rawId);
            if ($id <= 0) {
                continue;
            }

            $assertions[] = [
                'id' => $id,
                'position' => max(1, (int)trim($rawPosition)),
            ];
        }

        return $assertions;
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, position: int, sku: string, name: string}>
     */
    private function summarizeTop(array $ids, int $storeId, int $limit): array
    {
        $summary = [];
        foreach (array_slice($ids, 0, $limit) as $index => $id) {
            $summary[] = [
                'id' => (int)$id,
                'position' => $index + 1,
                'sku' => $this->getProductSku((int)$id, $storeId),
                'name' => $this->getProductName((int)$id, $storeId),
            ];
        }

        return $summary;
    }

    private function getProductSku(int $id, int $storeId): string
    {
        try {
            return (string)$this->productRepository->getById($id, false, $storeId)->getSku();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getProductName(int $id, int $storeId): string
    {
        try {
            return (string)$this->productRepository->getById($id, false, $storeId)->getName();
        } catch (\Throwable) {
            return '';
        }
    }
}
