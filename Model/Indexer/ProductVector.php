<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;

class ProductVector implements ActionInterface, MviewActionInterface
{
    private const BATCH_SIZE = 50;

    /** @var string[]|null */
    private ?array $searchableAttributeCodes = null;

    public function __construct(
        private readonly CollectionFactory    $collectionFactory,
        private readonly EmbeddingClient      $embeddingClient,
        private readonly OpenSearchClient     $openSearchClient,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface      $logger,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {}

    /**
     * Full reindex — rebuilds the entire OpenSearch kNN index.
     */
    public function executeFull(): void
    {
        $this->logger->info('[VectorSearch] Starting full reindex...');
        $this->openSearchClient->ensureIndex();

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $this->logger->info("[VectorSearch] Indexing store {$storeId}");
            $this->indexStore($storeId);
        }

        $this->logger->info('[VectorSearch] Full reindex complete.');
    }

    /**
     * Partial reindex — called by Magento indexer for specific IDs.
     */
    public function executeList(array $ids): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->indexStore((int)$store->getId(), $ids);
        }
    }

    /**
     * Mview incremental update.
     */
    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }

    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }

    // -------------------------------------------------------------------------

    private function indexStore(int $storeId, array $ids = []): void
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);

        $searchableCodes = $this->getSearchableAttributeCodes();
        $selectFields = array_merge(
            ['name', 'description', 'short_description', 'sku', 'status', 'visibility'],
            $searchableCodes
        );
        $collection->addAttributeToSelect(array_unique($selectFields));

        $collection->addAttributeToFilter('status', ['eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', ['in' => [
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_SEARCH,
            \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
        ]]);

        if (!empty($ids)) {
            $collection->addFieldToFilter('entity_id', ['in' => $ids]);
        }

        $collection->setPageSize(self::BATCH_SIZE);
        $pages = $collection->getLastPageNumber();

        $processedIds = [];

        for ($page = 1; $page <= $pages; $page++) {
            $collection->setCurPage($page);
            $collection->clear();

            $batch = [];
            /** @var ProductInterface $product */
            foreach ($collection as $product) {
                $batch[] = $product;
                $processedIds[] = (int)$product->getId();
            }

            $this->processBatch($batch, $storeId);
        }

        // Delete any IDs that were requested but not indexed (disabled, deleted, or not visible in search)
        if (!empty($ids)) {
            $skippedIds = array_diff(array_map('intval', $ids), $processedIds);
            foreach ($skippedIds as $deleteId) {
                $this->openSearchClient->deleteProduct($deleteId);
                $this->logger->info("[VectorSearch] Deleted product {$deleteId} from index (disabled or not visible in search).");
            }
        }
    }

    /**
     * @param ProductInterface[] $products
     */
    private function processBatch(array $products, int $storeId): void
    {
        if (empty($products)) {
            return;
        }

        $searchableCodes = $this->getSearchableAttributeCodes();

        $texts = [];
        $attributeTexts = [];
        foreach ($products as $idx => $product) {
            $name        = (string)$product->getName();
            $description = strip_tags((string)($product->getData('description') ?: $product->getData('short_description') ?: ''));

            // Extract values of all searchable/filterable attributes
            $attrText = $this->getProductAttributesText($product, $searchableCodes);
            $attributeTexts[$idx] = $attrText;

            // combine name (repeated 2x for weight) + attributes + description
            $texts[] = trim("{$name} {$name} {$attrText} {$description}");
        }

        try {
            $embeddings = $this->embeddingClient->embed($texts, 'passage');
        } catch (\RuntimeException $e) {
            $this->logger->error('[VectorSearch] Skipping batch due to embedding error: ' . $e->getMessage());
            return;
        }

        $docs = [];
        foreach ($products as $i => $product) {
            if (!isset($embeddings[$i])) {
                continue;
            }
            $attrText = $attributeTexts[$i] ?? '';
            $desc = strip_tags((string)$product->getData('description'));
            $docs[] = [
                'entity_id'   => (int)$product->getId(),
                'sku'         => (string)$product->getSku(),
                'store_id'    => $storeId,
                'name'        => (string)$product->getName(),
                'description' => trim($attrText . ' ' . $desc),
                'status'      => (int)$product->getStatus(),
                'visibility'  => (int)$product->getVisibility(),
                'embedding'   => $embeddings[$i],
            ];
        }

        $this->openSearchClient->bulk($docs);
        $this->logger->info('[VectorSearch] Indexed ' . count($docs) . " products for store {$storeId}.");
    }

    /**
     * Get all searchable and filterable attributes configured in Magento.
     *
     * @return string[]
     */
    private function getSearchableAttributeCodes(): array
    {
        if ($this->searchableAttributeCodes === null) {
            $collection = $this->attributeCollectionFactory->create();
            $collection->addFieldToFilter(
                ['is_searchable', 'is_filterable'],
                [
                    ['eq' => 1],
                    ['gt' => 0]
                ]
            );

            $codes = [];
            foreach ($collection as $attribute) {
                $code = $attribute->getAttributeCode();
                if (in_array($code, ['status', 'visibility', 'tax_class_id', 'price', 'url_key', 'name', 'description', 'short_description', 'sku'])) {
                    continue;
                }
                $codes[] = $code;
            }
            $this->searchableAttributeCodes = array_unique($codes);
        }
        return $this->searchableAttributeCodes;
    }

    /**
     * Extracts all searchable/filterable attribute values from the product and its children.
     *
     * @param ProductInterface $product
     * @param string[] $attributeCodes
     * @return string
     */
    private function getProductAttributesText(ProductInterface $product, array $attributeCodes): string
    {
        $lines = [];
        foreach ($attributeCodes as $code) {
            $attribute = $product->getResource()->getAttribute($code);
            if (!$attribute) {
                continue;
            }
            $label = $attribute->getStoreLabel();
            if (empty($label)) {
                $label = $attribute->getFrontendLabel();
            }
            if (empty($label)) {
                $label = ucfirst(str_replace('_', ' ', $code));
            }

            $values = [];
            // Check parent
            $val = $product->getAttributeText($code);
            if (empty($val)) {
                $val = $product->getData($code);
            }
            if ($val !== null && $val !== '') {
                if (is_array($val)) {
                    $values = $val;
                } else {
                    $values[] = (string)$val;
                }
            }

            // Check children
            if ($product->getTypeId() === 'configurable') {
                $children = $product->getTypeInstance()->getUsedProducts($product);
                foreach ($children as $child) {
                    $val = $attribute->getSource()->getOptionText($child->getData($code));
                    if (empty($val)) {
                        $val = $child->getData($code);
                    }
                    if ($val !== null && $val !== '') {
                        if (is_array($val)) {
                            $values = array_merge($values, $val);
                        } else {
                            $values[] = (string)$val;
                        }
                    }
                }
            }

            $values = array_unique(array_filter($values));
            if (!empty($values)) {
                $lines[] = "{$label}: " . implode(', ', $values) . '.';
            }
        }
        return implode(' ', $lines);
    }
}
