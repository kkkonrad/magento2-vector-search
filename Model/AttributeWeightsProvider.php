<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class AttributeWeightsProvider
{
    /** @var array|null */
    private ?array $weights = null;

    /** @var array|null */
    private ?array $attributeCodes = null;

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory
    ) {}

    /**
     * Get searchable and filterable attribute weights.
     * Returns associative array like ['name' => 5, 'sku' => 6, 'description' => 1]
     *
     * @return array
     */
    public function getWeights(): array
    {
        if ($this->weights === null) {
            $this->loadAttributes();
        }
        return $this->weights;
    }

    /**
     * Get all searchable and filterable attribute codes.
     *
     * @return array
     */
    public function getAttributeCodes(): array
    {
        if ($this->attributeCodes === null) {
            $this->loadAttributes();
        }
        return $this->attributeCodes;
    }

    /**
     * Loads active catalog attributes and determines their search weights.
     */
    private function loadAttributes(): void
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter(
            ['is_searchable', 'is_filterable'],
            [
                ['eq' => 1],
                ['gt' => 0]
            ]
        );

        $weights = [];
        $codes = [];

        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();
            
            // Skip system or technical fields that we manage separately or do not index this way
            if (in_array($code, ['status', 'visibility', 'tax_class_id', 'price', 'url_key'])) {
                continue;
            }
            
            // Search weight defaults to 1 if not set or invalid
            $weight = (int)$attribute->getSearchWeight();
            if ($weight < 1) {
                $weight = 1;
            }
            
            $weights[$code] = $weight;
            $codes[] = $code;
        }

        $this->weights = $weights;
        $this->attributeCodes = array_unique($codes);
    }
}
