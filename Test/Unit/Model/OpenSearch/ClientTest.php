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
