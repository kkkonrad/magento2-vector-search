<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_OPENSEARCH_EMBEDDING_SERVICE_URL = 'vectorsearch/embedding/service_url';
    private const XML_EMBEDDING_SERVICE_API_KEY = 'vectorsearch/embedding/api_key';
    private const XML_OPENSEARCH_INDEX_NAME = 'vectorsearch/opensearch/index_name';
    private const XML_OPENSEARCH_SEARCH_TYPE = 'vectorsearch/opensearch/search_type';

    private const XML_OPENSEARCH_MIN_SIMILARITY = 'vectorsearch/opensearch/min_similarity';

    private const XML_OPENSEARCH_COMBINATION_TECHNIQUE = 'vectorsearch/opensearch/hybrid_combination_technique';
    private const XML_OPENSEARCH_NORMALIZATION_TECHNIQUE = 'vectorsearch/opensearch/hybrid_normalization_technique';
    private const XML_OPENSEARCH_LEXICAL_WEIGHT = 'vectorsearch/opensearch/lexical_weight';
    private const XML_OPENSEARCH_KNN_WEIGHT = 'vectorsearch/opensearch/knn_weight';
    private const XML_OPENSEARCH_SEARCH_LIMIT = 'vectorsearch/opensearch/search_limit';

    private const XML_RERANKING_ENABLED = 'vectorsearch/reranking/enabled';
    private const XML_RERANKING_LIMIT = 'vectorsearch/reranking/limit';
    private const XML_RERANKING_MIN_SCORE = 'vectorsearch/reranking/min_score';
    private const XML_RERANKING_TIMEOUT_MS = 'vectorsearch/reranking/timeout_ms';
    private const XML_RERANKING_CIRCUIT_FAILURE_THRESHOLD = 'vectorsearch/reranking/circuit_failure_threshold';
    private const XML_RERANKING_CIRCUIT_COOLDOWN_SECONDS = 'vectorsearch/reranking/circuit_cooldown_seconds';
    private const XML_DIAGNOSTICS_ENABLED = 'vectorsearch/diagnostics/enabled';
    private const XML_DIAGNOSTICS_TOKEN = 'vectorsearch/diagnostics/token';
    private const XML_METRICS_ENABLED = 'vectorsearch/metrics/enabled';
    private const XML_PRODUCT_INTENT_RULES = 'vectorsearch/product_intent/rules';
    private const XML_REGRESSION_RULES = 'vectorsearch/regression/rules';
    private const XML_QUERY_SYNONYM_RULES = 'vectorsearch/query_normalization/synonym_rules';
    private const XML_QUERY_STOP_WORDS = 'vectorsearch/query_normalization/stop_words';
    private const XML_ATTRIBUTE_INTENT_RULES = 'vectorsearch/attribute_intent/rules';
    private const XML_ATTRIBUTE_INTENT_ALIASES = 'vectorsearch/attribute_intent/aliases';
    private const XML_ATTRIBUTE_INTENT_MODES = 'vectorsearch/attribute_intent/modes';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function getEmbeddingServiceUrl(): string
    {
        return rtrim(
            (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_EMBEDDING_SERVICE_URL),
            '/'
        );
    }

    public function getEmbeddingServiceApiKey(): string
    {
        $value = trim((string)$this->scopeConfig->getValue(self::XML_EMBEDDING_SERVICE_API_KEY));
        return $value === '' ? '' : $this->encryptor->decrypt($value);
    }

    public function getOpenSearchNormalizationTechnique(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_NORMALIZATION_TECHNIQUE) ?: 'min_max';
    }

    public function getOpenSearchCombinationTechnique(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_COMBINATION_TECHNIQUE) ?: 'rrf';
    }

    public function getOpenSearchLexicalWeight(): float
    {
        $val = $this->scopeConfig->getValue(self::XML_OPENSEARCH_LEXICAL_WEIGHT);
        return $val !== null && $val !== '' ? (float)$val : 0.7;
    }

    public function getOpenSearchKnnWeight(): float
    {
        $val = $this->scopeConfig->getValue(self::XML_OPENSEARCH_KNN_WEIGHT);
        return $val !== null && $val !== '' ? (float)$val : 0.3;
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

    public function getOpenSearchSearchLimit(): int
    {
        $val = $this->scopeConfig->getValue(self::XML_OPENSEARCH_SEARCH_LIMIT);
        return $val !== null && $val !== '' ? (int)$val : 100;
    }

    public function isRerankingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_RERANKING_ENABLED);
    }


    public function getRerankingLimit(): int
    {
        $val = $this->scopeConfig->getValue(self::XML_RERANKING_LIMIT);
        return $val !== null && $val !== '' ? (int)$val : 20;
    }

    /**
     * Absolute minimum reranker score a product must reach to remain in the priority result set.
     * Products that score below this floor (and below the dynamic 45% threshold) are demoted to
     * the tail of the results instead of appearing on page 1.
     * Default 0.0 means only the dynamic threshold applies.
     */
    public function getRerankingMinScore(): float
    {
        $val = $this->scopeConfig->getValue(self::XML_RERANKING_MIN_SCORE);
        return $val !== null && $val !== '' ? (float)$val : 0.0;
    }

    public function getRerankingTimeoutMs(): int
    {
        $val = $this->scopeConfig->getValue(self::XML_RERANKING_TIMEOUT_MS);
        return max(100, $val !== null && $val !== '' ? (int)$val : 5000);
    }

    public function getRerankingCircuitFailureThreshold(): int
    {
        $val = $this->scopeConfig->getValue(self::XML_RERANKING_CIRCUIT_FAILURE_THRESHOLD);
        return max(1, $val !== null && $val !== '' ? (int)$val : 3);
    }

    public function getRerankingCircuitCooldownSeconds(): int
    {
        $val = $this->scopeConfig->getValue(self::XML_RERANKING_CIRCUIT_COOLDOWN_SECONDS);
        return max(1, $val !== null && $val !== '' ? (int)$val : 60);
    }

    public function isDiagnosticsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_DIAGNOSTICS_ENABLED);
    }

    public function getDiagnosticsToken(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_DIAGNOSTICS_TOKEN));
    }

    public function isMetricsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_METRICS_ENABLED);
    }

    public function getProductIntentRules(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PRODUCT_INTENT_RULES));
    }

    public function getRegressionRules(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_REGRESSION_RULES));
    }

    public function getQuerySynonymRules(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_QUERY_SYNONYM_RULES));
    }

    public function getQueryStopWords(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_QUERY_STOP_WORDS));
    }

    public function getAttributeIntentRules(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_ATTRIBUTE_INTENT_RULES));
    }

    public function getAttributeIntentAliases(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_ATTRIBUTE_INTENT_ALIASES));
    }

    public function getAttributeIntentModes(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_ATTRIBUTE_INTENT_MODES));
    }

    /**
     * Changes to ranking configuration must immediately produce a different result-cache key.
     */
    public function getSearchConfigFingerprint(): string
    {
        $values = [
            $this->getOpenSearchSearchType(),
            $this->getOpenSearchCombinationTechnique(),
            $this->getOpenSearchNormalizationTechnique(),
            $this->getOpenSearchLexicalWeight(),
            $this->getOpenSearchKnnWeight(),
            $this->getOpenSearchMinSimilarity(),
            $this->getOpenSearchSearchLimit(),
            $this->isRerankingEnabled(),
            $this->getRerankingLimit(),
            $this->getRerankingMinScore(),
            $this->getProductIntentRules(),
            $this->getQuerySynonymRules(),
            $this->getQueryStopWords(),
            $this->getAttributeIntentRules(),
            $this->getAttributeIntentAliases(),
            $this->getAttributeIntentModes(),
        ];

        return sha1((string)json_encode($values, JSON_UNESCAPED_UNICODE));
    }
}
