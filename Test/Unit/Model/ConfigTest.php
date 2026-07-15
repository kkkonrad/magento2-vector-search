<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Test\Unit\Model;

use Kkkonrad\VectorSearch\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testRankingChangeProducesDifferentCacheFingerprint(): void
    {
        $first = $this->createConfig(['vectorsearch/opensearch/lexical_weight' => '0.7']);
        $second = $this->createConfig(['vectorsearch/opensearch/lexical_weight' => '0.8']);

        self::assertNotSame($first->getSearchConfigFingerprint(), $second->getSearchConfigFingerprint());
    }

    public function testEmbeddingApiKeyIsDecrypted(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn(string $path): ?string => $path === 'vectorsearch/embedding/api_key'
                ? 'encrypted-value'
                : null
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())->method('decrypt')->with('encrypted-value')->willReturn('secret');

        self::assertSame('secret', (new Config($scope, $encryptor))->getEmbeddingServiceApiKey());
    }

    /** @param array<string, mixed> $values */
    private function createConfig(array $values): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn(string $path): mixed => $values[$path] ?? null
        );
        $scope->method('isSetFlag')->willReturn(false);
        return new Config($scope, $this->createMock(EncryptorInterface::class));
    }
}
