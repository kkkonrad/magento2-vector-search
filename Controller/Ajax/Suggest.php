<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Controller\Ajax;

use Kkkonrad\VectorSearch\Model\Cache\Type as VectorSearchCacheType;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Suggest implements HttpGetActionInterface
{
    private const MAX_QUERY_LENGTH = 80;
    private const CACHE_LIFETIME = 300;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly QueryCollectionFactory $queryCollectionFactory,
        private readonly VectorSearchService $vectorSearchService,
        private readonly CacheInterface $cache,
        private readonly CustomerSession $customerSession,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageHelper $imageHelper,
        private readonly PriceHelper $priceHelper,
        private readonly SearchHelper $searchHelper,
        private readonly Visibility $visibility,
        private readonly LoggerInterface $logger
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $query = $this->cleanQuery((string)$this->request->getParam('q', ''));

        if (mb_strlen($query) < 2) {
            return $result->setData($this->emptyPayload($query));
        }

        try {
            $cacheKey = $this->cacheKey($query);
            $cached = $this->cache->load($cacheKey);
            if ($cached !== false) {
                $payload = json_decode((string)$cached, true);
                if (is_array($payload)) {
                    return $result->setData($payload);
                }
            }

            $categories = $this->getCategories($query);
            $products = $this->getProducts($query);

            $payload = [
                'query' => $query,
                'search_url' => $this->searchUrl($query),
                'phrases' => $this->getHistoricalPhrases($query),
                'categories' => $categories,
                'products' => $products,
            ];

            $this->cache->save(
                (string)json_encode($payload),
                $cacheKey,
                [VectorSearchCacheType::CACHE_TAG],
                self::CACHE_LIFETIME
            );

            return $result->setData($payload);
        } catch (\Throwable $exception) {
            $this->logger->error('[VectorSearch] Rich suggestions failed: ' . $exception->getMessage());

            return $result->setHttpResponseCode(200)->setData($this->emptyPayload($query));
        }
    }

    /**
     * @return array<int, array{title: string, path: string, url: string, count: int|null}>
     */
    private function getCategories(string $query): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $like = '%' . addcslashes($query, '%_') . '%';
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_key', 'url_path'])
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('name', ['like' => $like])
            ->addAttributeToSort('level', 'ASC')
            ->setPageSize(3);

        $items = [];
        foreach ($collection as $category) {
            /** @var Category $category */
            $name = $this->displayText((string)$category->getName());
            if ($name === '') {
                continue;
            }

            $items[] = [
                'title' => $name,
                'path' => $this->categoryPath($category, $storeId),
                'url' => $category->getUrl(),
                'count' => null,
            ];

            if (count($items) >= 3) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{title: string, sku: string, url: string, image: string, price: string}>
     */
    private function getProducts(string $query): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $rankedIds = $this->vectorSearchService->getEntityIds($query, $storeId, [], 8, false);
        if (empty($rankedIds)) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect(['name', 'sku', 'small_image', 'thumbnail', 'price'])
            ->addIdFilter($rankedIds)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSearchIds()])
            ->setPageSize(count($rankedIds));

        if (method_exists($collection, 'addUrlRewrite')) {
            $collection->addUrlRewrite();
        }

        $productsById = [];
        foreach ($collection as $product) {
            $productsById[(int)$product->getId()] = $product;
        }

        $items = [];
        foreach ($rankedIds as $productId) {
            $productId = (int)$productId;
            if (!isset($productsById[$productId])) {
                continue;
            }

            $product = $productsById[$productId];
            $name = $this->displayText((string)$product->getName());
            $items[] = [
                'title' => $name,
                'sku' => (string)$product->getSku(),
                'url' => $product->getProductUrl(),
                'image' => $this->imageHelper->init($product, 'product_small_image')->getUrl(),
                'price' => $this->priceHelper->currency((float)$product->getFinalPrice(), true, false),
            ];

            if (count($items) >= 6) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private function getHistoricalPhrases(string $query): array
    {
        $seen = [];
        $phrases = [];
        $collection = $this->queryCollectionFactory->create();
        $collection->setStoreId((int)$this->storeManager->getStore()->getId())
            ->setQueryFilter($query)
            ->setPageSize(3);

        foreach ($collection as $item) {
            $title = $this->displayText((string)$item->getData('query_text'));
            if ($title === '') {
                continue;
            }

            $key = mb_strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }

            $phrases[] = [
                'title' => $title,
                'url' => $this->searchUrl($title),
            ];
            $seen[$key] = true;

            if (count($phrases) >= 3) {
                break;
            }
        }

        return $phrases;
    }

    private function displayText(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function categoryPath(Category $category, int $storeId): string
    {
        $names = [];
        $pathIds = array_map('intval', $category->getPathIds());

        foreach ($pathIds as $pathId) {
            try {
                $pathCategory = (int)$category->getId() === $pathId
                    ? $category
                    : $this->categoryRepository->get($pathId, $storeId);
            } catch (\Throwable) {
                continue;
            }

            if ((int)$pathCategory->getLevel() <= 1) {
                continue;
            }

            $name = $this->displayText((string)$pathCategory->getName());
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(' / ', $names);
    }

    private function cleanQuery(string $query): string
    {
        $query = trim(preg_replace('/\s+/u', ' ', strip_tags($query)) ?? '');

        return mb_substr($query, 0, self::MAX_QUERY_LENGTH);
    }

    private function searchUrl(string $query): string
    {
        return $this->searchHelper->getResultUrl($query);
    }

    private function cacheKey(string $query): string
    {
        $store = $this->storeManager->getStore();
        $parts = [
            'vectorsearch_rich_suggest',
            (int)$store->getId(),
            (string)$store->getCurrentCurrencyCode(),
            (int)$this->customerSession->getCustomerGroupId(),
            mb_strtolower($query),
        ];

        return 'vectorsearch_rich_suggest_' . md5(implode('|', $parts));
    }

    /**
     * @return array{query: string, search_url: string, phrases: array, categories: array, products: array}
     */
    private function emptyPayload(string $query): array
    {
        return [
            'query' => $query,
            'search_url' => $query !== '' ? $this->searchUrl($query) : '',
            'phrases' => [],
            'categories' => [],
            'products' => [],
        ];
    }
}
