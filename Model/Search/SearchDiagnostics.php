<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Magento\Framework\App\ResponseInterface;
use Psr\Log\LoggerInterface;

class SearchDiagnostics
{
    private bool $active = false;
    private bool $flushed = false;
    private string $debugId = '';

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param array<int, array{field: string, value: mixed}> $filters
     */
    public function start(
        string $queryText,
        int $storeId,
        array $filters,
        ?int $requestedLimit,
        int $pageSize,
        int $currentPage
    ): void {
        $this->active = true;
        $this->flushed = false;
        $this->debugId = bin2hex(random_bytes(8));
        $this->data = [
            'debug_id' => $this->debugId,
            'query' => $queryText,
            'store_id' => $storeId,
            'filters' => $filters,
            'requested_limit' => $requestedLimit,
            'page_size' => $pageSize,
            'current_page' => $currentPage,
            'events' => [],
            'timings_ms' => [],
        ];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getDebugId(): string
    {
        return $this->debugId;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        if (!$this->active) {
            return;
        }

        $this->data[$key] = $this->sanitize($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $name, array $data = []): void
    {
        if (!$this->active) {
            return;
        }

        $this->data['events'][] = [
            'name' => $name,
            'data' => $this->sanitize($data),
        ];
    }

    public function timing(string $name, float $startedAt): void
    {
        if (!$this->active) {
            return;
        }

        $this->data['timings_ms'][$name] = round((microtime(true) - $startedAt) * 1000, 2);
    }

    public function flush(LoggerInterface $logger, ?ResponseInterface $response = null): void
    {
        if (!$this->active || $this->flushed) {
            return;
        }

        if ($response !== null && method_exists($response, 'setHeader')) {
            $response->setHeader('X-VectorSearch-Debug-Id', $this->debugId, true);
        }

        $logger->info('[VectorSearch][diagnostics] ' . json_encode(
            $this->data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $this->flushed = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->sanitize($item);
            }
            return $result;
        }

        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '...';
        }

        return $value;
    }
}
