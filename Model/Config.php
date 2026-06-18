<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_EMBEDDING_SERVICE_URL = 'vectorsearch/embedding/service_url';
    private const XML_OPENSEARCH_HOST       = 'vectorsearch/opensearch/host';
    private const XML_OPENSEARCH_PORT       = 'vectorsearch/opensearch/port';
    private const XML_OPENSEARCH_INDEX_NAME = 'vectorsearch/opensearch/index_name';
    private const XML_OPENSEARCH_USERNAME   = 'vectorsearch/opensearch/username';
    private const XML_OPENSEARCH_PASSWORD   = 'vectorsearch/opensearch/password';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface   $encryptor
    ) {}

    public function getEmbeddingServiceUrl(): string
    {
        return rtrim(
            (string)$this->scopeConfig->getValue(self::XML_EMBEDDING_SERVICE_URL),
            '/'
        );
    }

    public function getOpenSearchHost(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_HOST);
    }

    public function getOpenSearchPort(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_PORT);
    }

    public function getOpenSearchIndexName(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_INDEX_NAME);
    }

    public function getOpenSearchUsername(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_USERNAME);
    }

    public function getOpenSearchPassword(): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_PASSWORD);
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }
}
