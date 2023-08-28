const axios = require('axios');
const iconv = require('iconv-lite');
const WooCommerceAPI = require('@woocommerce/woocommerce-rest-api').default;
const http = require('http');

// Create an HTTP agent with keep-alive enabled
const httpAgent = new http.Agent({ keepAlive: true });

// Configure WooCommerce API client with the created HTTP agent
const WooCommerce = new WooCommerceAPI({
  url: 'https://www.thezoostation.gr',
  consumerKey: 'ck_208577f0ff5011c800530b7105e5ffa0af475034',
  consumerSecret: 'cs_bd4c7801dcb194062d5af26f815cf5ed1e9e7f68',
  wpAPI: true,
  version: 'wc/v3',
  encoding: 'utf8',
  axiosConfig: {
    httpAgent: httpAgent,

  }
});

async function login() {
  try {
    const loginUrl = 'https://chouchoumis.oncloud.gr/s1services';
    console.log('Login URL:', loginUrl); // Log Login URL

    const loginPayload = {
      service: 'login',
      username: 'web',
      password: 'HJbsoi&ged93bg*Hb98fbhu',
      appId: '2011',
    };

    console.log('Logging in...');
    const loginResponse = await axios.post(loginUrl, loginPayload);
    if (loginResponse.data.success) return loginResponse.data.clientID;
    else throw new Error(loginResponse.data.error);
  } catch (error) {
    console.error("%cFailed to login:", "color: red", error.message);
    throw error;
  }
}

async function authenticate(clientID) {
  try {
    const authenticateUrl = 'https://chouchoumis.oncloud.gr/s1services';

    const authenticatePayload = {
      service: 'authenticate',
      clientID,
      COMPANY: '1000',
      BRANCH: '1',
      REFID: '264',
    };

    console.log('Authenticating...');
    const authenticateResponse = await axios.post(authenticateUrl, authenticatePayload);
    console.log('%cAuthentication Successful', 'color: green');
    return authenticateResponse.data.clientID;
  } catch (error) {
    console.error('%cFailed to authenticate:', 'color: red', error.message);
    throw error;
  }
}

async function fetchProductsFromERP() {
  try {
    // Login and get token
    const clientID = await login();

    // Authenticate with the obtained token
    const authenticateResponse = await authenticate(clientID);

    // Refresh data from ERP API
    const refreshUrl = 'https://chouchoumis.oncloud.gr/s1services?refresh=refresh';
    console.log('Refreshing data from ERP...');
    await axios.get(refreshUrl, {
      headers: {
        Authorization: `Bearer ${clientID}`,
      },
    });
    console.log('%cData refreshed from ERP', 'color: green');

    // Get product data from ERP API
    const productsUrl = 'https://chouchoumis.oncloud.gr/s1services/JS/API.v1/products';
    
    const productsPayload = {
      clientID: authenticateResponse,
      upddate: '2023/08/23 08:00',
    };

    console.log('Fetching products from ERP...');
    const productsResponse = await axios.post(productsUrl, new URLSearchParams(productsPayload).toString(), {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=utf-8',
        Authorization: `Bearer ${clientID}`,
      },
      responseType: 'arraybuffer',
      transformResponse: [(data) => iconv.decode(data, 'iso-8859-7')],
    });

    const responseData = JSON.parse(productsResponse.data.toString('utf-8'));

    // Check if products exist in the response data
    if (responseData && responseData.success && Array.isArray(responseData.data)) {
      const products = responseData.data;

      // Process and map ERP product data to WooCommerce product data
      const woocommerceProducts = products.map((product) => {
        const woocommerceProduct = {
          name: product.NAME,
          type: 'simple',
          sku: product.SKU,
          regular_price: product.PRICER,
          manage_stock: true,
          stock_quantity: parseInt(product.BALANCE),
          ean: product.BARCODE,
          factory_code: product.FACTORYCODE,
        };

        return woocommerceProduct;
      });
      console.log('%cData from ERP were retrieved', 'color: green');
      return woocommerceProducts;
    } else {
      console.error('%cProducts data not found in the response.', 'color: red');
      return [];
    }
  } catch (error) {
    console.error('%cFailed to fetch products from ERP:', 'color: red', error.message);
    throw error;
  }
}

async function createOrUpdateProductInWooCommerce(product) {
  let retries = 3; // Maximum number of retries
  let delay = 5000; // Initial delay is 5 seconds

  while (retries > 0) {
    try {
      const encodedEAN = encodeURIComponent(product.ean);
      const getProductsUrl = `products?ean=${encodedEAN}`;
      console.log('Get Products URL:', getProductsUrl);

      // Check if the product already exists in WooCommerce
      const existingProductResponse = await WooCommerce.get(getProductsUrl);

      if (existingProductResponse.data && Array.isArray(existingProductResponse.data)) {
        const existingProduct = existingProductResponse.data.find((p) =>
          p.meta_data.some((meta) => meta.key === '_alg_ean' && meta.value === product.ean)
        );

        if (existingProduct) {
          const shouldUpdate = shouldUpdateProduct(existingProduct, product);
          if (shouldUpdate) {
            console.log(`%cProduct "${product.name}" is outdated in WooCommerce. Updating product...`, 'color: green');
        
            // Update the product in WooCommerce with the new data
            const updatedProduct = createUpdatedProductData(existingProduct, product);
            const updateProductUrl = `products/${existingProduct.id}`;
            await WooCommerce.put(updateProductUrl, updatedProduct);
            console.log(`%cProduct "${product.name}" updated in WooCommerce.`, 'color: green');
          } else {
            console.log('%cProduct in WooCommerce is up to date. Skipping update...', 'color: blue');
          }
        } else {
          console.log('%cProduct does not exist in WooCommerce. Creating product...', 'color: green');
          // Create the product in WooCommerce
          const createProductUrl = 'products';
          
          // Set the product status as 'draft' if you want to review the products before publishing them
          product.status = 'draft';

          // Set the necessary meta_data and other product properties
          product.meta_data = [
            {
              key: '_alg_ean',
              value: product.ean,
            },
            {
              key: '_factory_code',
              value: product.factory_code,
            },
          ];
          product.stock_quantity = parseInt(product.stock_quantity);

          await WooCommerce.post(createProductUrl, product);
          console.log(`%cProduct "${product.name}" created in WooCommerce.`, 'color: green');
        }
      }

      // If the request is successful, break the loop
      break;
    } catch (error) {
      if (error.response && (error.response.status === 429 || error.response.status === 504 || error.response.status === 500 || error.response.status === 403 || error.response.status === 503 ||  error.response.status === 502)) {
        // If a rate limit error or gateway timeout occurs, wait for the specified delay and then retry
        console.log(`Error ${error.response.status} occurred. Retrying in ${delay / 1000} seconds...`);
        await new Promise(resolve => setTimeout(resolve, delay));

        // Double the delay for the next potential retry
        delay *= 2;

        // Decrement the retry count
        retries--;
      } else if (error.response && error.response.status === 400) {
        // Skip the error silently when the product already exists
        console.log(`Product "${product.name}" already exists in WooCommerce. Skipping...`);
        break;
      } else {
        console.error('%cFailed to create or update product in WooCommerce:', 'color: red', error.message);
        throw error;
      }
    }
  }
}



function shouldUpdateProduct(existingProduct, newProduct) {
  // Compare the relevant properties of existingProduct and newProduct to determine if an update is needed
  // Modify the logic according to your specific requirements
  if (
    existingProduct.name !== newProduct.name ||
    existingProduct.regular_price !== newProduct.regular_price ||
    existingProduct.stock_quantity !== newProduct.stock_quantity ||
    existingProduct.sku !== newProduct.sku ||
    existingProduct.factory_code !== newProduct.factory_code
     ) 
      
      {
    return true;
  }
  return false;
}

function createUpdatedProductData(existingProduct, newProduct) {
  // Create an updated product object by merging the existingProduct and newProduct
  // Modify the logic according to your specific requirements
  return {
    ...existingProduct,
    name: newProduct.name,
    regular_price: newProduct.regular_price,
    stock_quantity: parseInt(newProduct.stock_quantity),
    sku: newProduct.sku,
    factory_code: newProduct.factory_code,
  };
}


async function integrateProducts() {
  try {
    // Fetch products from ERP
    const products = await fetchProductsFromERP();
    const totalProducts = products.length;
    console.log(`%cTotal products fetched: ${totalProducts}`, 'color: green'); // Logging total products

    // Batch processing and parallelize product creation/update
    const batchSize = 15; // Number of products to process in each batch (adjusted to be conservative)
    const batchCount = Math.ceil(totalProducts / batchSize);
    console.log(`%cTotal batches to be processed: ${batchCount}`, 'color: green'); // Logging total batches
    const rateLimit = 10000; // milliseconds delay between each batch to avoid rate limits (10 seconds)

    for (let i = 0; i < batchCount; i++) {
      const startIdx = i * batchSize;
      const endIdx = Math.min((i + 1) * batchSize, totalProducts);
      const batchProducts = products.slice(startIdx, endIdx);

      // Prepare an array to store the promises for each product in the current batch
      const batchPromises = [];

      // Process the products in the current batch
      batchProducts.forEach((product, index) => {
        const promise = createOrUpdateProductInWooCommerce(product)
          .catch((error) => {
            console.error(`%cFailed to process product ${startIdx + index + 1} of ${totalProducts}:`, 'color: red', error.message);
            throw error;
          });
        batchPromises.push(promise);
      });

      // Wait for all promises in the batch to resolve
      await Promise.all(batchPromises);

      const progress = ((i + 1) / batchCount) * 100;
      console.log(`Processed batch ${i + 1} of ${batchCount} - ${progress.toFixed(2)}%`);

      // Wait before processing the next batch to avoid rate limits
      if (i < batchCount - 1) {
        let countdown = rateLimit / 1000;
        const intervalId = setInterval(() => {
          console.log(`Waiting for the next batch... ${countdown} seconds remaining`);
          countdown--;
          if (countdown < 0) {
            clearInterval(intervalId);
          }
        }, 1000);

        await new Promise(resolve => setTimeout(resolve, rateLimit));
        clearInterval(intervalId);
      }
    }

    console.log('%cProduct integration completed successfully.', 'color: green');
  } catch (error) {
    console.error('%cProduct integration failed:', 'color: red', error.message);
  }
}

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function retry(func, args, maxRetries = 4, retryInterval = 10000) {
  try {
    return await func(...args);
  } catch (error) {
    if (maxRetries === 0) throw error;
    console.error(`An error occurred. Retrying in ${retryInterval / 1000} seconds... (${maxRetries} retries left)`);
    await sleep(retryInterval);
    return retry(func, args, maxRetries - 1, retryInterval);
  }
}

integrateProducts();