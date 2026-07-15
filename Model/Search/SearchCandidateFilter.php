<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Search;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Applies commerce constraints that are deliberately not stored in the vector index. */
class SearchCandidateFilter
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly AreProductsSalableInterface $areProductsSalable
    ) {}

    /**
     * @param int[] $rankedIds
     * @param array<string, mixed> $requestParams
     * @return int[]
     */
    public function filter(array $rankedIds, int $storeId, array $requestParams): array
    {
        $rankedIds = array_values(array_unique(array_filter(array_map('intval', $rankedIds))));
        if ($rankedIds === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addIdFilter($rankedIds)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSearchIds()]);

        $priceRange = $this->parsePriceRange($requestParams['price'] ?? null);
        if ($priceRange !== null) {
            $collection->addPriceData();
            if ($priceRange['from'] !== null) {
                $collection->getSelect()->where('price_index.final_price >= ?', $priceRange['from']);
            }
            if ($priceRange['to'] !== null) {
                $collection->getSelect()->where('price_index.final_price <= ?', $priceRange['to']);
            }
        }

        $showOutOfStock = $this->scopeConfig->isSetFlag(
            'cataloginventory/options/show_out_of_stock',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!$showOutOfStock) {
            $skusById = [];
            foreach ($collection as $product) {
                $skusById[(int)$product->getId()] = (string)$product->getSku();
            }
            $websiteCode = (string)$this->storeManager->getStore($storeId)->getWebsite()->getCode();
            $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
            $salableSkus = [];
            foreach ($this->areProductsSalable->execute(array_values($skusById), (int)$stock->getStockId()) as $result) {
                if ($result->isSalable()) {
                    $salableSkus[(string)$result->getSku()] = true;
                }
            }
            $allowed = [];
            foreach ($skusById as $id => $sku) {
                if (isset($salableSkus[$sku])) {
                    $allowed[$id] = true;
                }
            }
        } else {
            $allowed = array_fill_keys(array_map('intval', $collection->getAllIds()), true);
        }
        return array_values(array_filter(
            $rankedIds,
            static fn(int $id): bool => isset($allowed[$id])
        ));
    }

    /** @return array{from:float|null,to:float|null}|null */
    private function parsePriceRange(mixed $value): ?array
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)?-(\d+(?:\.\d+)?)?$/', $value, $matches)) {
            return null;
        }
        $from = isset($matches[1]) && $matches[1] !== '' ? (float)$matches[1] : null;
        $to = isset($matches[2]) && $matches[2] !== '' ? (float)$matches[2] : null;
        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }
}
