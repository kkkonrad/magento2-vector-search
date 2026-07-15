<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model;

use Kkkonrad\VectorSearch\Model\Config;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EmbeddingClientTest extends TestCase
{
    public function testHealthCheckDoesNotGuessDimensionWhenServiceIsUnavailable(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getEmbeddingServiceUrl')->willReturn('http://127.0.0.1:1');
        $config->method('getEmbeddingServiceApiKey')->willReturn('');
        $client = new EmbeddingClient($config, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('health check failed');
        $client->getDimension();
    }
}
