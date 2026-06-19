<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const XML_EMBEDDING_SERVICE_URL = 'vectorsearch/embedding/service_url';
    private const XML_OPENSEARCH_INDEX_NAME = 'vectorsearch/opensearch/index_name';
    private const XML_OPENSEARCH_SEARCH_TYPE = 'vectorsearch/opensearch/search_type';

    private const XML_OPENSEARCH_MIN_SIMILARITY = 'vectorsearch/opensearch/min_similarity';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
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
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        if ($engine !== '') {
            $host = (string)$this->scopeConfig->getValue("catalog/search/{$engine}_server_hostname");
            if ($host !== '') {
                return $host;
            }
        }
        return 'localhost';
    }

    public function getOpenSearchPort(): string
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        if ($engine !== '') {
            $port = (string)$this->scopeConfig->getValue("catalog/search/{$engine}_server_port");
            if ($port !== '') {
                return $port;
            }
        }
        return '9200';
    }

    public function getOpenSearchIndexName(): string
    {
        $indexName = (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_INDEX_NAME);
        if ($indexName === '') {
            return '';
        }
        $prefix = $this->getSearchIndexPrefix();
        if ($prefix !== '') {
            return $prefix . '_' . $indexName;
        }
        return $indexName;
    }

    private function getSearchIndexPrefix(): string
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        if ($engine !== '') {
            $prefix = (string)$this->scopeConfig->getValue("catalog/search/{$engine}_index_prefix");
            if ($prefix !== '') {
                return $prefix;
            }
        }
        return (string)$this->scopeConfig->getValue('catalog/search/elasticsearch_index_prefix');
    }

    public function getOpenSearchUsername(): string
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        if ($engine !== '') {
            $authEnabled = $this->scopeConfig->isSetFlag("catalog/search/{$engine}_enable_auth");
            if ($authEnabled) {
                return (string)$this->scopeConfig->getValue("catalog/search/{$engine}_username");
            }
        }
        return '';
    }

    public function getOpenSearchPassword(): string
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        if ($engine !== '') {
            $authEnabled = $this->scopeConfig->isSetFlag("catalog/search/{$engine}_enable_auth");
            if ($authEnabled) {
                return (string)$this->scopeConfig->getValue("catalog/search/{$engine}_password");
            }
        }
        return '';
    }

    public function getOpenSearchSearchType(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_SEARCH_TYPE) ?: 'hybrid';
    }

    public function getOpenSearchMinSimilarity(): float
    {
        $val = $this->scopeConfig->getValue(self::XML_OPENSEARCH_MIN_SIMILARITY);
        return $val !== null && $val !== '' ? (float)$val : 0.90;
    }
}
