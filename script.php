<?php
class PrintfulAutoSync {
    private $apiKey;
    private $baseUrl = 'https://api.printful.com';
    private $jsonFile = 'products.json';
    private $logFile = 'sync.log';
    
    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey ?: $this->loadApiKeyFromEnv();
        
        if (!$this->apiKey) {
            throw new Exception('API key is required. Please set PRINTFUL_API_KEY in .env file or pass it as parameter.');
        }
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
     * Main sync function - fetches all products and updates JSON file
     */
    public function syncAllProducts() {
        try {
            $this->log('Starting product sync...');
            
            $syncProducts = $this->getSyncProducts();
            $transformedProducts = [];
            
            foreach ($syncProducts as $syncProduct) {
                $productData = $this->getSyncProductById($syncProduct['id']);
                if ($productData) {
                    $transformed = $this->transformProductData($productData);
                    if ($transformed) {
                        $transformedProducts[] = $transformed;
                    }
                }
            }
            
            $this->saveToJson($transformedProducts);
            $this->log('Sync completed successfully. Total products: ' . count($transformedProducts));
            
            return $transformedProducts;
            
        } catch (Exception $e) {
            $this->log('Sync error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sync specific product by ID
     */
    public function syncProductById($productId) {
        try {
            $this->log("Syncing product ID: $productId");
            
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
            $this->log("Error syncing product $productId: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete product from JSON file
     */
    public function deleteProduct($productId) {
        try {
            $this->log("Deleting product ID: $productId");
            
            $products = $this->loadFromJson();
            $products = array_filter($products, function($product) use ($productId) {
                return $product['product']['id'] !== (string)$productId;
            });
            
            $this->saveToJson(array_values($products));
            $this->log("Product $productId deleted successfully");
            
        } catch (Exception $e) {
            $this->log("Error deleting product $productId: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get all sync products from Printful
     */
    private function getSyncProducts() {
        $response = $this->makeApiCall('/sync/products');
        return $response['result'] ?? [];
    }
    
    /**
     * Get specific sync product with variants
     */
    private function getSyncProductById($productId) {
        $response = $this->makeApiCall("/sync/products/$productId");
        return $response ?? null;
    }
    
    /**
     * Get Printful product details
     */
    private function getPrintfulProduct($productId) {
        $response = $this->makeApiCall("/products/$productId");
        return $response ?? null;
    }
    
    /**
     * Get categories (if needed)
     */
    private function getCategories($categoryId = null) {
        $endpoint = $categoryId ? "/categories/$categoryId" : "/categories";
        $response = $this->makeApiCall($endpoint);
        return $response ?? null;
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
     * Extract unique colors from variants
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
                    'hex' => $this->getColorHex($colorName, $colorCode)
                ];
                $colorMap[$colorName] = true;
            }
        }
        
        return $colors;
    }
    
    /**
     * Extract unique sizes from variants
     */
    private function extractSizes($syncVariants) {
        $sizes = [];
        $sizeMap = [];
        
        foreach ($syncVariants as $variant) {
            $sizeName = $variant['size'] ?? '';
            
            if ($sizeName && !isset($sizeMap[$sizeName])) {
                $sizes[] = [
                    'name' => $sizeName,
                    'displayName' => $this->formatSizeName($sizeName)
                ];
                $sizeMap[$sizeName] = true;
            }
        }
        
        // Sort sizes in logical order
        usort($sizes, function($a, $b) {
            return $this->compareSizes($a['name'], $b['name']);
        });
        
        return $sizes;
    }
    
    /**
     * Get color hex value (basic color mapping)
     */
    private function getColorHex($colorName, $colorCode) {
        if ($colorCode && strpos($colorCode, '#') === 0) {
            return $colorCode;
        }
        
        // Basic color mapping
        $colorMap = [
            'black' => '#000000',
            'white' => '#FFFFFF',
            'red' => '#FF0000',
            'blue' => '#0000FF',
            'green' => '#008000',
            'yellow' => '#FFFF00',
            'purple' => '#800080',
            'orange' => '#FFA500',
            'pink' => '#FFC0CB',
            'brown' => '#A52A2A',
            'gray' => '#808080',
            'grey' => '#808080',
            'navy' => '#000080',
            'maroon' => '#800000',
            'olive' => '#808000',
            'lime' => '#00FF00',
            'aqua' => '#00FFFF',
            'teal' => '#008080',
            'silver' => '#C0C0C0',
            'fuchsia' => '#FF00FF'
        ];
        
        $colorKey = strtolower($colorName);
        return $colorMap[$colorKey] ?? '#CCCCCC';
    }
    
    /**
     * Format size name for display
     */
    private function formatSizeName($sizeName) {
        $sizeMap = [
            'xs' => 'Extra Small',
            's' => 'Small',
            'm' => 'Medium',
            'l' => 'Large',
            'xl' => 'Extra Large',
            'xxl' => '2X Large',
            '2xl' => '2X Large',
            'xxxl' => '3X Large',
            '3xl' => '3X Large',
            '4xl' => '4X Large',
            '5xl' => '5X Large'
        ];
        
        $sizeKey = strtolower($sizeName);
        return $sizeMap[$sizeKey] ?? $sizeName;
    }
    
    /**
     * Compare sizes for sorting
     */
    private function compareSizes($a, $b) {
        $order = ['xs', 's', 'm', 'l', 'xl', 'xxl', '2xl', 'xxxl', '3xl', '4xl', '5xl'];
        
        $aIndex = array_search(strtolower($a), $order);
        $bIndex = array_search(strtolower($b), $order);
        
        if ($aIndex === false && $bIndex === false) {
            return strcmp($a, $b);
        }
        
        if ($aIndex === false) return 1;
        if ($bIndex === false) return -1;
        
        return $aIndex - $bIndex;
    }
    
    /**
     * Transform product data to match your structure
     */
    private function transformProductData($rawData) {
        try {
            $syncProduct = $rawData['result']['sync_product'] ?? null;
            $syncVariants = $rawData['result']['sync_variants'] ?? [];
            
            if (!$syncProduct || empty($syncVariants)) {
                throw new Exception('Invalid product data structure');
            }
            
            // Get product details
            $productId = $syncVariants[0]['product']['product_id'] ?? $syncProduct['id'];
            $productDetails = $this->getPrintfulProduct($productId);
            $productInfo = $productDetails['result']['product'] ?? [];
            
            // Get category name
            $categoryId = $syncVariants[0]['main_category_id'] ?? $productInfo['main_category_id'] ?? null;
            $categoryName = '';
            if ($categoryId) {
                $categoryData = $this->getCategories($categoryId);
                $categoryName = $categoryData['result']['category']['title'] ?? '';
            }
            
            // Build product object
            $product = [
                'id' => (string)$syncProduct['id'],
                'externalId' => $syncProduct['external_id'] ?? '',
                'name' => $syncProduct['name'] ?? '',
                'description' => $productInfo['description'] ?? '',
                'brand' => $productInfo['brand'] ?? '',
                'model' => $productInfo['model'] ?? '',
                'currency' => $syncVariants[0]['currency'] ?? $productInfo['currency'] ?? 'USD',
                'imageUrl' => $syncProduct['thumbnail_url'] ?? $productInfo['image'] ?? '',
                'mainCategory_ID' => (string)($categoryId ?? ''),
                'mainCategory_Name' => $categoryName,
                'mainProduct_ID' => (string)$productId,
                'originCountry' => $productInfo['origin_country'] ?? '',
                'type' => $productInfo['type_name'] ?? $productInfo['type'] ?? '',
                'material' => $this->extractMaterial($productInfo['description'] ?? ''),
                'price' => $syncVariants[0]['retail_price'] ?? '0'
            ];
            
            // Process variants
            $variants = [];
            foreach ($syncVariants as $v) {
                $variants[] = [
                    'id' => (string)$v['id'],
                    'name' => $v['name'] ?? '',
                    'externalId' => $v['external_id'] ?? '',
                    'size' => $v['size'] ?? '',
                    'color' => $v['color'] ?? '',
                    'price' => (float)($v['retail_price'] ?? 0),
                    'imageUrl' => $v['product']['image'] ?? '',
                    'sku' => $v['sku'] ?? '',
                    'availability' => $v['availability_status'] ?? 'unknown'
                ];
            }
            
            // Extract colors and sizes
            $colors = $this->extractColors($syncVariants);
            $sizes = $this->extractSizes($syncVariants);
            
            return [
                'id' => $this->generateUuidV4(),
                'product' => $product,
                'variants' => $variants,
                'colors' => $colors,
                'sizes' => $sizes
            ];
            
        } catch (Exception $e) {
            $this->log('Error transforming product data: ' . $e->getMessage());
            throw new Exception('Failed to transform product data');
        }
    }
    
    /**
     * Extract material from description
     */
    private function extractMaterial($description) {
        $result = [];

        if (empty($description)) {
            return $result;
        }

        // Match: "<number>% <material name>"
        if (preg_match_all('/(\d+)%\s+([\w\s]+)/u', $description, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result[] = [
                    'name' => trim($match[2]),
                    'pourcentage' => $match[1] . '%'
                ];
            }

            // If only one material → force 100%
            if (count($result) === 1) {
                $result[0]['pourcentage'] = '100%';
            }
        }

        return $result;
    }
    
    /**
     * Make API call to Printful
     */
    private function makeApiCall($endpoint) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API call failed. HTTP Code: $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from API');
        }
        
        return $data;
    }
    
    /**
     * Save products to JSON file
     */
    private function saveToJson($products) {
        $json = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to encode products to JSON');
        }
        
        if (file_put_contents($this->jsonFile, $json) === false) {
            throw new Exception('Failed to write JSON file');
        }
    }
    
    /**
     * Load products from JSON file
     */
    private function loadFromJson() {
        if (!file_exists($this->jsonFile)) {
            return [];
        }
        
        $content = file_get_contents($this->jsonFile);
        $products = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in file');
        }
        
        return $products ?? [];
    }
    
    /**
     * Update specific product in JSON file
     */
    private function updateProductInJson($newProduct) {
        $products = $this->loadFromJson();
        $updated = false;
        
        // Update existing product by sync product ID
        for ($i = 0; $i < count($products); $i++) {
            if ($products[$i]['product']['id'] === $newProduct['product']['id']) {
                // Keep the same UUID, update the rest
                $newProduct['id'] = $products[$i]['id'];
                $products[$i] = $newProduct;
                $updated = true;
                break;
            }
        }
        
        // Add new product if not found
        if (!$updated) {
            $products[] = $newProduct;
        }
        
        $this->saveToJson($products);
    }
    
    /**
     * Log messages
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        echo $logMessage;
    }
}

// Usage example and webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Webhook handler for Printful events
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data && isset($data['type'])) {
        $sync = new PrintfulAutoSync(); // API key loaded from .env
        
        try {
            switch ($data['type']) {
                case 'product_synced':
                case 'product_updated':
                    if (isset($data['data']['sync_product']['id'])) {
                        $sync->syncProductById($data['data']['sync_product']['id']);
                    }
                    break;
                    
                case 'product_deleted':
                    if (isset($data['data']['sync_product']['id'])) {
                        $sync->deleteProduct($data['data']['sync_product']['id']);
                    }
                    break;
            }
            
            http_response_code(200);
            echo json_encode(['status' => 'success']);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    
} else {
    // Manual sync or CLI usage
    if (php_sapi_name() === 'cli') {
        // Try to use API key from command line argument, otherwise use .env
        $apiKey = $argv[1] ?? null;
        
        try {
            $sync = new PrintfulAutoSync($apiKey);
            
            if (isset($argv[2])) {
                // Sync specific product
                $sync->syncProductById($argv[2]);
            } elseif (isset($argv[1]) && !$apiKey) {
                // If first argument is not API key but product ID
                $sync->syncProductById($argv[1]);
            } else {
                // Sync all products
                $sync->syncAllProducts();
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo "Usage: php script.php [API_KEY] [product_id]\n";
            echo "Or set PRINTFUL_API_KEY in .env file\n";
            exit(1);
        }
    } else {
        // Web interface for manual sync
        echo "<!DOCTYPE html>
        <html>
        <head><title>Printful Sync</title></head>
        <body>
            <h1>Printful Product Sync</h1>
            <p>This script handles automatic product synchronization via webhooks.</p>
            <p>For manual sync, use CLI: php script.php [API_KEY] [product_id]</p>
            <p>Or set PRINTFUL_API_KEY in .env file</p>
        </body>
        </html>";
    }
}
?>