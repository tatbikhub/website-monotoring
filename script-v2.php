<?php
class PrintfulAutoSync {
    private $apiKey;
    private $baseUrl = 'https://api.printful.com';
    private $jsonFile = 'products.json';
    private $logFile = 'sync.log';
    private $cacheFile = 'cache.json';
    private $retryAttempts = 3;
    private $retryDelay = 2; // seconds
    
    public function __construct($apiKey = null, $config = []) {
        $this->apiKey = $apiKey ?: $this->loadApiKeyFromEnv();
        
        if (!$this->apiKey) {
            throw new Exception('API key is required. Please set PRINTFUL_API_KEY in .env file or pass it as parameter.');
        }
        
        // Override default config
        $this->jsonFile = $config['json_file'] ?? $this->jsonFile;
        $this->logFile = $config['log_file'] ?? $this->logFile;
        $this->cacheFile = $config['cache_file'] ?? $this->cacheFile;
        $this->retryAttempts = $config['retry_attempts'] ?? $this->retryAttempts;
        $this->retryDelay = $config['retry_delay'] ?? $this->retryDelay;
    }
    
    /**
     * Load API key from .env file
     */
    private function loadApiKeyFromEnv() {
        $envFile = __DIR__ . '/.env';
        
        if (!file_exists($envFile)) {
            return null;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments
            if (strpos($line, '#') === 0) {
                continue;
            }
            
            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, '"\'');
                
                if ($key === 'PRINTFUL_API_KEY') {
                    return $value;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Main sync function with progress tracking and batch processing
     */
    public function syncAllProducts($batchSize = 10, $callback = null) {
        try {
            $this->log('Starting comprehensive product sync...');
            
            $syncProducts = $this->getSyncProducts();
            $totalProducts = count($syncProducts);
            $transformedProducts = [];
            $errors = [];
            
            $this->log("Found $totalProducts products to sync");
            
            // Process in batches
            $batches = array_chunk($syncProducts, $batchSize);
            $processedCount = 0;
            
            foreach ($batches as $batchIndex => $batch) {
                $this->log("Processing batch " . ($batchIndex + 1) . "/" . count($batches));
                
                foreach ($batch as $syncProduct) {
                    $processedCount++;
                    
                    try {
                        $productData = $this->getSyncProductById($syncProduct['id']);
                        if ($productData) {
                            $transformed = $this->transformProductData($productData);
                            if ($transformed) {
                                $transformedProducts[] = $transformed;
                                
                                // Progress callback
                                if ($callback) {
                                    $callback($processedCount, $totalProducts, $syncProduct['name'] ?? 'Unknown');
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $errors[] = [
                            'product_id' => $syncProduct['id'],
                            'error' => $e->getMessage()
                        ];
                        $this->log("Error processing product {$syncProduct['id']}: " . $e->getMessage());
                    }
                }
                
                // Small delay between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    usleep(500000); // 0.5 seconds
                }
            }
            
            // Save results
            $this->saveToJson($transformedProducts);
            $this->saveErrorLog($errors);
            
            $successCount = count($transformedProducts);
            $errorCount = count($errors);
            
            $this->log("Sync completed! Success: $successCount, Errors: $errorCount");
            
            return [
                'products' => $transformedProducts,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->log('Critical sync error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sync specific product by ID with enhanced error handling
     */
    public function syncProductById($productId) {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->retryAttempts) {
            try {
                $this->log("Syncing product ID: $productId (attempt " . ($attempt + 1) . ")");
                
                $productData = $this->getSyncProductById($productId);
                if (!$productData) {
                    throw new Exception("Product not found: $productId");
                }
                
                $transformed = $this->transformProductData($productData);
                
                // Update existing JSON file
                $this->updateProductInJson($transformed);
                
                $this->log("Product $productId synced successfully");
                return $transformed;
                
            } catch (Exception $e) {
                $lastError = $e;
                $attempt++;
                
                if ($attempt < $this->retryAttempts) {
                    $this->log("Retry attempt $attempt for product $productId: " . $e->getMessage());
                    sleep($this->retryDelay);
                } else {
                    $this->log("Failed to sync product $productId after $attempt attempts: " . $e->getMessage());
                }
            }
        }
        
        throw $lastError;
    }
    
    /**
     * Enhanced delete with backup
     */
    public function deleteProduct($productId, $createBackup = true) {
        try {
            $this->log("Deleting product ID: $productId");
            
            $products = $this->loadFromJson();
            $deletedProduct = null;
            
            // Find and backup the product before deletion
            foreach ($products as $product) {
                if ($product['product']['id'] === (string)$productId) {
                    $deletedProduct = $product;
                    break;
                }
            }
            
            if ($deletedProduct && $createBackup) {
                $this->backupDeletedProduct($deletedProduct);
            }
            
            $products = array_filter($products, function($product) use ($productId) {
                return $product['product']['id'] !== (string)$productId;
            });
            
            $this->saveToJson(array_values($products));
            $this->log("Product $productId deleted successfully");
            
            return $deletedProduct;
            
        } catch (Exception $e) {
            $this->log("Error deleting product $productId: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Bulk operations support
     */
    public function bulkSync(array $productIds, $callback = null) {
        $results = [];
        $total = count($productIds);
        
        foreach ($productIds as $index => $productId) {
            try {
                $result = $this->syncProductById($productId);
                $results['success'][] = $result;
                
                if ($callback) {
                    $callback($index + 1, $total, $productId, 'success');
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ];
                
                if ($callback) {
                    $callback($index + 1, $total, $productId, 'error', $e->getMessage());
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get sync status and statistics
     */
    public function getSyncStatus() {
        $products = $this->loadFromJson();
        $cache = $this->loadCache();
        
        $status = [
            'total_products' => count($products),
            'last_sync' => $cache['last_full_sync'] ?? null,
            'last_update' => $cache['last_update'] ?? null,
            'cache_size' => file_exists($this->cacheFile) ? filesize($this->cacheFile) : 0,
            'json_size' => file_exists($this->jsonFile) ? filesize($this->jsonFile) : 0,
            'log_size' => file_exists($this->logFile) ? filesize($this->logFile) : 0
        ];
        
        // Product statistics
        $categories = [];
        $materials = [];
        $colors = [];
        $sizes = [];
        
        foreach ($products as $item) {
            $product = $item['product'];
            
            // Categories
            if (!empty($product['mainCategory_Name'])) {
                $categories[$product['mainCategory_Name']] = 
                    ($categories[$product['mainCategory_Name']] ?? 0) + 1;
            }
            
            // Materials
            if (!empty($product['material']) && is_array($product['material'])) {
                foreach ($product['material'] as $material) {
                    $materials[$material['name']] = 
                        ($materials[$material['name']] ?? 0) + 1;
                }
            }
            
            // Colors and sizes
            foreach ($item['colors'] ?? [] as $color) {
                $colors[$color['name']] = ($colors[$color['name']] ?? 0) + 1;
            }
            
            foreach ($item['sizes'] ?? [] as $size) {
                $sizes[$size['name']] = ($sizes[$size['name']] ?? 0) + 1;
            }
        }
        
        $status['statistics'] = [
            'categories' => $categories,
            'materials' => $materials,
            'colors' => $colors,
            'sizes' => $sizes
        ];
        
        return $status;
    }
    
    /**
     * Search and filter products
     */
    public function searchProducts($filters = []) {
        $products = $this->loadFromJson();
        
        return array_filter($products, function($item) use ($filters) {
            $product = $item['product'];
            
            // Name search
            if (!empty($filters['name'])) {
                if (stripos($product['name'], $filters['name']) === false) {
                    return false;
                }
            }
            
            // Category filter
            if (!empty($filters['category'])) {
                if ($product['mainCategory_Name'] !== $filters['category']) {
                    return false;
                }
            }
            
            // Price range
            if (!empty($filters['min_price'])) {
                if ((float)$product['price'] < (float)$filters['min_price']) {
                    return false;
                }
            }
            
            if (!empty($filters['max_price'])) {
                if ((float)$product['price'] > (float)$filters['max_price']) {
                    return false;
                }
            }
            
            // Color filter
            if (!empty($filters['color'])) {
                $hasColor = false;
                foreach ($item['colors'] ?? [] as $color) {
                    if (stripos($color['name'], $filters['color']) !== false) {
                        $hasColor = true;
                        break;
                    }
                }
                if (!$hasColor) return false;
            }
            
            // Size filter
            if (!empty($filters['size'])) {
                $hasSize = false;
                foreach ($item['sizes'] ?? [] as $size) {
                    if (strcasecmp($size['name'], $filters['size']) === 0) {
                        $hasSize = true;
                        break;
                    }
                }
                if (!$hasSize) return false;
            }
            
            return true;
        });
    }
    
    /**
     * Cache management
     */
    private function loadCache() {
        if (!file_exists($this->cacheFile)) {
            return [];
        }
        
        $content = file_get_contents($this->cacheFile);
        return json_decode($content, true) ?: [];
    }
    
    private function saveCache($data) {
        $cache = $this->loadCache();
        $cache = array_merge($cache, $data);
        $cache['last_update'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
    }
    
    private function backupDeletedProduct($product) {
        $backupFile = 'deleted_products_' . date('Y-m') . '.json';
        $backups = [];
        
        if (file_exists($backupFile)) {
            $backups = json_decode(file_get_contents($backupFile), true) ?: [];
        }
        
        $backups[] = [
            'product' => $product,
            'deleted_at' => date('Y-m-d H:i:s')
        ];
        
        file_put_contents($backupFile, json_encode($backups, JSON_PRETTY_PRINT));
    }
    
    private function saveErrorLog($errors) {
        if (empty($errors)) return;
        
        $errorFile = 'error_log_' . date('Y-m-d') . '.json';
        file_put_contents($errorFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'errors' => $errors
        ], JSON_PRETTY_PRINT));
    }
    
    /**
     * Get all sync products from Printful with caching
     */
    private function getSyncProducts() {
        $cache = $this->loadCache();
        $cacheKey = 'sync_products';
        $cacheExpiry = 3600; // 1 hour
        
        // Check cache
        if (isset($cache[$cacheKey]) && 
            isset($cache[$cacheKey . '_timestamp']) && 
            (time() - $cache[$cacheKey . '_timestamp']) < $cacheExpiry) {
            
            $this->log('Using cached sync products list');
            return $cache[$cacheKey];
        }
        
        // Fetch fresh data
        $response = $this->makeApiCall('/sync/products');
        $products = $response['result'] ?? [];
        
        // Cache the result
        $this->saveCache([
            $cacheKey => $products,
            $cacheKey . '_timestamp' => time()
        ]);
        
        return $products;
    }
    
    /**
     * Get specific sync product with variants and caching
     */
    private function getSyncProductById($productId) {
        $cache = $this->loadCache();
        $cacheKey = "sync_product_$productId";
        $cacheExpiry = 1800; // 30 minutes
        
        // Check cache
        if (isset($cache[$cacheKey]) && 
            isset($cache[$cacheKey . '_timestamp']) && 
            (time() - $cache[$cacheKey . '_timestamp']) < $cacheExpiry) {
            
            return $cache[$cacheKey];
        }
        
        // Fetch fresh data
        $response = $this->makeApiCall("/sync/products/$productId");
        
        if ($response) {
            // Cache the result
            $this->saveCache([
                $cacheKey => $response,
                $cacheKey . '_timestamp' => time()
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get Printful product details with caching
     */
    private function getPrintfulProduct($productId) {
        $cache = $this->loadCache();
        $cacheKey = "printful_product_$productId";
        $cacheExpiry = 7200; // 2 hours (product details change less frequently)
        
        // Check cache
        if (isset($cache[$cacheKey]) && 
            isset($cache[$cacheKey . '_timestamp']) && 
            (time() - $cache[$cacheKey . '_timestamp']) < $cacheExpiry) {
            
            return $cache[$cacheKey];
        }
        
        // Fetch fresh data
        $response = $this->makeApiCall("/products/$productId");
        
        if ($response) {
            // Cache the result
            $this->saveCache([
                $cacheKey => $response,
                $cacheKey . '_timestamp' => time()
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get categories with caching
     */
    private function getCategories($categoryId = null) {
        $cache = $this->loadCache();
        $cacheKey = $categoryId ? "category_$categoryId" : "categories_all";
        $cacheExpiry = 86400; // 24 hours (categories rarely change)
        
        // Check cache
        if (isset($cache[$cacheKey]) && 
            isset($cache[$cacheKey . '_timestamp']) && 
            (time() - $cache[$cacheKey . '_timestamp']) < $cacheExpiry) {
            
            return $cache[$cacheKey];
        }
        
        $endpoint = $categoryId ? "/categories/$categoryId" : "/categories";
        $response = $this->makeApiCall($endpoint);
        
        if ($response) {
            // Cache the result
            $this->saveCache([
                $cacheKey => $response,
                $cacheKey . '_timestamp' => time()
            ]);
        }
        
        return $response;
    }
    
    /**
     * Generate UUID v4
     */
    private function generateUuidV4() {
        $data = random_bytes(16);
        
        // Set version (4) and variant bits
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant bits
        
        return sprintf('%08s-%04s-%04s-%04s-%12s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
    
    /**
     * Extract unique colors from variants with enhanced color detection
     */
    private function extractColors($syncVariants) {
        $colors = [];
        $colorMap = [];
        
        foreach ($syncVariants as $variant) {
            $colorName = $variant['color'] ?? '';
            $colorCode = $variant['color_code'] ?? '';
            
            if ($colorName && !isset($colorMap[$colorName])) {
                $colors[] = [
                    'name' => $colorName,
                    'code' => $colorCode,
                    'hex' => $this->getColorHex($colorName, $colorCode),
                    'family' => $this->getColorFamily($colorName)
                ];
                $colorMap[$colorName] = true;
            }
        }
        
        // Sort colors by family and name
        usort($colors, function($a, $b) {
            if ($a['family'] !== $b['family']) {
                return strcmp($a['family'], $b['family']);
            }
            return strcmp($a['name'], $b['name']);
        });
        
        return $colors;
    }
    
    /**
     * Extract unique sizes from variants with enhanced size detection
     */
    private function extractSizes($syncVariants) {
        $sizes = [];
        $sizeMap = [];
        
        foreach ($syncVariants as $variant) {
            $sizeName = $variant['size'] ?? '';
            
            if ($sizeName && !isset($sizeMap[$sizeName])) {
                $sizes[] = [
                    'name' => $sizeName,
                    'displayName' => $this->formatSizeName($sizeName),
                    'category' => $this->getSizeCategory($sizeName),
                    'order' => $this->getSizeOrder($sizeName)
                ];
                $sizeMap[$sizeName] = true;
            }
        }
        
        // Sort sizes by order
        usort($sizes, function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        return $sizes;
    }
    
    /**
     * Enhanced color hex mapping with more colors
     */
    private function getColorHex($colorName, $colorCode) {
        if ($colorCode && strpos($colorCode, '#') === 0) {
            return $colorCode;
        }
        
        $colorMap = [
            // Basic colors
            'black' => '#000000', 'white' => '#FFFFFF', 'red' => '#FF0000',
            'blue' => '#0000FF', 'green' => '#008000', 'yellow' => '#FFFF00',
            'purple' => '#800080', 'orange' => '#FFA500', 'pink' => '#FFC0CB',
            'brown' => '#A52A2A', 'gray' => '#808080', 'grey' => '#808080',
            
            // Extended colors
            'navy' => '#000080', 'maroon' => '#800000', 'olive' => '#808000',
            'lime' => '#00FF00', 'aqua' => '#00FFFF', 'teal' => '#008080',
            'silver' => '#C0C0C0', 'fuchsia' => '#FF00FF', 'gold' => '#FFD700',
            'indigo' => '#4B0082', 'violet' => '#8B00FF', 'turquoise' => '#40E0D0',
            'coral' => '#FF7F50', 'salmon' => '#FA8072', 'khaki' => '#F0E68C',
            'tan' => '#D2B48C', 'beige' => '#F5F5DC', 'mint' => '#98FB98',
            'lavender' => '#E6E6FA', 'rose' => '#FF66CC', 'cream' => '#FFFDD0',
            
            // Dark variants
            'dark blue' => '#00008B', 'dark green' => '#006400', 'dark red' => '#8B0000',
            'dark gray' => '#A9A9A9', 'dark grey' => '#A9A9A9',
            
            // Light variants  
            'light blue' => '#ADD8E6', 'light green' => '#90EE90', 'light pink' => '#FFB6C1',
            'light gray' => '#D3D3D3', 'light grey' => '#D3D3D3'
        ];
        
        $colorKey = strtolower($colorName);
        return $colorMap[$colorKey] ?? '#CCCCCC';
    }
    
    /**
     * Get color family for grouping
     */
    private function getColorFamily($colorName) {
        $families = [
            'neutral' => ['black', 'white', 'gray', 'grey', 'silver', 'beige', 'cream', 'tan', 'khaki'],
            'blue' => ['blue', 'navy', 'turquoise', 'aqua', 'teal', 'light blue', 'dark blue'],
            'red' => ['red', 'maroon', 'pink', 'coral', 'salmon', 'rose', 'dark red', 'light pink'],
            'green' => ['green', 'lime', 'olive', 'mint', 'dark green', 'light green'],
            'yellow' => ['yellow', 'gold'],
            'purple' => ['purple', 'violet', 'indigo', 'fuchsia', 'lavender'],
            'orange' => ['orange'],
            'brown' => ['brown']
        ];
        
        $colorKey = strtolower($colorName);
        foreach ($families as $family => $colors) {
            if (in_array($colorKey, $colors)) {
                return $family;
            }
        }
        
        return 'other';
    }
    
    /**
     * Enhanced size formatting
     */
    private function formatSizeName($sizeName) {
        $sizeMap = [
            'xs' => 'Extra Small', 's' => 'Small', 'm' => 'Medium', 'l' => 'Large',
            'xl' => 'Extra Large', 'xxl' => '2X Large', '2xl' => '2X Large',
            'xxxl' => '3X Large', '3xl' => '3X Large', '4xl' => '4X Large', '5xl' => '5X Large',
            'os' => 'One Size', 'onesize' => 'One Size'
        ];
        
        $sizeKey = strtolower($sizeName);
        return $sizeMap[$sizeKey] ?? $sizeName;
    }
    
    /**
     * Get size category
     */
    private function getSizeCategory($sizeName) {
        $sizeKey = strtolower($sizeName);
        
        if (in_array($sizeKey, ['xs', 's', 'm', 'l', 'xl', 'xxl', '2xl', 'xxxl', '3xl', '4xl', '5xl'])) {
            return 'clothing';
        }
        
        if (preg_match('/^\d+(\.\d+)?\s?(cm|mm|inch|in)$/', $sizeKey)) {
            return 'measurement';
        }
        
        if (in_array($sizeKey, ['os', 'onesize', 'one size'])) {
            return 'universal';
        }
        
        return 'other';
    }
    
    /**
     * Get size order for sorting
     */
    private function getSizeOrder($sizeName) {
        $order = [
            'xs' => 1, 's' => 2, 'm' => 3, 'l' => 4, 'xl' => 5,
            'xxl' => 6, '2xl' => 6, 'xxxl' => 7, '3xl' => 7,
            '4xl' => 8, '5xl' => 9, 'os' => 10, 'onesize' => 10
        ];
        
        $sizeKey = strtolower($sizeName);
        return $order[$sizeKey] ?? 999;
    }
    
    /**
     * Enhanced transform with validation and error recovery
     */
    private function transformProductData($rawData) {
        try {
            // Validate input data
            if (!isset($rawData['result']) || 
                !isset($rawData['result']['sync_product']) || 
                !isset($rawData['result']['sync_variants'])) {
                throw new Exception('Invalid API response structure');
            }
            
            $syncProduct = $rawData['result']['sync_product'];
            $syncVariants = $rawData['result']['sync_variants'];
            
            if (empty($syncVariants)) {
                throw new Exception('No variants found for product');
            }
            
            // Get product details with fallback
            $productId = $syncVariants[0]['product']['product_id'] ?? $syncProduct['id'];
            $productDetails = null;
            $productInfo = [];
            
            try {
                $productDetails = $this->getPrintfulProduct($productId);
                $productInfo = $productDetails['result']['product'] ?? [];
            } catch (Exception $e) {
                $this->log("Warning: Could not fetch product details for $productId: " . $e->getMessage());
            }
            
            // Get category with fallback
            $categoryId = $syncVariants[0]['main_category_id'] ?? $productInfo['main_category_id'] ?? null;
            $categoryName = '';
            
            if ($categoryId) {
                try {
                    $categoryData = $this->getCategories($categoryId);
                    $categoryName = $categoryData['result']['category']['title'] ?? '';
                } catch (Exception $e) {
                    $this->log("Warning: Could not fetch category $categoryId: " . $e->getMessage());
                }
            }
            
            // Build robust product object
            $product = [
                'id' => (string)$syncProduct['id'],
                'externalId' => $syncProduct['external_id'] ?? '',
                'name' => $this->sanitizeText($syncProduct['name'] ?? ''),
                'description' => $this->sanitizeText($productInfo['description'] ?? ''),
                'brand' => $this->sanitizeText($productInfo['brand'] ?? ''),
                'model' => $this->sanitizeText($productInfo['model'] ?? ''),
                'currency' => $syncVariants[0]['currency'] ?? $productInfo['currency'] ?? 'USD',
                'imageUrl' => $this->validateImageUrl($syncProduct['thumbnail_url'] ?? $productInfo['image'] ?? ''),
                'mainCategory_ID' => (string)($categoryId ?? ''),
                'mainCategory_Name' => $this->sanitizeText($categoryName),
                'mainProduct_ID' => (string)$productId,
                'originCountry' => $productInfo['origin_country'] ?? '',
                'type' => $this->sanitizeText($productInfo['type_name'] ?? $productInfo['type'] ?? ''),
                'material' => $this->extractMaterial($productInfo['description'] ?? ''),
                'price' => $this->validatePrice($syncVariants[0]['retail_price'] ?? '0'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Process variants with validation
            $variants = [];
            foreach ($syncVariants as $v) {
                $variants[] = [
                    'id' => (string)$v['id'],
                    'name' => $this->sanitizeText($v['name'] ?? ''),
                    'externalId' => $v['external_id'] ?? '',
                    'size' => $this->sanitizeText($v['size'] ?? ''),
                    'color' => $this->sanitizeText($v['color'] ?? ''),
                    'price' => $this->validatePrice($v['retail_price'] ?? 0),
                    'imageUrl' => $this->validateImageUrl($v['product']['image'] ?? ''),
                    'sku' => $v['sku'] ?? '',
                    'availability' => $v['availability_status'] ?? 'unknown',
                    'in_stock' => $this->isInStock($v['availability_status'] ?? 'unknown')
                ];
            }
            
            // Extract and validate colors and sizes
            $colors = $this->extractColors($syncVariants);
            $sizes = $this->extractSizes($syncVariants);
            
            return [
                'id' => $this->generateUuidV4(),
                'product' => $product,
                'variants' => $variants,
                'colors' => $colors,
                'sizes' => $sizes,
                'metadata' => [
                    'sync_source' => 'printful_api',
                    'sync_version' => '2.0',
                    'sync_timestamp' => date('c'),
                    'variant_count' => count($variants),
                    'color_count' => count($colors),
                    'size_count' => count($sizes)
                ]
            ];
            
        } catch (Exception $e) {
            $this->log('Error transforming product data: ' . $e->getMessage());
            throw new Exception('Failed to transform product data: ' . $e->getMessage());
        }
    }
    
    /**
     * Enhanced material extraction with better parsing
     */
    private function extractMaterial($description) {
        $result = [];

        if (empty($description)) {
            return $result;
        }

        // Enhanced regex patterns for material detection
        $patterns = [
            // Pattern: "100% Cotton" or "50% Polyester"
            '/(\d+)%\s+([\w\s\-\/]+?)(?=\s*[\d%]|$|,|\.|\n)/ui',
            // Pattern: "Made from 100% Cotton"
            '/made\s+from\s+(\d+)%\s+([\w\s\-\/]+)/ui',
            // Pattern: "Cotton 100%"
            '/([\w\s\-\/]+?)\s+(\d+)%/ui'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $description, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $percentage = isset($match[1]) && is_numeric($match[1]) ? $match[1] : $match[2];
                    $material = isset($match[2]) && !is_numeric($match[2]) ? trim($match[2]) : trim($match[1]);
                    
                    // Clean up material name
                    $material = $this->cleanMaterialName($material);
                    
                    if (!empty($material) && is_numeric($percentage)) {
                        $result[] = [
                            'name' => $material,
                            'percentage' => $percentage . '%'
                        ];
                    }
                }
                break; // Use first matching pattern
            }
        }

        // If no percentage found, try to extract material names only
        if (empty($result)) {
            $materialKeywords = [
                'cotton', 'polyester', 'silk', 'wool', 'linen', 'bamboo', 'hemp',
                'nylon', 'spandex', 'elastane', 'viscose', 'rayon', 'modal',
                'acrylic', 'cashmere', 'alpaca', 'mohair', 'angora'
            ];
            
            foreach ($materialKeywords as $keyword) {
                if (stripos($description, $keyword) !== false) {
                    $result[] = [
                        'name' => ucfirst($keyword),
                        'percentage' => '100%'
                    ];
                    break; // Only first found material
                }
            }
        }

        // Validate total percentage
        if (count($result) > 1) {
            $total = array_sum(array_map(function($item) {
                return (int)str_replace('%', '', $item['percentage']);
            }, $result));
            
            // If percentages don't add up to 100%, normalize them
            if ($total !== 100 && $total > 0) {
                foreach ($result as &$item) {
                    $currentPercent = (int)str_replace('%', '', $item['percentage']);
                    $normalizedPercent = round(($currentPercent / $total) * 100);
                    $item['percentage'] = $normalizedPercent . '%';
                }
            }
        }

        return $result;
    }
    
    /**
     * Clean material name
     */
    private function cleanMaterialName($material) {
        // Remove common unwanted words and clean up
        $material = preg_replace('/\b(blend|fabric|material|fiber|fibre)\b/i', '', $material);
        $material = preg_replace('/[^\w\s\-]/', '', $material); // Remove special chars except hyphens
        $material = trim($material);
        $material = ucwords(strtolower($material));
        
        return $material;
    }
    
    /**
     * Validate and sanitize text
     */
    private function sanitizeText($text) {
        if (empty($text)) return '';
        
        // Remove HTML tags and decode entities
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Validate image URL
     */
    private function validateImageUrl($url) {
        if (empty($url)) return '';
        
        // Check if it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        
        // Check if it's an image URL (basic check)
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        if (!in_array($extension, $imageExtensions) && !empty($extension)) {
            return ''; // Not an image extension
        }
        
        return $url;
    }
    
    /**
     * Validate price
     */
    private function validatePrice($price) {
        $price = (float)$price;
        return $price >= 0 ? number_format($price, 2, '.', '') : '0.00';
    }
    
    /**
     * Check if product is in stock
     */
    private function isInStock($availability) {
        $inStockStatuses = ['in_stock', 'available', 'active'];
        return in_array(strtolower($availability), $inStockStatuses);
    }
    
    /**
     * Enhanced API call with retry logic and rate limiting
     */
    private function makeApiCall($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->retryAttempts) {
            try {
                $headers = [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'User-Agent: PrintfulAutoSync/2.0'
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                
                if ($method === 'POST' && $data) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    throw new Exception("CURL Error: $error");
                }
                
                // Handle rate limiting
                if ($httpCode === 429) {
                    $waitTime = pow(2, $attempt) * $this->retryDelay; // Exponential backoff
                    $this->log("Rate limited, waiting {$waitTime} seconds before retry...");
                    sleep($waitTime);
                    $attempt++;
                    continue;
                }
                
                if ($httpCode !== 200) {
                    throw new Exception("API call failed. HTTP Code: $httpCode, Response: " . substr($response, 0, 200));
                }
                
                $data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
                }
                
                // Check for API errors
                if (isset($data['error'])) {
                    throw new Exception('API Error: ' . ($data['error']['message'] ?? 'Unknown error'));
                }
                
                return $data;
                
            } catch (Exception $e) {
                $lastError = $e;
                $attempt++;
                
                if ($attempt < $this->retryAttempts) {
                    $waitTime = $attempt * $this->retryDelay;
                    $this->log("API call failed (attempt $attempt), retrying in {$waitTime}s: " . $e->getMessage());
                    sleep($waitTime);
                } else {
                    $this->log("API call failed after $attempt attempts: " . $e->getMessage());
                }
            }
        }
        
        throw $lastError;
    }
    
    /**
     * Enhanced JSON save with atomic write and backup
     */
    private function saveToJson($products) {
        // Create backup of existing file
        if (file_exists($this->jsonFile)) {
            $backupFile = $this->jsonFile . '.backup.' . date('Y-m-d-H-i-s');
            copy($this->jsonFile, $backupFile);
            
            // Keep only last 5 backups
            $this->cleanupBackups($this->jsonFile . '.backup.*', 5);
        }
        
        // Add metadata
        $output = [
            'metadata' => [
                'generated_at' => date('c'),
                'total_products' => count($products),
                'sync_version' => '2.0',
                'generator' => 'PrintfulAutoSync'
            ],
            'products' => $products
        ];
        
        $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to encode products to JSON: ' . json_last_error_msg());
        }
        
        // Atomic write using temporary file
        $tempFile = $this->jsonFile . '.tmp.' . uniqid();
        
        if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
            throw new Exception('Failed to write temporary JSON file');
        }
        
        if (!rename($tempFile, $this->jsonFile)) {
            unlink($tempFile);
            throw new Exception('Failed to move temporary file to final location');
        }
        
        $this->log("Successfully saved " . count($products) . " products to JSON file");
    }
    
    /**
     * Enhanced JSON load with error recovery
     */
    private function loadFromJson() {
        if (!file_exists($this->jsonFile)) {
            return [];
        }
        
        $content = file_get_contents($this->jsonFile);
        
        if ($content === false) {
            throw new Exception('Failed to read JSON file');
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to load from backup
            $backupFiles = glob($this->jsonFile . '.backup.*');
            if (!empty($backupFiles)) {
                rsort($backupFiles); // Get most recent backup
                $backupContent = file_get_contents($backupFiles[0]);
                $data = json_decode($backupContent, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->log("Recovered data from backup: " . basename($backupFiles[0]));
                    return isset($data['products']) ? $data['products'] : ($data ?? []);
                }
            }
            
            throw new Exception('Invalid JSON in file and no valid backup found: ' . json_last_error_msg());
        }
        
        // Handle both old and new format
        return isset($data['products']) ? $data['products'] : ($data ?? []);
    }
    
    /**
     * Cleanup old backup files
     */
    private function cleanupBackups($pattern, $keepCount) {
        $files = glob($pattern);
        if (count($files) > $keepCount) {
            // Sort by modification time, oldest first
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Delete oldest files
            $filesToDelete = array_slice($files, 0, count($files) - $keepCount);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Enhanced product update in JSON with conflict resolution
     */
    private function updateProductInJson($newProduct) {
        $products = $this->loadFromJson();
        $updated = false;
        
        // Find existing product by sync product ID
        for ($i = 0; $i < count($products); $i++) {
            if ($products[$i]['product']['id'] === $newProduct['product']['id']) {
                // Preserve UUID and creation date if exists
                if (isset($products[$i]['id'])) {
                    $newProduct['id'] = $products[$i]['id'];
                }
                if (isset($products[$i]['product']['created_at'])) {
                    $newProduct['product']['created_at'] = $products[$i]['product']['created_at'];
                }
                
                // Update the product
                $products[$i] = $newProduct;
                $updated = true;
                $this->log("Updated existing product: " . $newProduct['product']['name']);
                break;
            }
        }
        
        // Add new product if not found
        if (!$updated) {
            $products[] = $newProduct;
            $this->log("Added new product: " . $newProduct['product']['name']);
        }
        
        $this->saveToJson($products);
    }
    
    /**
     * Enhanced logging with levels and rotation
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        // Console output
        echo $logMessage;
        
        // File logging with rotation
        $maxLogSize = 5 * 1024 * 1024; // 5MB
        
        if (file_exists($this->logFile) && filesize($this->logFile) > $maxLogSize) {
            // Rotate log file
            $rotatedLog = $this->logFile . '.' . date('Y-m-d-H-i-s');
            rename($this->logFile, $rotatedLog);
            
            // Keep only last 10 log files
            $this->cleanupBackups($this->logFile . '.*', 10);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Enhanced usage example and webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Webhook handler for Printful events
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data && isset($data['type'])) {
        try {
            $config = [
                'retry_attempts' => 5,
                'retry_delay' => 3
            ];
            
            $sync = new PrintfulAutoSync(null, $config);
            
            switch ($data['type']) {
                case 'product_synced':
                case 'product_updated':
                    if (isset($data['data']['sync_product']['id'])) {
                        $result = $sync->syncProductById($data['data']['sync_product']['id']);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'action' => 'synced',
                            'product_id' => $data['data']['sync_product']['id'],
                            'product_name' => $result['product']['name'] ?? 'Unknown'
                        ]);
                    }
                    break;
                    
                case 'product_deleted':
                    if (isset($data['data']['sync_product']['id'])) {
                        $deleted = $sync->deleteProduct($data['data']['sync_product']['id'], true);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'action' => 'deleted',
                            'product_id' => $data['data']['sync_product']['id'],
                            'backup_created' => !empty($deleted)
                        ]);
                    }
                    break;
                    
                default:
                    http_response_code(200);
                    echo json_encode(['status' => 'ignored', 'event_type' => $data['type']]);
            }
            
        } catch (Exception $e) {
            error_log("Printful webhook error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid webhook payload']);
    }
    
} else {
    // Manual sync or CLI usage
    if (php_sapi_name() === 'cli') {
        $apiKey = $argv[1] ?? null;
        $action = $argv[2] ?? 'sync_all';
        $productId = $argv[3] ?? null;
        
        try {
            $sync = new PrintfulAutoSync($apiKey);
            
            switch ($action) {
                case 'sync_all':
                    echo "Starting full product sync...\n";
                    $result = $sync->syncAllProducts(10, function($current, $total, $name) {
                        $percent = round(($current / $total) * 100);
                        echo "\rProgress: $current/$total ($percent%) - $name";
                    });
                    echo "\n\nSync completed!\n";
                    echo "Success: {$result['success_count']}\n";
                    echo "Errors: {$result['error_count']}\n";
                    break;
                    
                case 'sync_product':
                    if (!$productId) {
                        echo "Error: Product ID required for sync_product action\n";
                        exit(1);
                    }
                    $result = $sync->syncProductById($productId);
                    echo "Product synced: {$result['product']['name']}\n";
                    break;
                    
                case 'status':
                    $status = $sync->getSyncStatus();
                    echo "=== Sync Status ===\n";
                    echo "Total Products: {$status['total_products']}\n";
                    echo "Last Sync: " . ($status['last_sync'] ?? 'Never') . "\n";
                    echo "Last Update: " . ($status['last_update'] ?? 'Never') . "\n";
                    echo "JSON File Size: " . number_format($status['json_size']) . " bytes\n";
                    echo "Cache Size: " . number_format($status['cache_size']) . " bytes\n";
                    break;
                    
                case 'search':
                    $filters = [];
                    if (isset($argv[3])) $filters['name'] = $argv[3];
                    if (isset($argv[4])) $filters['category'] = $argv[4];
                    
                    $results = $sync->searchProducts($filters);
                    echo "Found " . count($results) . " products:\n";
                    foreach ($results as $product) {
                        echo "- {$product['product']['name']} ({$product['product']['mainCategory_Name']})\n";
                    }
                    break;
                    
                default:
                    echo "Usage:\n";
                    echo "  php script.php [API_KEY] sync_all\n";
                    echo "  php script.php [API_KEY] sync_product PRODUCT_ID\n";
                    echo "  php script.php [API_KEY] status\n";
                    echo "  php script.php [API_KEY] search [NAME] [CATEGORY]\n";
                    echo "\nOr set PRINTFUL_API_KEY in .env file to omit API key parameter\n";
            }
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        // Enhanced web interface
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Printful Auto-Sync Dashboard</title>
            <meta name='viewport' content='width=device-width, initial-scale=1'>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                .card { border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 8px; }
                .status { background: #f8f9fa; }
                .success { color: #28a745; }
                .error { color: #dc3545; }
                .warning { color: #ffc107; }
            </style>
        </head>
        <body>
            <h1>Printful Auto-Sync Dashboard</h1>
            
            <div class='card status'>
                <h3>System Status</h3>
                <p>This endpoint handles automatic product synchronization via Printful webhooks.</p>
                <p><strong>Version:</strong> 2.0 Enhanced</p>
                <p><strong>Features:</strong> Caching, Retry Logic, Backup, Search, Statistics</p>
            </div>
            
            <div class='card'>
                <h3>Manual Operations (CLI)</h3>
                <pre>
# Full sync with progress
php script.php sync_all

# Sync specific product  
php script.php sync_product 12345

# View sync status and statistics
php script.php status

# Search products
php script.php search \"T-Shirt\" \"Apparel\"
                </pre>
            </div>
            
            <div class='card'>
                <h3>Webhook Configuration</h3>
                <p>Point your Printful webhook to: <code>" . $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "</code></p>
                <p>Supported events: product_synced, product_updated, product_deleted</p>
            </div>
        </body>
        </html>";
    }
}
?>