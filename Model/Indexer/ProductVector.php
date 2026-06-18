<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;
use Kkkonrad\VectorSearch\Model\AttributeWeightsProvider;

class ProductVector implements ActionInterface, MviewActionInterface
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly CollectionFactory         $collectionFactory,
        private readonly EmbeddingClient           $embeddingClient,
        private readonly OpenSearchClient          $openSearchClient,
        private readonly StoreManagerInterface     $storeManager,
        private readonly LoggerInterface           $logger,
        private readonly AttributeWeightsProvider  $attributeWeightsProvider
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

        $searchableCodes = $this->attributeWeightsProvider->getAttributeCodes();
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

        $attributeCodes = $this->attributeWeightsProvider->getAttributeCodes();
        $weights = $this->attributeWeightsProvider->getWeights();

        $texts = [];
        foreach ($products as $product) {
            $parts = [];
            foreach ($attributeCodes as $code) {
                if ($code === 'sku') {
                    $val = (string)$product->getSku();
                } elseif ($code === 'name') {
                    $val = (string)$product->getName();
                } elseif ($code === 'description') {
                    $val = strip_tags((string)$product->getData('description'));
                } elseif ($code === 'short_description') {
                    $val = strip_tags((string)$product->getData('short_description'));
                } else {
                    $val = $this->getAttributeValue($product, $code);
                }

                if ($val === null || $val === '') {
                    continue;
                }

                $weight = $weights[$code] ?? 1;
                // Formulate the part for semantic search
                if (in_array($code, ['name', 'description', 'short_description', 'sku'])) {
                    $formattedPart = $val;
                } else {
                    $label = $this->getAttributeLabel($product, $code);
                    $formattedPart = "{$label}: {$val}.";
                }

                for ($w = 0; $w < $weight; $w++) {
                    $parts[] = $formattedPart;
                }
            }
            $texts[] = trim(implode(' ', $parts));
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

            $doc = [
                'entity_id'  => (int)$product->getId(),
                'store_id'   => $storeId,
                'status'     => (int)$product->getStatus(),
                'visibility' => (int)$product->getVisibility(),
                'embedding'  => $embeddings[$i],
            ];

            foreach ($attributeCodes as $code) {
                if ($code === 'sku') {
                    $doc['sku'] = (string)$product->getSku();
                } elseif ($code === 'name') {
                    $doc['name'] = (string)$product->getName();
                } elseif ($code === 'description') {
                    $doc['description'] = strip_tags((string)$product->getData('description'));
                } elseif ($code === 'short_description') {
                    $doc['short_description'] = strip_tags((string)$product->getData('short_description'));
                } else {
                    $doc[$code] = $this->getAttributeValue($product, $code);
                }
            }
            $docs[] = $doc;
        }

        $this->openSearchClient->bulk($docs);
        $this->logger->info('[VectorSearch] Indexed ' . count($docs) . " products for store {$storeId}.");
    }

    /**
     * Extracts all searchable/filterable attribute values from the product and its children.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return string
     */
    private function getAttributeValue(ProductInterface $product, string $code): string
    {
        $attribute = $product->getResource()->getAttribute($code);
        if (!$attribute) {
            return '';
        }

        $values = [];

        // Check main product value
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

        // Check configurable children products
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
        return implode(', ', $values);
    }

    /**
     * Retrieves Frontend label for an attribute.
     *
     * @param ProductInterface $product
     * @param string $code
     * @return string
     */
    private function getAttributeLabel(ProductInterface $product, string $code): string
    {
        $attribute = $product->getResource()->getAttribute($code);
        if (!$attribute) {
            return ucfirst(str_replace('_', ' ', $code));
        }
        $label = $attribute->getStoreLabel();
        if (empty($label)) {
            $label = $attribute->getFrontendLabel();
        }
        if (empty($label)) {
            $label = ucfirst(str_replace('_', ' ', $code));
        }
        return $label;
    }
}
