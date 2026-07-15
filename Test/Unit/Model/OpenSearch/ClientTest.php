<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model\OpenSearch;

use Kkkonrad\VectorSearch\Model\AttributeWeightProvider;
use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ClientTest extends TestCase
{
    public function testHashLookupUsesLiveAliasWhileRebuildWritesToStagingIndex(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getOpenSearchIndexName')->willReturn('test_vector_index');

        $client = new class(
            $config,
            new NullLogger(),
            $this->createMock(AttributeWeightProvider::class),
            new PolishStemmer(),
            $this->createMock(EmbeddingClient::class)
        ) extends Client {
            /** @var array<int, array{method: string, path: string, body: array, logErrors: bool}> */
            public array $requests = [];

            protected function request(
                string $method,
                string $path,
                array $body = [],
                bool $logErrors = true
            ): array {
                $this->requests[] = compact('method', 'path', 'body', 'logErrors');

                if ($method === 'GET' && $path === '/_alias/test_vector_index_current') {
                    return ['test_vector_index_v_active' => ['aliases' => []]];
                }

                return ['hits' => ['hits' => []]];
            }
        };

        $property = new \ReflectionProperty(Client::class, 'rebuildIndexName');
        $property->setValue($client, 'test_vector_index_v_staging');

        $client->getDocsForHashCheck([10, 20], 1);

        self::assertSame('/_alias/test_vector_index_current', $client->requests[0]['path']);
        self::assertSame('/test_vector_index_current/_search', $client->requests[1]['path']);
        self::assertSame([10, 20], $client->requests[1]['body']['query']['bool']['filter'][0]['terms']['entity_id']);
        self::assertSame(1, $client->requests[1]['body']['query']['bool']['filter'][1]['term']['store_id']);
    }

    public function testTransportFailureIsNotConvertedToMissingIndex(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getOpenSearchHost')->willReturn('127.0.0.1');
        $config->method('getOpenSearchPort')->willReturn('1');
        $config->method('getOpenSearchIndexName')->willReturn('test_vector_index');
        $config->method('getOpenSearchUsername')->willReturn('');
        $config->method('getOpenSearchPassword')->willReturn('');

        $client = new Client(
            $config,
            new NullLogger(),
            $this->createMock(AttributeWeightProvider::class),
            new PolishStemmer(),
            $this->createMock(EmbeddingClient::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenSearch unavailable');
        $client->indexExists();
    }
}
