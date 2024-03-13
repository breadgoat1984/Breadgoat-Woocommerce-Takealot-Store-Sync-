<?php
/**
 * Plugin Name: Breadgoat Woocommerce Takealot Store Sync
 * Description: A plugin to synchronize data with Takealot store. It uses the takealot api to fetch all your products and add them to your woocommerce store. It brings in the primary product image, stock on hand, title, pricing, and SKU. The SKU is the value used to verify everything. if you have existing product this will overwrite that info. 
 * Version: 1.0
 * Author: Breadgoat.com
*/

// Register activation hook
register_activation_hook( __FILE__, 'wp_tal_sync_activate' );

// Include other plugin files or dependencies
// include 'path/to/dependency.php';

// Define your encryption, decryption, and logging functions

/**
 * Activates the plugin by generating and storing a secure encryption key and IV.
 */
function wp_tal_sync_activate() {
    // Generate a secure encryption key
    $secure_key = bin2hex(openssl_random_pseudo_bytes(32)); // 256-bit key
    update_option('wp_tal_sync_secure_key', $secure_key);

    // Generate a secure IV
    $cipher = "aes-256-cbc"; // Example cipher method
    $iv_length = openssl_cipher_iv_length($cipher);
    $secure_iv = openssl_random_pseudo_bytes($iv_length);
    update_option('wp_tal_sync_secure_iv', base64_encode($secure_iv)); // base64_encode for storage

    wp_tal_sync_log('Plugin activated: Secure key and IV generated.');
}

/**
 * Encrypts data using AES-256-CBC.
 *
 * @param string $data Data to be encrypted.
 * @return string|false Encrypted data on success, false on failure.
 */
function wp_tal_sync_encrypt($data) {
    $cipher = "aes-256-cbc";
    $key = get_option('wp_tal_sync_secure_key');
    $iv = base64_decode(get_option('wp_tal_sync_secure_iv'));

    if ($key && $iv) {
        $encrypted = openssl_encrypt($data, $cipher, hex2bin($key), 0, $iv);
        wp_tal_sync_log('Data encrypted successfully.');
        return $encrypted;
    } else {
        wp_tal_sync_log('Encryption failed: Key or IV not set properly.', true);
        // Handle error; Key or IV not set properly
        return false;
    }
}

/**
 * Decrypts data using AES-256-CBC.
 *
 * @param string $data Data to be decrypted.
 * @return string|false Decrypted data on success, false on failure.
 */
function wp_tal_sync_decrypt($data) {
    $cipher = "aes-256-cbc";
    $key = get_option('wp_tal_sync_secure_key');
    $iv = base64_decode(get_option('wp_tal_sync_secure_iv'));

    if ($key && $iv) {
        $decrypted = openssl_decrypt($data, $cipher, hex2bin($key), 0, $iv);
        wp_tal_sync_log('Data decrypted successfully.');
        return $decrypted;
    } else {
        wp_tal_sync_log('Decryption failed: Key or IV not set properly.', true);
        // Handle error; Key or IV not set properly
        return false;
    }
}

/**
 * Logs messages to a plugin-specific log file.
 *
 * @param string $message Message to log.
 * @param bool $is_error Whether the message is an error.
 */
function wp_tal_sync_log($message, $is_error = false) {
    $log_file = plugin_dir_path( __FILE__ ) . 'wp-tal-sync-log.txt';
    $time_stamp = current_time( 'mysql' );
    $log_entry = sprintf( "[%s] %s: %s\n", $time_stamp, $is_error ? 'ERROR' : 'INFO', $message );
    
    file_put_contents( $log_file, $log_entry, FILE_APPEND );
}

/**
 * Adds the plugin settings page to the WordPress admin menu.
 */
function wp_tal_sync_admin_menu() {
    add_menu_page(
        'WP TAL Sync Settings',
        'WP TAL Sync',
        'manage_options',
        'wp_tal_sync_settings',
        'wp_tal_sync_settings_page'
    );
}

/**
 * Displays the settings page for the plugin.
 */
function wp_tal_sync_settings_page() {

    $api_key_placeholder = ''; // Initialize as empty

    // Check if the form has been submitted
    if ( isset( $_POST['wp_tal_sync_save_api_key_nonce'] ) && wp_verify_nonce( $_POST['wp_tal_sync_save_api_key_nonce'], 'wp_tal_sync_save_api_key' ) ) {
        $api_key = sanitize_text_field( $_POST['wp_tal_sync_api_key'] );
        
        // Perform the API key test
        if ( wp_tal_sync_test_api_key( $api_key ) ) {
            // Encrypt and save the API key if the test is successful
            $encrypted_api_key = wp_tal_sync_encrypt( $api_key );
            update_option( 'wp_tal_sync_api_key', $encrypted_api_key );
            echo '<div class="updated"><p>API Key saved and verified successfully.</p></div>';
        } else {
            // API Key verification failed - prompt the user to try again
            echo '<div class="error"><p>API Key verification failed. Please check the key and try again.</p></div>';
            $api_key_placeholder = $api_key; // Show the submitted API key for correction
        }
    }
    ?>
    <div class="wrap">
        <h2>WP TAL Sync Settings</h2>
        <form method="post">
            <?php wp_nonce_field( 'wp_tal_sync_save_api_key', 'wp_tal_sync_save_api_key_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                <th scope="row">API Key</th>
                <td><input type="text" name="wp_tal_sync_api_key" value="<?php echo esc_attr( $api_key_placeholder ); ?>" /></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button-primary" value="Save API Key" />
            </p>
        </form>
        <p style="margin-top: -15px;">This button saves the API key required for synchronizing your products with the TAL service. Ensure your key is valid to prevent synchronization issues.</p>
        
        <button id="wp_tal_sync_manually_sync_offers" class="button-secondary">Manually Sync Offers</button>
        <p>Manually trigger the synchronization of offers with the TAL service. This may take awhile depedning on how many products you have to sync.</p>
        <img id="wp_tal_sync_loading" src="<?php echo admin_url('/images/loading.gif'); ?>" style="display:none;"/>
        <div id="wp_tal_sync_message"></div>
        
        <script type="text/javascript">
            jQuery('#wp_tal_sync_manually_sync_offers').click(function() {
                jQuery('#wp_tal_sync_loading').show();
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'manual_sync_takealot_offers',
                    },
                    success: function(response) {
                        jQuery('#wp_tal_sync_message').html('<p>Success: Offers have been synchronized.</p>');
                        jQuery('#wp_tal_sync_loading').hide();
                    },
                    error: function(response) {
                        jQuery('#wp_tal_sync_message').html('<p>Error: ' + response.responseJSON.data + ' Code: ' + response.responseJSON.code + '</p>');
                        jQuery('#wp_tal_sync_loading').hide();
                    }
                });
            });
        </script>

        <form method="post">
            <?php wp_nonce_field('wp_tal_sync_download_images'); ?>
            <p class="submit">
                <input type="submit" name="wp_tal_sync_download_images" class="button-secondary" value="Download Primary Images For Products" onclick="return confirm('Are you sure you want to download and set product images? This action cannot be undone.');"/>
            </p>
        </form>
        <p style="margin-top: -15px;">Use this button to download and set images for your products. This action will overwrite existing product images. This is scheduled in the background and takes around 10 minutes per 500 products.</p>
        
        <form method="post">
            <?php wp_nonce_field('wp_tal_sync_delete_all_media'); ?>
            <p class="submit">
                <input type="submit" name="wp_tal_sync_delete_all_media" class="button-secondary" value="Delete All Product Media" onclick="return confirm('Are you sure you want to delete all media files associated with products? This action cannot be undone.');"/>
            </p>
        </form>
        <p style="margin-top: -15px;">This button will permanently remove all media files associated with products. Proceed with extreme caution as this action is irreversible. This will not work if products have been deleted.</p>
        
        <form method="post">
        <?php wp_nonce_field('wp_tal_sync_delete_all_products'); ?>
        <p class="submit">
            <input type="submit" name="wp_tal_sync_delete_all_products" class="button-secondary" value="Delete All Products" onclick="return confirm('Are you sure you want to delete all products? This action cannot be undone.');"/>
        </p>
        </form>
        <p style="margin-top: -15px;">This action will permanently delete all products from your WordPress site. Use this feature with caution as it cannot be undone.</p>
        
        <form method="post">
            <p class="submit">
                <input type="submit" name="wp_tal_sync_download_log" class="button-secondary" value="Download Error Log"/>
            </p>
        </form>
        <p style="margin-top: -15px;">Download the plugin's error log file. This can help in diagnosing issues with synchronization or other errors.</p>
        
        </script>
    </div>
    <?php
}

/**
 * Handles the download log action, allowing the log file to be downloaded.
 */
function wp_tal_sync_handle_download_log() {
    if ( isset( $_POST['wp_tal_sync_download_log'] ) && current_user_can( 'manage_options' ) ) {
        $log_file = plugin_dir_path( __FILE__ ) . 'wp-tal-sync-log.txt';
        
        if ( file_exists( $log_file ) ) {
            header( 'Content-Type: text/plain' );
            header( 'Content-Disposition: attachment; filename="wp-tal-sync-log.txt"' );
            readfile( $log_file );
            exit;
        } else {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Error log file does not exist.</p></div>';
            });
        }
    }
}

/**
 * Tests the provided API key by making a request to the Takealot API.
 *
 * @param string $api_key API key to test.
 * @return bool True if the API key is valid, false otherwise.
 */
function wp_tal_sync_test_api_key($api_key) {
    $url = 'https://seller-api.takealot.com/v2/offers?page_number=1&page_size=1';
    $args = array(
        'headers' => array(
            'accept' => 'application/json',
            'Authorization' => 'Key ' . $api_key,
        ),
    );

    // Make the API request
    $response = wp_remote_get($url, $args);

    // Check for WP_Error
    if (is_wp_error($response)) {
        wp_tal_sync_log('API Key Test Failed: ' . $response->get_error_message(), true);
        return false;
    }

    // Check the response code
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code == 200) {
        wp_tal_sync_log('API Key Test Successful.');
        return true;
    } else {
        wp_tal_sync_log('API Key Test Failed: HTTP Status Code ' . $response_code, true);
        return false;
    }
}

/**
 * Fetches all offers from the Takealot API.
 *
 * @return array|WP_Error Offers array on success, WP_Error on failure.
 */
function fetch_all_takealot_offers() {
    $base_url = 'https://seller-api.takealot.com/v2/offers';
    $page_size = 100; // Define the number of items per page
    
    // Retrieve and decrypt the API key
    $encrypted_api_key = get_option('wp_tal_sync_api_key');
    $api_key = wp_tal_sync_decrypt($encrypted_api_key);
    
    if (!$api_key) {
        wp_tal_sync_log('Failed to decrypt API key.', true);
        return new WP_Error('decryption_error', 'Failed to decrypt API key.');
    }
    
    $all_offers = []; // Initialize an array to store all offers
    
    $args = [
        'headers' => [
            'Authorization' => 'Key ' . $api_key,
            'accept' => 'application/json',
        ],
    ];

    // Make the initial API call
    $initial_response = wp_remote_get("{$base_url}?page_number=1&page_size={$page_size}", $args);

    if (is_wp_error($initial_response)) {
        wp_tal_sync_log('Initial API call failed: ' . $initial_response->get_error_message(), true);
        return $initial_response; // Return the error
    }

    $initial_body = wp_remote_retrieve_body($initial_response);
    $initial_data = json_decode($initial_body, true);

    if (!isset($initial_data['total_results'])) {
        wp_tal_sync_log('API call did not return total results.', true);
        return new WP_Error('api_error', 'API call did not return total results.');
    }

    // Calculate the total number of pages
    $total_pages = ceil($initial_data['total_results'] / $page_size);
    wp_tal_sync_log("Total results: {$initial_data['total_results']}. Number of pages: {$total_pages}");

    // Collect offers from all pages
    for ($page = 1; $page <= $total_pages; $page++) {
        $response = wp_remote_get("{$base_url}?page_number={$page}&page_size={$page_size}", $args);

        if (is_wp_error($response)) {
            wp_tal_sync_log('API call for page ' . $page . ' failed: ' . $response->get_error_message(), true);
            continue; // Skip in case of an error and try the next page
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['offers'])) {
            wp_tal_sync_log('API call for page ' . $page . ' did not return offers.', true);
            continue; // Skip if no offers are present and try the next page
        }

        // Append offers to the all_offers array
        $all_offers = array_merge($all_offers, $data['offers']);
        wp_tal_sync_log('API call for page ' . $page . ' succeeded. Offers fetched: ' . count($data['offers']));
    }

    wp_tal_sync_log('Completed fetching offers. Total offers fetched: ' . count($all_offers));

    // Return the aggregated offers data
    return $all_offers;
}

/**
 * Manually triggers the synchronization of offers with the Takealot service.
 */
function manual_sync_takealot_offers() {
    wp_tal_sync_log('Starting manual sync of Takealot offers.');

    $offers = fetch_all_takealot_offers();

    if (is_wp_error($offers)) {
        wp_tal_sync_log('Failed to fetch Takealot offers: ' . $offers->get_error_message(), true);
        wp_send_json_error(['code' => $offers->get_error_code(), 'message' => $offers->get_error_message()]);
        return; // Exit the function to prevent further execution
    }

    if (is_array($offers) && !empty($offers)) {
        // Here you would process the offers, e.g., creating/updating WooCommerce products
        sync_takealot_offers_to_woocommerce($offers); // This line performs the synchronization
        wp_tal_sync_log('First Run - Successfully fetched and processed ' . count($offers) . ' Takealot offers.');
       
        sleep (5);
        sync_takealot_offers_to_woocommerce($offers); // This line performs the synchronization
        
        wp_tal_sync_log('Second Run - Successfully fetched and processed ' . count($offers) . ' Takealot offers.');
        wp_send_json_success(['message' => 'Successfully fetched and processed ' . count($offers) . ' offers.']);
    } else {
        wp_tal_sync_log('Manual sync of Takealot offers completed with an unknown error or no offers fetched.', true);
        wp_send_json_error(['code' => 'unknown_error', 'message' => 'An unknown error occurred or no offers were fetched.']);
    }
}

/**
 * Synchronizes Takealot offers to WooCommerce products.
 *
 * @param array $offers Offers to synchronize.
 */
function sync_takealot_offers_to_woocommerce($offers) {

    foreach ($offers as $offer) {
        $product_id = wc_get_product_id_by_sku($offer['sku']);

        // Prepare meta data
        $meta_data = [
            '_takealot_tsin_id' => $offer['tsin_id'],
            '_takealot_offer_id' => $offer['offer_id'],
            '_barcode' => $offer['barcode'],
            '_product_label_number' => $offer['product_label_number'],
            '_image_url' => $offer['image_url'], // Image downloading handled separately
            '_offer_url' => $offer['offer_url'],
            '_sales_units' => isset($offer['sales_units']) ? json_encode($offer['sales_units']) : '', // Assuming this needs aggregation or conversion
            '_image_hash' => '', // To be filled after image sync
            '_status' => $offer['status'],
            '_storage_fee_eligible' => $offer['storage_fee_eligible'] ? 'yes' : 'no',
            '_discount' => isset($offer['discount']) ? $offer['discount'] : '',
            '_discount_shown' => isset($offer['discount_shown']) ? ($offer['discount_shown'] ? 'yes' : 'no') : 'no',
            // Stock-related attributes
            '_stock_at_takealot' => isset($offer['stock_at_takealot']) ? json_encode($offer['stock_at_takealot']) : '',
            '_stock_on_way' => isset($offer['stock_on_way']) ? json_encode($offer['stock_on_way']) : '',
            '_total_stock_on_way' => isset($offer['total_stock_on_way']) ? $offer['total_stock_on_way'] : 0,
            '_stock_cover' => isset($offer['stock_cover']) ? json_encode($offer['stock_cover']) : '',
            '_total_stock_cover' => isset($offer['total_stock_cover']) ? $offer['total_stock_cover'] : 0,
            '_stock_at_takealot_total' => isset($offer['stock_at_takealot_total']) ? $offer['stock_at_takealot_total'] : 0,
        ];
        

        // Check if product exists
        if ($product_id) {
            // Update existing product
            $product = wc_get_product($product_id);
            wp_tal_sync_log("Updating product: {$product_id}");

            // Update stock and meta data
            $product->set_manage_stock(true);
            $product->set_stock_quantity($offer['leadtime_stock'][0]['quantity_available']); // Simplified
            $product->set_regular_price($offer['rrp']);
            $product->set_sale_price($offer['selling_price']);

            // Update additional meta data
            foreach ($meta_data as $key => $value) {
                update_post_meta($product_id, $key, $value);
            }

            $product->save();
        } else {
            // Create new product
            wp_tal_sync_log("Creating new product for SKU: {$offer['sku']}");

            $product = new WC_Product_Simple();
            $product->set_name($offer['title']);
            $product->set_sku($offer['sku']);
            $product->set_price($offer['selling_price']);
            $product->set_regular_price($offer['rrp']);
            $product->set_stock_quantity($offer['leadtime_stock'][0]['quantity_available']);
            $product->set_manage_stock(true);
            $product->set_status('publish'); // Simplified, consider mapping or default status

            // Set additional meta data
            foreach ($meta_data as $key => $value) {
                $previous_value = get_post_meta($product_id, $key, true);
                update_post_meta($product_id, $key, $value);
                if ($previous_value != $value) {
                    wp_tal_sync_log("Updated meta field '{$key}' for Product ID: {$product_id} from '{$previous_value}' to '{$value}'.");
                } else {
                    wp_tal_sync_log("No change for meta field '{$key}' for Product ID: {$product_id}, remains '{$value}'.");
                }
            }
            

            $product_id = $product->save();

            // Optionally, set product creation date based on Takealot's date_created
            if (!$product->get_date_created()) {
                $post_date = date('Y-m-d H:i:s', strtotime($offer['date_created']));
                wp_update_post([
                    'ID' => $product_id,
                    'post_date' => $post_date,
                    'post_date_gmt' => get_gmt_from_date($post_date),
                ]);
            }
        }

        wp_tal_sync_log("Sync completed for product");
    }
}

/**
 * Handles requests to delete all products or all media files.
 */
function wp_tal_sync_handle_deletion_requests() {
    if (isset($_POST['wp_tal_sync_delete_all_products']) && check_admin_referer('wp_tal_sync_delete_all_products')) {
        wp_tal_sync_delete_all_products();
    } elseif (isset($_POST['wp_tal_sync_delete_all_media']) && check_admin_referer('wp_tal_sync_delete_all_media')) {
        wp_tal_sync_delete_all_media();
    }
}

/**
 * Deletes all products from the WooCommerce store.
 */
function wp_tal_sync_delete_all_products() {
    $args = [
        'post_type' => 'product',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
    ];

    $products = get_posts($args);

    foreach ($products as $product_id) {
        wp_delete_post($product_id, true);
    }

    wp_redirect(add_query_arg('wp_tal_sync_action', 'delete_all_products_done', admin_url('admin.php?page=wp_tal_sync_settings')));
    exit;
}

/**
 * Deletes all media files associated with products.
 */
function wp_tal_sync_delete_all_media() {
    // Query to get all products
    $products = get_posts([
        'post_type' => 'product',
        'numberposts' => -1, // Get all products
        'fields' => 'ids', // Only need the IDs
    ]);

    // Iterate over each product
    foreach ($products as $product_id) {
        // Get all media attached to the product
        $media = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $product_id, // This ensures we're only fetching media attached to this product
            'fields' => 'ids', // Only need the IDs for deletion
        ]);

        // Delete each media item
        foreach ($media as $media_id) {
            wp_delete_attachment($media_id, true); // true to force delete
            wp_tal_sync_log("Deleted media attachment ID $media_id associated with product ID $product_id.", false);
        }
    }

    // Redirect or notify user of completion
    wp_redirect(add_query_arg('wp_tal_sync_action', 'delete_all_media_done', admin_url('admin.php?page=wp_tal_sync_settings')));
    exit;
}

/**
 * Displays admin notices based on actions taken in the plugin settings page.
 */
function wp_tal_sync_admin_notices() {
    if (isset($_GET['wp_tal_sync_action'])) {
        if ($_GET['wp_tal_sync_action'] == 'delete_all_products_done') {
            echo '<div class="notice notice-success"><p>All products have been deleted.</p></div>';
        } elseif ($_GET['wp_tal_sync_action'] == 'delete_all_media_done') {
            echo '<div class="notice notice-success"><p>All media files have been deleted.</p></div>';
        }
    }
}

/**
 * Initiates the process of downloading and setting primary images for products.
 */
function wp_tal_sync_download_images() {
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1, // Adjust based on your needs
    ];

    $products = get_posts($args);

    foreach ($products as $product) {
        try {
            $product_id = $product->ID;
            // Check if the product already has a featured image
            if (has_post_thumbnail($product_id)) {
                // Log that the product already has an image, and skip to the next product
                wp_tal_sync_log("Product ID $product_id already has a featured image. Skipping download.", false);
                continue;
            }
            $image_url = get_post_meta($product_id, '_image_url', true);
            $sku = get_post_meta($product_id, '_sku', true); // Make sure SKU meta key is correct

            if (!empty($image_url) && !empty($sku)) {
                // Proceed to download and attach the image
                $result = wp_tal_sync_download_and_process_image($product_id, $image_url, $sku);
                if (!$result) {
                    wp_tal_sync_log("Failed to download and process image for Product ID $product_id", true);
                } else {
                    wp_tal_sync_log("Successfully downloaded and processed image for Product ID $product_id", false);
                }
            } else {
                wp_tal_sync_log("No image URL or SKU found for Product ID $product_id. Skipping.", true);
            }
        }catch (Exception $e) {
            wp_tal_sync_log("Exception caught for product ID {$product->ID}: " . $e->getMessage(), true);
        }
    }
    wp_tal_sync_log("Download image process completed.", false);
            
}

/**
 * Schedules or triggers the background process for image download.
 */
function wp_tal_sync_handle_image_download() {
    if (isset($_POST['wp_tal_sync_download_images']) && check_admin_referer('wp_tal_sync_download_images')) {
        // Schedule the event if it's not already scheduled
        if (!wp_next_scheduled('wp_tal_sync_download_images_cron')) {
            wp_schedule_single_event(time(), 'wp_tal_sync_download_images_cron');
            wp_tal_sync_log('Scheduled image download task.', false);
        } else {
            wp_tal_sync_log('Image download task already scheduled.', false);
        }

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Image download process scheduled.</p></div>';
        });
    }
}

/**
 * Downloads and processes a product image.
 *
 * @param int $product_id Product ID to associate the image with.
 * @param string $image_url URL of the image to download.
 * @param string $sku SKU of the product, used to generate the image file name.
 * @return int|false Attachment ID on success, false on failure.
 */
function wp_tal_sync_download_and_process_image($product_id, $image_url, $sku) {
    // Step 1: Download the image to a temporary file
    $temp_image_path = wp_tal_sync_download_image_to_temp($image_url);
    if (!$temp_image_path) {
        // This is where the specific log message can be added for failure
        wp_tal_sync_log("ERROR: Failed to download image for product ID: $product_id", true);
        return false;
    }

    // Prepare the file for sideloading
    $file_array = wp_tal_sync_prepare_file_for_sideload($temp_image_path, $sku);
    if (is_wp_error($file_array)) {
        wp_tal_sync_log("Error preparing file for upload: " . $file_array->get_error_message(), true);
        @unlink($temp_image_path); // Clean up
        return false;
    }

    // Step 2: Upload and attach the image to the product
    $attachment_id = wp_tal_sync_upload_and_attach_image($file_array, $product_id);
    if (is_wp_error($attachment_id)) {
        wp_tal_sync_log("Failed to upload and attach image for SKU: $sku", true);
        @unlink($temp_image_path); // Clean up
        return false;
    }

    // Clean up the temporary file
    @unlink($temp_image_path);

    wp_tal_sync_log("Image processed and attached for SKU: $sku with attachment ID: $attachment_id");
    return $attachment_id;
}

/**
 * Prepares a file for sideloading into WordPress.
 *
 * @param string $temp_file_path Temporary file path.
 * @param string $sku SKU of the product, used in the file name.
 * @return array File array suitable for media_handle_sideload().
 */
function wp_tal_sync_prepare_file_for_sideload($temp_file_path, $sku) {
    // Ensure the file exists
    if (!file_exists($temp_file_path)) {
        return new WP_Error('file_not_found', 'The temporary file does not exist.');
    }

    // Mimic the $_FILES array format
    $file_array = array(
        'name' => 'talwc_' . $sku . '.jpg', // Desired filename
        'tmp_name' => $temp_file_path, // Temporary file path on the server
        'error' => 0,
        'size' => filesize($temp_file_path),
    );

    return $file_array;
}

/**
 * Uploads and attaches an image to a product.
 *
 * @param array $file_array Array representing the file to upload.
 * @param int $post_id Product ID to attach the image to.
 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
 */
function wp_tal_sync_upload_and_attach_image($file_array, $post_id) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Sideload the image file into the Media Library
    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        wp_tal_sync_log('Sideload failed: ' . $attachment_id->get_error_message(), true);
        return $attachment_id; // Return the error
    }

    // Set the image as the featured image of the post/product
    set_post_thumbnail($post_id, $attachment_id);

    return $attachment_id;
}

/**
 * Downloads an image from a URL to a temporary file.
 *
 * @param string $image_url URL of the image to download.
 * @return string|false Path to the temporary file on success, false on failure.
 */
function wp_tal_sync_download_image_to_temp($image_url) {
    $response = wp_remote_get($image_url);

    if (is_wp_error($response)) {
        wp_tal_sync_log("HTTP request error: " . $response->get_error_message(), true);
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code != 200) {
        wp_tal_sync_log("HTTP request returned status code $http_code for $image_url", true);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        wp_tal_sync_log("Downloaded image body is empty", true);
        return false;
    }

    $temp_image_path = tempnam(sys_get_temp_dir(), 'WP_TAL');
    if (!@file_put_contents($temp_image_path, $body)) {
        wp_tal_sync_log("Failed to write the image to temp file", true);
        return false;
    }

    return $temp_image_path;
}

/**
 * Background process handler for downloading images.
 */
function wp_tal_sync_process_image_download() {
    // Call the original function that handles image downloading
    wp_tal_sync_download_images(); // Ensure this function is adapted for background processing
}

// Add action and filter hooks
/**
 * Schedules the image download task upon plugin activation or when triggered manually.
 */
add_action('wp_tal_sync_download_images_cron', 'wp_tal_sync_process_image_download');

/**
 * Registers the function to run when the plugin's settings page is loaded, which handles the downloading log action.
 */
add_action( 'admin_init', 'wp_tal_sync_handle_download_log' );

/**
 * Adds the plugin's settings page to the WordPress admin menu.
 */
add_action( 'admin_menu', 'wp_tal_sync_admin_menu' );

/**
 * Handles the manual synchronization of Takealot offers through an AJAX request in the admin.
 */
add_action('wp_ajax_manual_sync_takealot_offers', 'manual_sync_takealot_offers');

/**
 * Processes deletion requests for all products or all media files, initiated from the plugin's settings page.
 */
add_action('admin_init', 'wp_tal_sync_handle_deletion_requests');

/**
 * Displays admin notices based on query parameters, typically set after redirecting from a form submission.
 */
add_action('admin_notices', 'wp_tal_sync_admin_notices');
// add_action('wp_ajax_nopriv_manual_sync_takealot_offers', 'manual_sync_takealot_offers'); // If needed for non-logged-in users

/**
 * Triggers the handling of the image download process, ensuring it's set up upon plugin actions or form submissions.
 */
add_action('admin_init', 'wp_tal_sync_handle_image_download');

// add_filter('another_hook', 'your_function_name');

// Shortcode, widgets, and other initializations
// add_shortcode('your_shortcode', 'your_shortcode_handler');