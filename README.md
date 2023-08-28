# softWoo
SoftOne (ERP) - WooCommerce Interconnection

The script automates the integration of product data between SoftOne and WooCommerce platforms, while handling any potential errors. It follows these main steps:

1) Setup and Configuration:

Loads necessary libraries and sets the timezone.
Defines error handling for logging and sending error emails.
Retry Mechanism:

Provides a function for retrying operations with backoff delays in case of exceptions.

2) Mailer Configuration:

Sets up email configuration and error reporting using PHPMailer.

3) WooCommerce and HTTP Client Setup:

Initializes connections to WooCommerce and an HTTP client.

4) Login and Authentication with SoftOne:

Logs into SoftOne using credentials and retrieves a client ID.
Authenticates the client ID for further API interactions.

5) Fetching Products from SoftOne:

Retrieves product data from SoftOne's API.
Converts data to a usable format and handles potential errors.

6) Product Update Check and Fields:

Compares product data for updates and prepares data for WooCommerce.

7) Product Management in WooCommerce:

Handles updating and creating products in WooCommerce.
Manages different product types and errors that might occur.

8) Integrate Products:

Orchestrates the entire integration process.
Fetches SoftOne products, compares and manages them in WooCommerce.
Processes products in batches and handles errors.

9) Executing Integration:

Initiates the integration process by calling the integrateProducts function.
In essence, the script streamlines the synchronization of product information between SoftOne and WooCommerce platforms, ensuring data accuracy and reliability while handling potential errors.
