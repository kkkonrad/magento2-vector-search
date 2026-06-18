<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Provides searchable/filterable product attributes together with their
 * Magento "Search Weight" value (catalog_eav_attribute.search_weight, 1–10).
 *
 * Results are cached in-process for the lifetime of the request.
 */
class AttributeWeightProvider
{
    /**
     * Attributes whose values are handled by dedicated OpenSearch fields
     * (name, description) or that are irrelevant for full-text search.
     */
    private const EXCLUDED_CODES = [
        'status', 'visibility', 'tax_class_id', 'price', 'url_key',
        'name', 'description', 'short_description', 'sku',
    ];

    /**
     * In-process cache: attribute_code → search_weight (int ≥ 1).
     *
     * @var array<string, int>|null
     */
    private ?array $cache = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface    $logger
    ) {}

    /**
     * Returns an associative array of attribute_code → search_weight for all
     * searchable or filterable catalog product attributes, excluding the core
     * fields handled separately.
     *
     * @return array<string, int>  e.g. ['color' => 5, 'material' => 1, ...]
     */
    public function getWeightedAttributes(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $conn = $this->resource->getConnection();

            $excluded = implode(
                ', ',
                array_map(static fn(string $c): string => $conn->quote($c), self::EXCLUDED_CODES)
            );

            $rows = $conn->fetchAll("
                SELECT a.attribute_code,
                       COALESCE(NULLIF(ca.search_weight, 0), 1) AS search_weight
                FROM   eav_attribute        AS a
                JOIN   catalog_eav_attribute AS ca ON ca.attribute_id = a.attribute_id
                WHERE  a.entity_type_id = 4
                  AND  (ca.is_searchable = 1 OR ca.is_filterable > 0)
                  AND  a.attribute_code NOT IN ({$excluded})
                ORDER  BY a.attribute_code
            ");

            $result = [];
            foreach ($rows as $row) {
                $result[(string)$row['attribute_code']] = (int)$row['search_weight'];
            }

            $this->cache = $result;
        } catch (\Throwable $e) {
            $this->logger->error('[VectorSearch] AttributeWeightProvider error: ' . $e->getMessage());
            $this->cache = [];
        }

        return $this->cache;
    }

    /**
     * Returns the OpenSearch field name for a given attribute code.
     * Prefix "attr_" avoids collisions with core document fields.
     */
    public static function fieldName(string $attributeCode): string
    {
        return 'attr_' . $attributeCode;
    }
}
