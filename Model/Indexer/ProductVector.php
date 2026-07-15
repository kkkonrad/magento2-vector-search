<?php
declare(strict_types=1);

namespace Kkkonrad\VectorSearch\Model\Indexer;

use Magento\CatalogSearch\Model\Indexer\Fulltext\Action\DataProvider;
use Magento\Elasticsearch\Model\Adapter\BatchDataMapper\ProductDataMapper;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Kkkonrad\VectorSearch\Model\EmbeddingClient;
use Kkkonrad\VectorSearch\Model\OpenSearch\Client as OpenSearchClient;
use Kkkonrad\VectorSearch\Model\AttributeWeightProvider;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ObjectManager;
use Kkkonrad\VectorSearch\Model\Search\PolishStemmer;
use Kkkonrad\VectorSearch\Model\Search\VectorSearchService;
use Kkkonrad\VectorSearch\Model\Cache\Type as VectorSearchCacheType;

/**
 * Vector search product indexer.
 *
 * Uses Magento's native DataProvider (the same pipeline as the standard ES indexer)
 * to ensure embeddings are generated from identical data — including configurable
 * variant names, option labels, store-specific values and child product attributes.
 */
class ProductVector implements ActionInterface, MviewActionInterface
{
    /** Number of products fetched from the DB per SQL batch. */
    private const BATCH_SIZE = 500;

    /**
     * Attribute backend types used to fetch dynamic (EAV) attributes.
     * Mirrors the list in Magento\CatalogSearch\Model\Indexer\Fulltext\Action\Full::rebuildStoreIndex().
     */
    private const DYNAMIC_FIELD_TYPES = ['int', 'varchar', 'text', 'decimal', 'datetime'];

    /**
     * Cache of category names and paths for the currently indexed store.
     * @var array<int, array{name: string, path: string}>|null
     */
    private ?array $categoryMap = null;
    private int $indexedDocumentCount = 0;

    public function __construct(
        private readonly DataProvider            $dataProvider,
        private readonly ProductDataMapper       $productDataMapper,
        private readonly EmbeddingClient         $embeddingClient,
        private readonly OpenSearchClient        $openSearchClient,
        private readonly StoreManagerInterface   $storeManager,
        private readonly LoggerInterface         $logger,
        private readonly AttributeWeightProvider $weightProvider,
        private readonly ResourceConnection     $resource,
        private readonly PolishStemmer           $stemmer,
        private readonly ?CacheInterface         $cache = null,
        private readonly ?VectorSearchService    $vectorSearchService = null
    ) {}

    // -------------------------------------------------------------------------
    // ActionInterface / MviewActionInterface
    // -------------------------------------------------------------------------

    public function executeFull(): void
    {
        $startedAt = microtime(true);
        $this->logger->info('[VectorSearch] Starting full reindex...');
        $this->indexedDocumentCount = 0;
        try {
            // Full rebuild writes to a versioned index. The live alias is switched only after success.
            $this->openSearchClient->ensureIndex(true);

            foreach ($this->storeManager->getStores() as $store) {
                $storeId = (int)$store->getId();
                $this->logger->info("[VectorSearch] Indexing store {$storeId}");
                $this->indexStore($storeId);
            }

            if ($this->indexedDocumentCount <= 0) {
                throw new \RuntimeException('Full vector reindex produced no documents; refusing to activate it.');
            }
            $this->openSearchClient->activateRebuiltIndex($this->indexedDocumentCount);
        } catch (\Throwable $exception) {
            $this->openSearchClient->abortRebuiltIndex();
            throw $exception;
        }

        $this->cleanSearchCache();
        $elapsed = microtime(true) - $startedAt;
        $rate = $elapsed > 0.0 ? $this->indexedDocumentCount / $elapsed : 0.0;
        $this->logger->info(sprintf(
            '[VectorSearch] Full reindex complete: %d documents in %.2f s (%.1f docs/s).',
            $this->indexedDocumentCount,
            $elapsed,
            $rate
        ));
    }

    public function executeList(array $ids): void
    {
        $ids = $this->expandRelatedProductIds($ids);
        if ($ids === []) {
            return;
        }
        // Partial reindex: ensure index exists, but do not drop it if it's already there
        $this->openSearchClient->ensureIndex(false);

        foreach ($this->storeManager->getStores() as $store) {
            $this->indexStore((int)$store->getId(), $ids);
        }

        $this->cleanSearchCache();
    }

    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }

    public function executeRow($id): void
    {
        $this->executeList([$id]);
    }

    /**
     * Child attribute changes affect the searchable document of their composite parent.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function expandRelatedProductIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($connection->getTableName('catalog_product_relation'), ['parent_id'])
                ->where('child_id IN (?)', $ids);
            $parentIds = array_map('intval', $connection->fetchCol($select));
            return array_values(array_unique(array_merge($ids, $parentIds)));
        } catch (\Throwable $exception) {
            $this->logger->error('[VectorSearch] Could not resolve composite parents: ' . $exception->getMessage());
            throw new \RuntimeException('Could not resolve products affected by partial vector reindex.', 0, $exception);
        }
    }

    // -------------------------------------------------------------------------
    // Core indexing logic — mirrors Full::rebuildStoreIndex()
    // -------------------------------------------------------------------------

    private function indexStore(int $storeId, array $productIds = []): void
    {
        $startedAt = microtime(true);
        $documentsBefore = $this->indexedDocumentCount;
        $this->loadCategoryMap($storeId);

        // Build the list of static (flat-table) attribute codes.
        $staticFields = [];
        foreach ($this->dataProvider->getSearchableAttributes('static') as $attribute) {
            $staticFields[] = $attribute->getAttributeCode();
        }

        // Build the lists of dynamic (EAV) attribute IDs, grouped by backend type.
        $dynamicFields = [];
        foreach (self::DYNAMIC_FIELD_TYPES as $type) {
            $dynamicFields[$type] = array_keys($this->dataProvider->getSearchableAttributes($type));
        }

        $lastProductId  = 0;
        $requestedIds   = !empty($productIds) ? array_map('intval', $productIds) : null;
        $processedIds   = [];

        $products = $this->dataProvider->getSearchableProducts(
            $storeId, $staticFields, $requestedIds, $lastProductId, self::BATCH_SIZE
        );

        while (count($products) > 0) {
            // Collect parent + child IDs for this batch.
            $batchParentIds = array_column($products, 'entity_id');
            $childrenMap    = $this->buildChildrenMap($products);
            $allIds         = array_unique(array_merge($batchParentIds, array_values(array_merge(...array_values($childrenMap) ?: [[]]))));

            // Load all EAV attribute values in a single SQL query.
            $productsAttributes = $this->dataProvider->getProductAttributes($storeId, $allIds, $dynamicFields);

            $preparedIndices = [];
            $validProducts   = [];

            foreach ($products as $productData) {
                $parentId      = (int)$productData['entity_id'];
                $lastProductId = $parentId;

                // Build the productIndex: parent attributes + enabled children attributes.
                $productIndex = [];
                if (isset($productsAttributes[$parentId])) {
                    $productIndex[$parentId] = $productsAttributes[$parentId];
                }
                if (isset($childrenMap[$parentId])) {
                    foreach ($childrenMap[$parentId] as $childId) {
                        if (isset($productsAttributes[$childId])) {
                            $productIndex[$childId] = $productsAttributes[$childId];
                        }
                    }
                }

                if (empty($productIndex)) {
                    continue;
                }

                // prepareProductIndex() merges parent+children, resolves option labels,
                // and returns the same index array that Magento passes to ES.
                $preparedIndices[$parentId] = $this->dataProvider->prepareProductIndex($productIndex, $productData, $storeId);
                $validProducts[$parentId]   = $productData;
                $processedIds[]             = $parentId;
            }

            if (!empty($preparedIndices)) {
                // ProductDataMapper::map() converts the raw indices to human-readable ES documents
                // with {code}_value fields (e.g. color_value: "Pomarańczowy"). Batch operation is O(1) overhead.
                $mappedDocs = $this->productDataMapper->map($preparedIndices, $storeId);

                $batch = [];
                foreach ($validProducts as $parentId => $productData) {
                    $batch[] = [
                        'entity_id'    => $parentId,
                        'product_data' => $productData,
                        'doc'          => $mappedDocs[$parentId] ?? [],
                    ];
                }

                $this->processBatch($batch, $storeId);
            }

            $products = $this->dataProvider->getSearchableProducts(
                $storeId, $staticFields, $requestedIds, $lastProductId, self::BATCH_SIZE
            );
        }

        // If a partial reindex was requested, delete any IDs that were not indexed
        // (disabled, not visible in search, or deleted products).
        if (!empty($productIds)) {
            $skipped = array_diff(array_map('intval', $productIds), $processedIds);
            foreach ($skipped as $deleteId) {
                $this->openSearchClient->deleteProduct($deleteId, $storeId);
                $this->logger->info("[VectorSearch] Deleted product {$deleteId} from index.");
            }
        }

        $documentCount = $this->indexedDocumentCount - $documentsBefore;
        $this->logger->info(sprintf(
            '[VectorSearch] Store %d complete: %d documents in %.2f s.',
            $storeId,
            $documentCount,
            microtime(true) - $startedAt
        ));
    }

    // -------------------------------------------------------------------------
    // Batch: embed + bulk index
    // -------------------------------------------------------------------------

    /**
     * @param array[] $batch  Each element: ['entity_id' => int, 'product_data' => array, 'doc' => array]
     */
    private function processBatch(array $batch, int $storeId): void
    {
        if (empty($batch)) {
            return;
        }

        $batchStartedAt = microtime(true);
        $stageStartedAt = $batchStartedAt;

        // Bulk load category IDs for all products in this batch from DB
        $productIds = array_column($batch, 'entity_id');
        $batchCategoryIds = [];
        try {
            $conn = $this->resource->getConnection();
            $select = $conn->select()
                ->from($conn->getTableName('catalog_category_product'), ['product_id', 'category_id'])
                ->where('product_id IN (?)', $productIds);
            $rows = $conn->fetchAll($select);
            foreach ($rows as $row) {
                $pId = (int)$row['product_id'];
                $cId = (int)$row['category_id'];
                $batchCategoryIds[$pId][] = $cId;
            }
        } catch (\Throwable $e) {
            $this->logger->error('[VectorSearch] Error loading batch category IDs: ' . $e->getMessage());
        }
        $categoryMs = (microtime(true) - $stageStartedAt) * 1000;

        // Build embedding texts from the mapped ES documents.
        $stageStartedAt = microtime(true);
        $texts = [];
        $hashes = [];
        $modelName = $this->embeddingClient->getModelName();
        foreach ($batch as $item) {
            $doc = $item['doc'];
            $entityId = (int)$item['entity_id'];
            $categoryIds = $batchCategoryIds[$entityId] ?? [];
            $categoryNames = $this->getProductCategoryNames($categoryIds);
            $text = $this->buildEmbeddingText($doc, $item['product_data'], $categoryNames);
            $texts[] = $text;
            $hashes[] = hash('sha256', $modelName . ':' . $text);
        }
        $textMs = (microtime(true) - $stageStartedAt) * 1000;

        // Fetch existing hashes and embeddings from OpenSearch
        $stageStartedAt = microtime(true);
        $existingHashes = [];
        $existingEmbeddings = [];
        try {
            $entityIds = array_column($batch, 'entity_id');
            $response = $this->openSearchClient->getDocsForHashCheck($entityIds, $storeId);
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $source = $hit['_source'] ?? [];
                $id = (int)($source['entity_id'] ?? 0);
                if ($id > 0 && isset($source['embedding_text_hash']) && isset($source['embedding'])) {
                    $existingHashes[$id] = $source['embedding_text_hash'];
                    $existingEmbeddings[$id] = $source['embedding'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[VectorSearch] Hash check failed, generating all embeddings: ' . $e->getMessage());
        }
        $hashLookupMs = (microtime(true) - $stageStartedAt) * 1000;

        // Check which texts actually need embedding
        $textsToEmbed = [];
        $embedIndexMap = []; // maps index in $textsToEmbed to index in $batch
        $embeddings = [];
        $skippedCount = 0;

        foreach ($batch as $i => $item) {
            $entityId = (int)$item['entity_id'];
            $hash = $hashes[$i];
            if (isset($existingHashes[$entityId]) && $existingHashes[$entityId] === $hash) {
                $embeddings[$i] = $existingEmbeddings[$entityId];
                $skippedCount++;
            } else {
                $textsToEmbed[] = $texts[$i];
                $embedIndexMap[count($textsToEmbed) - 1] = $i;
            }
        }

        $stageStartedAt = microtime(true);
        if (!empty($textsToEmbed)) {
            try {
                $newEmbeddings = $this->embeddingClient->embed($textsToEmbed, 'passage');
                foreach ($newEmbeddings as $newI => $emb) {
                    $origI = $embedIndexMap[$newI];
                    $embeddings[$origI] = $emb;
                }
            } catch (\RuntimeException $e) {
                $this->logger->error('[VectorSearch] Batch embedding failed: ' . $e->getMessage());
                throw $e;
            }
        }
        $embeddingMs = (microtime(true) - $stageStartedAt) * 1000;

        if ($skippedCount > 0) {
            $this->logger->info("[VectorSearch] Reused {$skippedCount} existing embeddings from OpenSearch (hash matched).");
        }

        $stageStartedAt = microtime(true);
        $weightedAttrCodes = array_keys($this->weightProvider->getWeightedAttributes());

        $docs = [];
        foreach ($batch as $i => $item) {
            if (!isset($embeddings[$i])) {
                continue;
            }

            $entityId    = $item['entity_id'];
            $productData = $item['product_data'];
            $doc         = $item['doc'];

            $categoryIds = $batchCategoryIds[$entityId] ?? [];
            $categoryNames = $this->getProductCategoryNames($categoryIds);

            // Build per-attribute OpenSearch fields from the *_value entries in the
            // mapped document. These enable per-field boosting based on search_weight.
            // Note: ProductDataMapper may return *_value as an array (multiple variant values).
            $perAttrFields = [];
            foreach ($weightedAttrCodes as $code) {
                // 1. Text value for search term boosting
                $valueKey = $code . '_value';
                if (isset($doc[$valueKey])) {
                    $str = $this->docFieldToString($doc[$valueKey]);
                    if ($str !== '') {
                        $perAttrFields[AttributeWeightProvider::fieldName($code)] = $this->stemmer->stemText($str);
                    }
                }
                
                // 2. Option ID value for precise filtering
                if (isset($doc[$code])) {
                    $val = $doc[$code];
                    if (is_array($val)) {
                        $perAttrFields[AttributeWeightProvider::fieldName($code) . '_id'] = array_map('intval', $val);
                    } elseif ($val !== null && $val !== '') {
                        $perAttrFields[AttributeWeightProvider::fieldName($code) . '_id'] = (int)$val;
                    }
                }
            }

            $docs[] = array_merge(
                [
                    'entity_id'           => $entityId,
                    'sku'                 => (string)($productData['sku'] ?? ''),
                    'store_id'            => $storeId,
                    'category_ids'        => array_map('intval', $categoryIds),
                    'category_names'      => $this->stemmer->stemText(implode(' ', $categoryNames)),
                    'name'                => $this->stemmer->stemText(explode("\n", $this->docFieldToString($productData['name'] ?? $doc['name'] ?? ''))[0]),
                    'description'         => $this->stemmer->stemText($this->getDocumentDescription($doc, $categoryNames)),
                    'status'              => (int)($productData['status'] ?? 1),
                    'visibility'          => (int)($productData['visibility'] ?? 4),
                    'embedding_text_hash' => $hashes[$i],
                    'embedding'           => $embeddings[$i],
                    'embedding_text'      => $this->buildEmbeddingText($doc, $productData, $categoryNames),
                ],
                $perAttrFields
            );
        }

        $documentBuildMs = (microtime(true) - $stageStartedAt) * 1000;
        $stageStartedAt = microtime(true);
        $this->openSearchClient->bulk($docs);
        $bulkMs = (microtime(true) - $stageStartedAt) * 1000;
        $this->indexedDocumentCount += count($docs);
        $this->logger->info(sprintf(
            '[VectorSearch] Indexed %d products for store %d in %.1f ms '
            . '(categories %.1f, text %.1f, hash lookup %.1f, embedding %.1f, documents %.1f, bulk %.1f; reused %d).',
            count($docs),
            $storeId,
            (microtime(true) - $batchStartedAt) * 1000,
            $categoryMs,
            $textMs,
            $hashLookupMs,
            $embeddingMs,
            $documentBuildMs,
            $bulkMs,
            $skippedCount
        ));
    }


    private function cleanSearchCache(): void
    {
        try {
            $cache = $this->cache ?? ObjectManager::getInstance()->get(CacheInterface::class);
            $cache->clean([VectorSearchCacheType::CACHE_TAG]);
            $service = $this->vectorSearchService ?? ObjectManager::getInstance()->get(VectorSearchService::class);
            $version = $service->bumpIndexVersion();
            $this->logger->info('[VectorSearch] Bumped search index cache version to ' . $version . '.');
        } catch (\Throwable $e) {
            $this->logger->warning('[VectorSearch] Could not clean search cache: ' . $e->getMessage());
        }
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the text used to generate an embedding from the mapped ES document.
     *
     * Structure (mirrors what Magento indexes in the `_search` field):
     *   - name (×2 for extra weight — includes configurable variant names)
     *   - all {code}_value fields (human-readable attribute labels)
     *   - short_description
     *   - description (HTML stripped)
     *
     * @param array    $doc           Mapped ES document (output of ProductDataMapper::map())
     * @param array    $productData   Static product row (entity_id, name, sku, type_id, …)
     * @param string[] $categoryNames Resolved category names
     */
    private function buildEmbeddingText(array $doc, array $productData, array $categoryNames): string
    {
        $text = '';

        // Name
        $name = $this->docFieldToString($productData['name'] ?? $doc['name'] ?? '');
        $name = explode("\n", $name)[0];
        if ($name !== '') {
            $text .= "Nazwa: " . $name . ". ";
        }

        // Categories
        if (!empty($categoryNames)) {
            $text .= "Kategorie: " . implode(', ', $categoryNames) . ". ";
        }

        // Dynamic EAV attributes
        $attrs = [];
        foreach ($doc as $key => $value) {
            if (str_ends_with($key, '_value')) {
                $code = substr($key, 0, -6);
                $label = ucfirst($code);
                if ($code === 'color') $label = 'Kolor';
                elseif ($code === 'material') $label = 'Materiał';
                elseif ($code === 'size') $label = 'Rozmiar';
                elseif ($code === 'gender') $label = 'Płeć';

                $str = $this->docFieldToString($value);
                if ($str !== '') {
                    $attrs[] = "$label: $str";
                }
            }
        }
        if (!empty($attrs)) {
            $text .= implode(', ', $attrs) . ". ";
        }

        // Descriptions
        $short = strip_tags($this->docFieldToString($doc['short_description'] ?? ''));
        if ($short !== '') {
            $text .= "Opis skrócony: " . mb_substr($short, 0, 500) . ". ";
        }
        $desc = strip_tags($this->docFieldToString($doc['description'] ?? ''));
        if ($desc !== '') {
            $text .= "Opis: " . mb_substr($desc, 0, 1000) . ". ";
        }

        return trim($text);
    }

    /**
     * Builds the plain-text description stored in the OpenSearch document
     * (used for lexical fallback in hybrid search).
     *
     * @param array    $doc           Mapped ES document (output of ProductDataMapper::map())
     * @param string[] $categoryNames Resolved category names
     */
    private function getDocumentDescription(array $doc, array $categoryNames): string
    {
        $parts = [];

        if (!empty($categoryNames)) {
            $parts[] = implode(' ', $categoryNames);
        }

        foreach ($doc as $key => $value) {
            if (str_ends_with($key, '_value')) {
                $str = $this->docFieldToString($value);
                if ($str !== '') {
                    $parts[] = $str;
                }
            }
        }
        $desc = strip_tags($this->docFieldToString($doc['description'] ?? ''));
        if ($desc !== '') {
            $parts[] = $desc;
        }
        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * Builds a map of parent_id → [child_id, …] for composite products in the batch.
     *
     * @param  array[] $products  Rows from DataProvider::getSearchableProducts()
     * @return array<int, int[]>
     */
    private function buildChildrenMap(array $products): array
    {
        $parentIds = [];
        foreach ($products as $productData) {
            $parentIds[] = (int)$productData['entity_id'];
        }

        if (empty($parentIds)) {
            return [];
        }

        $map = [];
        try {
            $conn = $this->resource->getConnection();
            $select = $conn->select()
                ->from($conn->getTableName('catalog_product_relation'), ['parent_id', 'child_id'])
                ->where('parent_id IN (?)', $parentIds);

            $rows = $conn->fetchAll($select);
            foreach ($rows as $row) {
                $parentId = (int)$row['parent_id'];
                $childId = (int)$row['child_id'];
                $map[$parentId][] = $childId;
            }
        } catch (\Throwable $e) {
            $this->logger->error('[VectorSearch] Error bulk loading child IDs: ' . $e->getMessage());
            // Fallback to native one-by-one method if DB query fails
            foreach ($products as $productData) {
                $childIds = $this->dataProvider->getProductChildIds(
                    $productData['entity_id'],
                    $productData['type_id']
                );
                if (!empty($childIds)) {
                    $map[(int)$productData['entity_id']] = array_map('intval', $childIds);
                }
            }
        }
        return $map;
    }


    /**
     * Safely converts a document field value to a trimmed string.
     *
     * ProductDataMapper::map() returns arrays for fields that aggregate values
     * from multiple configurable variants (e.g. 'name' contains one entry per
     * variant). We join them with a space so the embedding sees all variant names.
     *
     * @param mixed $value
     */
    private function docFieldToString(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_filter(array_map('strval', $value), static fn(string $s): bool => $s !== '');
            return trim(implode(' ', $parts));
        }
        return trim((string)$value);
    }

    /**
     * Loads and caches category names and paths for the given store.
     */
    private function loadCategoryMap(int $storeId): void
    {
        try {
            $conn = $this->resource->getConnection();
            
            // Get category name attribute ID (entity_type_id = 3 for catalog_category)
            $selectAttr = $conn->select()
                ->from($conn->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = ?', 3);
            $attributeId = (int)$conn->fetchOne($selectAttr);

            if (!$attributeId) {
                $this->categoryMap = [];
                return;
            }

            // Build the base select query
            $select = $conn->select()
                ->from(['cce' => $conn->getTableName('catalog_category_entity')], ['entity_id', 'path'])
                ->join(
                    ['ccv' => $conn->getTableName('catalog_category_entity_varchar')],
                    'ccv.entity_id = cce.entity_id',
                    ['name' => 'value']
                )
                ->where('ccv.attribute_id = ?', $attributeId);

            // Load defaults and then store overrides. Partial store translations must not
            // make categories without an override disappear from the embedding text.
            $select->where('ccv.store_id IN (?)', array_values(array_unique([0, $storeId])))
                ->order('ccv.store_id ASC');
            $rows = $conn->fetchAll($select);

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row['entity_id']] = [
                    'path' => (string)$row['path'],
                    'name' => (string)$row['name']
                ];
            }
            $this->categoryMap = $map;
        } catch (\Throwable $e) {
            $this->logger->error('[VectorSearch] Error loading category map: ' . $e->getMessage());
            $this->categoryMap = [];
        }
    }

    /**
     * Resolves product category IDs to all ancestor and direct category names.
     *
     * @param int[] $categoryIds
     * @return string[]
     */
    private function getProductCategoryNames(array $categoryIds): array
    {
        if ($this->categoryMap === null) {
            return [];
        }

        $allIds = [];
        foreach ($categoryIds as $catId) {
            $catId = (int)$catId;
            if (isset($this->categoryMap[$catId])) {
                $path = $this->categoryMap[$catId]['path'];
                foreach (explode('/', $path) as $part) {
                    $partId = (int)$part;
                    if ($partId > 2) { // Exclude root (1) and default category (2)
                        $allIds[] = $partId;
                    }
                }
            }
        }

        $allIds = array_unique($allIds);
        $names = [];
        foreach ($allIds as $id) {
            if (isset($this->categoryMap[$id]) && $this->categoryMap[$id]['name'] !== '') {
                $names[] = $this->categoryMap[$id]['name'];
            }
        }

        return $names;
    }
}
