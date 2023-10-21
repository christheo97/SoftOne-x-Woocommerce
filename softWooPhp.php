<?php

use GuzzleHttp\Exception\RequestException;
require_once 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
use Automattic\WooCommerce\Client;
use GuzzleHttp\Client as HttpClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use GuzzleHttp\HandlerStack;

date_default_timezone_set('Europe/Athens');
set_exception_handler("errorHandler");

// Centralized Exception and Error Handler
function errorHandler($exception, $context = '') {
    $errorMessage = "[" . date('d-m-y H:i:s') . "] Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . PHP_EOL;

    if (!empty($context)) {
        $errorMessage .= "Context: " . $context . PHP_EOL;
    }

    // Add stack trace to the error log
    $errorMessage .= "Stack Trace: " . $exception->getTraceAsString() . PHP_EOL;

    errorToLogfile($errorMessage);
    sendErrorEmail($errorMessage);
}

function errorToLogfile($errorMessage) {
    $logFile = __DIR__ . '/logfile.log';
    file_put_contents($logFile, date("Y-m-d H:i:s") . " " . $errorMessage . "\n", FILE_APPEND);
}

// Configure the mailer
function configureMailer() {
    try {
        $mailer = new PHPMailer(true);

        // Configure mailer settings
        $mailer->isSMTP();
        $mailer->Host = $_ENV['SMTP_HOST'];
        $mailer->Port = $_ENV['SMTP_PORT'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $_ENV['SMTP_USERNAME'];
        $mailer->Password = $_ENV['SMTP_PASSWORD'];
        $mailer->CharSet = 'UTF-8';

        return $mailer;
    } catch (Exception $e) {
        errorHandler($e, "Error configuring mailer");
        return null;
    }
}

// Sending error email
function sendErrorEmail($errorMessage) {
    $mailer = configureMailer();

    if ($mailer !== null) {
        try {
            // Configure mailer settings
            $mailer->setFrom($_ENV['SMTP_USERNAME'], 'Zoo x SoftOne');
            $mailer->addAddress($_ENV['SMTP_USERNAME'], 'Dev');
            $mailer->Subject = 'Error Report';
            $mailer->Body = $errorMessage;

            $mailer->send();
            echo "Error Email Sent Successfully." . PHP_EOL;
        } catch (Exception $e) {
            errorHandler($e, "Email Sending Error");
        }
    }
}

$woocommerce = new Client(
    $_ENV['WOO_SITE_URL'],
    $_ENV['WOO_CK'],
    $_ENV['WOO_CS'],
    [
        'version' => 'wc/v3',
        'timeout' => 400,
    ]
);

$http = new HttpClient([
    'headers' => [
        'Content-Type' => 'application/json',
        'Accept-Charset' => 'utf-8',
    ]
]);

function login(){
    global $http;
    $loginUrl = $_ENV['SOFTONE_LOGIN_URL'];
    $loginPayload = [
        'service' => 'login',
        'username' => $_ENV['SOFTONE_USERNAME'],
        'password' => $_ENV['SOFTONE_PASSWORD'],
        'appId' => '2011',
    ];

    try {
        $response = $http->post($loginUrl, ['json' => $loginPayload]);
        $result = $response->getBody()->getContents();
        // Convert response to ISO-8859-7 encoding
        $result = mb_convert_encoding($result, 'UTF-8', 'ISO-8859-7');

        // Decode JSON response
        $result = json_decode($result, true);

        if ($result && isset($result['success']) && $result['success']) {
            return $result['clientID'];
        } elseif ($result && isset($result['error'])) {
            throw new Exception('Login failed: ' . $result['error']);
        } else {
            throw new Exception('Invalid request. Please login first.');
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        exit; // Exit the script if the website is down
    }
}

function authenticate($clientID){
    global $http;
    $authenticateUrl = $_ENV['SOFTONE_LOGIN_URL'];
    $authenticatePayload = [
        'service' => 'authenticate',
        'clientID' => $clientID,
        'COMPANY' => '1000',
        'BRANCH' => '1',
        'REFID' => '264',
    ];

    try {
        $response = $http->post($authenticateUrl, ['json' => $authenticatePayload]);
        $result = $response->getBody()->getContents();

         // Manually decode the JSON string
        $result = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $result);
        $result = json_decode($result, true);

        if ($result && isset($result['success']) && $result['success']) {
            return $result['clientID'];
        } else {
            throw new Exception('Invalid request. Please login first.');
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}

function fetchProductsFromSoftone($clientID) {
    global $http;
    try {
        // Refresh and fetch URLs
        $refreshUrl = $_ENV['SOFTONE_REFRESH_URL'];
        $productsUrl = $_ENV['SOFTONE_PRODUCTS_URL'];

        // Refresh token
        $http->get($refreshUrl, ['headers' => ['Authorization' => "Bearer {$clientID}"]]);

        // Calculate datetime 5 mins ago
        $currentDate = new DateTime('now', new DateTimeZone('Europe/Athens'));
        $currentDate->sub(new DateInterval('PT5M'));

        // Payload
        $productsPayload = [
            'clientID' => $clientID,
            'upddate' => $currentDate->format('Y/m/d H:i'),
        ];

        // Fetch products
        $response = $http->post($productsUrl, [
            'form_params' => $productsPayload,
            'headers' => ['Authorization' => "Bearer {$clientID}"]
        ]);

        // Process response
        $responseData = json_decode(mb_convert_encoding($response->getBody()->getContents(), 'UTF-8', 'ISO-8859-7'), true);

        // Populate WooCommerce product array
        $woocommerceProducts = [];
        if ($responseData['success'] && is_array($responseData['data'])) {
            foreach ($responseData['data'] as $product) {
                $regular_price = isset($product['PRICER']) ? $product['PRICER'] : 0;
                $discount1 = isset($product['SODISCOUNT']) ? $product['SODISCOUNT'] : 0;
                $discount2 = isset($product['SODISCOUNT1']) ? $product['SODISCOUNT1'] : 0;
                $sale_price = $regular_price * (1 - $discount1 / 100) * (1 - $discount2 / 100);
            
                $woocommerceProducts[] = [
                    'name' => $product['NAME'],
                    'type' => 'simple',
                    'sku' => $product['SKU'] ?? 'N/A',
                    'regular_price' => $regular_price,
                    'sale_price' => number_format($sale_price, 2, '.', ''),
                    'stock_quantity' => intval($product['BALANCE']),
                    'ean' => $product['BARCODE'],
                    'factory_code' => $product['FACTORYCODE'] ?? '',
                    'discount1' => $discount1,
                    'discount2' => $discount2,
                ];
            }
        }
        return $woocommerceProducts;

    } catch (RequestException $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        if ($e->hasResponse()) {
            echo 'Response Body: ' . $e->getResponse()->getBody()->getContents() . PHP_EOL;
        }
    }
}



function productUpdateCheck($newProduct, $wooProducts){
    $wooEAN = '';
    $wooFactoryCode = '';

    foreach ($wooProducts->meta_data as $meta) {
        if ($meta->key === '_alg_ean') {
            $wooEAN = $meta->value;
        }

        if ($meta->key === '_factory_code') {
            $wooFactoryCode = $meta->value;
        }
    }

    return (
        $wooProducts->name !== $newProduct['name'] ||
        $wooProducts->regular_price !== $newProduct['regular_price'] ||
        $wooProducts->sale_price !== $newProduct['sale_price'] ||
        $wooProducts->stock_quantity !== $newProduct['stock_quantity'] ||
        $wooProducts->sku !== $newProduct['sku'] ||
        $wooEAN !== $newProduct['meta_data'][0]['value'] ||
        $wooFactoryCode !== $newProduct['meta_data'][1]['value']
    );
}

function productFields($product){
    return [
        'name' => $product['name'],
        'type' => 'simple',
        'sku' => $product['sku'],
        'regular_price' => $product['regular_price'],
        'sale_price' => $product['sale_price'],
        'manage_stock' => true,
        'stock_quantity' => $product['stock_quantity'],
        'stock_status' => $product['stock_quantity'] > 0 ? 'instock' : 'outofstock',
        'meta_data' => [
            ['key' => '_alg_ean', 'value' => $product['ean']],
            ['key' => '_factory_code', 'value' => $product['factory_code']],
        ],
         // Add other fields for new product creation
    ];
}

function createOrUpdateProductInWooCommerce($product) {
    global $woocommerce;

    $productName = isset($product['name']) ? $product['name'] : '';
    $productEAN = isset($product['ean']) ? $product['ean'] : '';
    $productSKU = isset($product['sku']) ? $product['sku'] : '';

    try {
        // Check if the product exists in WooCommerce based on EAN, SKU
        $wooProducts = [];

        if (!empty($productEAN)) {
            $wooProducts = $woocommerce->get('products', ['ean' => $productEAN]);
        } elseif (!empty($productSKU)) {
            $wooProducts = $woocommerce->get('products', ['sku' => $productSKU]);
        } else {
            echo "Product '{$productName}' doesn't have EAN or SKU. Skipping update." . PHP_EOL;
            return;
        }

        if (!empty($wooProducts)) {
            foreach ($wooProducts as $wooProduct) {
                $factory_code = null;
                foreach ($wooProduct->meta_data as $meta) {
                    if ($meta->key == '_factory_code') {
                        $factory_code = $meta->value;
                    }
                }

                // Handle different product types: Simple, Variation, New Product

                if ($wooProduct->type === 'simple') {
                    // Handle simple product update
                    $productId = $wooProduct->id;
                    echo "(SIMPLE) product '{$productName}' found in WooCommerce. Checking for updates..." . PHP_EOL;

                    $productData = productFields($product);

                    if (productUpdateCheck($productData, $wooProduct)) {
                        echo "The product is outdated in WooCommerce. Updating..." . PHP_EOL;
                        $newProductData['meta_data'][] = [
                            'key' => '_factory_code',
                            'value' => isset($productData['factory_code']) ? $productData['factory_code'] : ''
                        ];
                        $newProductData['sale_price'] = $product['sale_price'];
                        $productData['manage_stock'] = true;
                        $productData['stock_status'] = $productData['stock_quantity'] > 0 ? 'instock' : 'outofstock'; // Update the stock status too
                        $woocommerce->put("products/{$productId}", $productData);
                        echo "The product has been updated in WooCommerce." . PHP_EOL;
                    } else {
                        echo "The product is up to date in WooCommerce. Skipping update." . PHP_EOL;
                    }

                } elseif ($wooProduct->type === 'variation') {
                    // Handle variation update
                    $variationId = $wooProduct->id; // Variation ID in WooCommerce
                    $productId = $wooProduct->parent_id; // Parent product ID in WooCommerce

                    echo "(VARIATION) product '{$productName}' found in WooCommerce. Checking for updates..." . PHP_EOL;

                    if (
                        $product['regular_price'] === $wooProduct->regular_price &&
                        $product['stock_quantity'] === $wooProduct->stock_quantity &&
                        $product['sku'] === $wooProduct->sku &&
                        $product['sale_price'] === $wooProduct->sale_price &&
                        $product['factory_code'] === $factory_code
                    ) {
                        echo "The product is up to date in WooCommerce. Skipping update." . PHP_EOL;
                        continue;
                    } else {
                        echo "The product is outdated in WooCommerce. Updating..." . PHP_EOL;

                        $updatedVariationData = [
                            'regular_price' => $product['regular_price'],
                            'sale_price' => $product['sale_price'],
                            'stock_quantity' => $product['stock_quantity'],
                            'sku' => $product['sku'],
                            'meta_data' => [
                                [
                                    'key' => 'factory_code',
                                    'value' => $product['factory_code']
                                ]
                            ]
                        ];

                        $woocommerce->put("products/{$productId}/variations/{$variationId}", $updatedVariationData);
                        echo "The product has been updated in WooCommerce." . PHP_EOL;
                    }
                }

            }
        } else {
            // Product not found, create a new product
            echo "Product '{$productName}' not found in WooCommerce. Creating new simple product..." . PHP_EOL;

            $newProductData = productFields($product);
            $newProductData['status'] = 'draft';
            $woocommerce->post('products', $newProductData);
            echo "New simple product '{$productName}' created as a draft in WooCommerce." . PHP_EOL;
        }
    } catch (HTTPClientException $e) {
        // Handle the WooCommerce API error
        if (strpos($e->getMessage(), 'product_invalid_sku') !== false) {
            echo "WooCommerce API Error: Invalid SKU for product '{$productName}'. EAN: '{$productEAN}'" . PHP_EOL;
        } else {
            $errorMessage = "[{$productName}] WooCommerce API Error: " . $e->getMessage();
            echo $errorMessage . "\n";
            errorToLogfile($errorMessage);
            sendErrorEmail($errorMessage);
        }
    } catch (Exception $e) {
        $errorMessage = "An error occurred: " . $e->getMessage();
        echo $errorMessage . "\n";
        errorToLogfile($errorMessage);
        sendErrorEmail($errorMessage);
    }
}

function integrateProducts(){
    global $woocommerce;


       // Login to get the client ID
       $clientID = login();

       if ($clientID === null) {
           echo "Error logging in.";
           return;
       }
   
       // Authenticate with the obtained client ID
       $authenticatedClientID = authenticate($clientID);
   
       if ($authenticatedClientID === null) {
           echo "Error authenticating.";
           return;
       }
   

    // Assume that getProductsFromSOFTONE() will return an array of all your products
    $softOneproducts = fetchProductsFromSoftone($authenticatedClientID); 
    if (empty($softOneproducts)) {
        echo "No new updates detected in SoftOne.\n";
        return;
    }

        // Get the total number of products
        $totalProducts = count($softOneproducts);
        echo "Total Products: {$totalProducts}\n";
    
// Define batch processing parameters
$batchSize = 10; // Number of products to process in each batch
$delayBetweenBatches = 5; // Delay between batches in seconds

// Process products in batches
for ($i = 0; $i < $totalProducts; $i += $batchSize) {
    echo "Processing batch " . ($i / $batchSize + 1) . "\n";
    $batchProducts = array_slice($softOneproducts, $i, $batchSize);

    $batchUpdates = [];

    foreach ($batchProducts as $product) {
        try {
            $updatedProduct = createOrUpdateProductInWooCommerce($product);
            if ($updatedProduct) {
                $batchUpdates[] = $updatedProduct;
            }
        } catch (Exception $e) {
            errorHandler($e, "Error processing product: " . $product['name']);
        }
    }

    // Update the products in a batch
    if (!empty($batchUpdates)) {
        try {
            echo "Updating batch " . ($i / $batchSize + 1) . "\n";
            $woocommerce->post('products/batch', ['update' => $batchUpdates]);
        } catch (HTTPClientException $e) {
            $errorMessage = "WooCommerce API Error: " . $e->getMessage();
            echo $errorMessage . "\n";
            errorToLogfile($errorMessage);
            sendErrorEmail($errorMessage);
        } catch (Exception $e) {
            $errorMessage = "An error occurred: " . $e->getMessage();
            echo $errorMessage . "\n";
            errorToLogfile($errorMessage);
            sendErrorEmail($errorMessage);
        }
    }

    // Delay between batches
    if ($i + $batchSize < $totalProducts) {
        sleep($delayBetweenBatches);
    }
}

}


integrateProducts();
