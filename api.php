<?php

function create_voucher_allocations_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'voucher_allocations';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        unique_identifier VARCHAR(255) NOT NULL,
        order_id INT NOT NULL,
        allocated_vouchers TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate";

    $wpdb->query($sql);
}
add_action('init', 'create_voucher_allocations_table');













function send_order_data_to_api($order_id, $old_status, $new_status) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'payment_check';

    // Get the order object
    $order = wc_get_order($order_id);

    // Check if the user role is 'pending' or 'banned_user'
    $user_id = $order->get_user_id();
    $user = new WP_User($user_id);
    if (in_array('pending', $user->roles) || in_array('banned_user', $user->roles)) {
        $order->add_order_note('Order not processed due to user role being Pending or Banned.');
        return;
    }

    // Check if the order ID is found more than once in the payment check table
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE order_id = %d", $order_id));

if ($count > 1) {
    // Add an order note
    $order->add_order_note('Duplicate entries found in payment check table.');
    
    // Update the order status to 'waiting'
    $order->update_status('waiting', __('Order status updated to waiting due to duplicate entries.', 'text-domain'));
    
    return; // Early return
}


    // Retrieve UniPin server status
    $server_status = get_option('unipin_server_status', 'off');
    if ($server_status === 'off') {
        return;
    }

    // Check if the new status is 'processing'
    if ($new_status !== 'processing') {
        return;
    }

    // Check if the API request payload has already been stored for the order
    if (get_post_meta($order_id, '_api_request_payload_stored', true)) {
        $order->add_order_note('Already sent');
        return;
    }

    // Generate a unique identifier for the voucher allocation request
    $unique_identifier = uniqid($order_id . '_', true);

    // Check if the unique identifier already exists in the database
    $existing_allocation = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}voucher_allocations WHERE unique_identifier = %s",
            $unique_identifier
        )
    );

    if ($existing_allocation > 0) {
        $order->add_order_note('Voucher allocation request already processed. Skipping allocation.');
        return;
    }

    $payment_method = $order->get_payment_method();
    $order_number = $order->get_order_number();

    // Get the transaction ID
    $transaction_id = '';
    $meta_keys = array('woo_bkash_trans_id', 'woo_nagad_trans_id', 'woo_rocket_trans_id');
    foreach ($meta_keys as $meta_key) {
        $transaction_id = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $order_id, $meta_key));
        if (!empty($transaction_id)) {
            break;
        }
    }

    // If trxid not found in the database, generate a random 10-digit trxid
    if (empty($transaction_id)) {
        $transaction_id = mt_rand(1000000000, 9999999999);
    }

    // Check for duplicate requests
    if (is_duplicate_request_in_file($transaction_id) || is_duplicate_request_in_database($transaction_id)) {
        $order->add_order_note('Multiple order with one trxid detected.. Request not sent.');
        log_api_request($transaction_id, 'Duplicate request detected. Request not sent.');
        return;
    }

    // Initialize variables for voucher checking
    $required_vouchers = array();
    $has_insufficient_codes = false;
    $stockout = array();

    // 1. Order Analysis and 2. Voucher Mapping
    $items = $order->get_items();
    foreach ($items as $item_id => $item) {
        $variation_id = $item->get_variation_id();
        $quantity = $item->get_quantity();

        // Get voucher mappings
        $voucher_mappings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT voucher_value, voucher_quantity, topup_url
                FROM {$wpdb->prefix}unipin_voucher_mapping
                WHERE variation_id = %d",
                $variation_id
            ),
            ARRAY_A
        );

        if (empty($voucher_mappings)) {
            $order->add_order_note('Variation ID not found in the mapping table. Request not sent.');
            log_api_request($transaction_id, 'Variation ID not found in the mapping table. Request not sent.');
            return;
        }

        foreach ($voucher_mappings as $mapping) {
            $voucher_value = $mapping['voucher_value'];
            $required_quantity = $mapping['voucher_quantity'] * $quantity;
            
            if (!isset($required_vouchers[$voucher_value])) {
                $required_vouchers[$voucher_value] = 0;
            }
            $required_vouchers[$voucher_value] += $required_quantity;
        }
    }

    // 3. Voucher Availability Check and 4. Sufficiency Determination
    foreach ($required_vouchers as $voucher_value => $required_quantity) {
        $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
        
        $available_quantity = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE used = 'no'"
            )
        );

        if ($available_quantity < $required_quantity) {
            $has_insufficient_codes = true;
            $stockout[] = $voucher_value;
        }
    }

    // Handle insufficient vouchers
    if ($has_insufficient_codes) {
        $stockout_message = implode(', ', $stockout);
        $order->update_status('wc-stock-out', "Insufficient unused voucher codes: $stockout_message. Order marked as restock.");
        log_api_request($transaction_id, "Insufficient unused voucher codes: $stockout_message. Order marked as restock.");
        return;
    }

    // If we reach here, we have sufficient vouchers. Proceed with order processing.
    $order_items_data = array();
    $used_voucher_codes = array();
    $allocated_vouchers = array();

    foreach ($items as $item_id => $item) {
        $product = $item->get_product();
        $parent_product_id = $product->get_parent_id();
        $product_id = $parent_product_id ? $parent_product_id : $product->get_id();
        $variation_id = $item->get_variation_id();
        $variation_data = $variation_id ? $product->get_variation_attributes() : array();
        $variation_name = !empty($variation_data) ? implode(', ', $variation_data) : '';
        
 // Get Player ID
    $player_id = wc_get_order_item_meta($item->get_id(), 'Player ID', true);
    
    // Check if Player ID is not available
    if (empty($player_id)) {
        $order->update_status('vuul-uid', __('Player ID not available. Order status updated to vuul-uid.', 'text-domain'));
        $order->add_order_note('Player ID not available for item. Order status updated.');
        log_api_request($transaction_id, 'Player ID not available for item. Order status updated to vuul-uid.');
        return; // Exit the function if Player ID is missing
    }        
        
        
        
        
        
        
        
        
        $voucher_data = array();

        $voucher_mappings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT voucher_value, voucher_quantity, topup_url
                FROM {$wpdb->prefix}unipin_voucher_mapping
                WHERE variation_id = %d",
                $variation_id
            ),
            ARRAY_A
        );

        foreach ($voucher_mappings as $mapping) {
            $voucher_value = $mapping['voucher_value'];
            $voucher_quantity = $mapping['voucher_quantity'] * $item->get_quantity();
            $topup_url = $mapping['topup_url'];

            // Modify the voucher_value based on the mapping
            $voucher_value = modify_voucher_value($voucher_value);

            // Retrieve unused voucher codes
            $table_name = $wpdb->prefix . 'unipin_voucher_' . $mapping['voucher_value'];
            $unused_vouchers = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT voucher
                    FROM $table_name
                    WHERE used = 'no'
                    LIMIT %d",
                    $voucher_quantity
                )
            );

            $voucher_data[] = array(
                'voucher_value' => $voucher_value,
                'voucher_quantity' => $voucher_quantity,
                'voucher_codes' => $unused_vouchers,
            );

            // Store the used voucher codes for later update
            $used_voucher_codes[$table_name] = isset($used_voucher_codes[$table_name])
                ? array_merge($used_voucher_codes[$table_name], $unused_vouchers)
                : $unused_vouchers;

            // Add to allocated vouchers
            $allocated_vouchers[] = array(
                'voucher_value' => $voucher_value,
                'voucher_codes' => $unused_vouchers,
            );
        }

        $item_data = array(
            'player_id' => $player_id,
            'items' => array(
                array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'variation_name' => $variation_name,
                    'amount' => $product->get_price() . 'BDT',
                    'quantity' => $item->get_quantity(),
                    'voucher_data' => $voucher_data
                )
            ),
            'parent_product_id' => $product_id,
            'topup_url' => $topup_url
        );

        $order_items_data[] = $item_data;
    }

    // Prepare order data
    $order_data = array(
        'domain' => get_site_url(),
        'order_id' => $order_number,
        'order_items' => $order_items_data,
        'status' => 'waiting',
        'trxid' => strval($transaction_id)
    );

    // Store the JSON payload in wp_postmeta table
    update_post_meta($order_id, '_unipin_order_payload', json_encode($order_data));

    // Add order note with the formatted JSON payload
    $json_payload = json_encode($order_data, JSON_PRETTY_PRINT);
    $order->add_order_note('API request payload stored. JSON payload:' . "\n" . $json_payload);

    // Set the flag to indicate that the API request payload has been stored for the order
    update_post_meta($order_id, '_api_request_payload_stored', true);

    // Insert the voucher allocation details into the database
    $allocation_data = array(
        'unique_identifier' => $unique_identifier,
        'order_id' => $order_id,
        'allocated_vouchers' => json_encode($allocated_vouchers),
    );

    $insert_result = $wpdb->insert(
        "{$wpdb->prefix}voucher_allocations",
        $allocation_data
    );

    if ($insert_result === false) {
        $error_message = $wpdb->last_error;
        $order->add_order_note("Failed to insert voucher allocation data. Error: " . $error_message);
        error_log("Failed to insert voucher allocation data for order {$order_id}. Error: " . $error_message);
        return; // Stop processing if allocation insertion fails
    } else {
        $order->add_order_note("Voucher allocation data inserted successfully. ID: " . $wpdb->insert_id);
    }

    // Update the voucher codes as used
    foreach ($used_voucher_codes as $table_name => $voucher_codes) {
        $voucher_codes_str = "'" . implode("','", $voucher_codes) . "'";
        $update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name
                SET used = 'yes', used_time = %s, order_id = %d
                WHERE voucher IN ($voucher_codes_str)",
                current_time('mysql'), $order_id
            )
        );

        if ($update_result === false) {
            $error_message = $wpdb->last_error;
            $order->add_order_note("Failed to update voucher codes as used in table {$table_name}. Error: " . $error_message);
            error_log("Failed to update voucher codes as used in table {$table_name} for order {$order_id}. Error: " . $error_message);
            return; // Stop processing if voucher update fails
        } else {
            $order->add_order_note("Updated {$update_result} voucher codes as used in table {$table_name}.");
        }
    }

    // Update the order status to 'waiting'
    $order->update_status('waiting', 'API request payload stored. Order status changed to Waiting.');
}

function modify_voucher_value($voucher_value) {
    $mapping = array(
        '20' => '25 Diamond',
        '36' => '50 Diamond',
        '80' => '115 Diamond',
        '160' => '240 Diamond',
        '405' => '610 Diamond',
        '810' => '1240 Diamond',
        '1625' => '2530 Diamond',
        '161' => 'Weekly Membership',
        '162' => 'Level Up Pass',
        '800' => 'Monthly Membership'
    );

    return isset($mapping[$voucher_value]) ? $mapping[$voucher_value] : $voucher_value;
}

add_action('woocommerce_order_status_changed', 'send_order_data_to_api', 10, 3);







function get_actual_voucher_allocations_by_order($order_id) {
    global $wpdb;
    $actual_allocations = array();

    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '161', '800', '162');

    foreach ($voucher_values as $voucher_value) {
        $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

        $allocation_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE used = 'yes' AND order_id = %d",
                $order_id
            )
        );

        if ($allocation_count > 0) {
            $actual_allocations[$voucher_value] = $allocation_count;
        }
    }

    return $actual_allocations;
}



function get_expected_voucher_allocations_by_order($order_id) {
    global $wpdb;
    $order = wc_get_order($order_id);
    $items = $order->get_items();

    $expected_allocations = array();

    foreach ($items as $item) {
        $variation_id = $item->get_variation_id();

        $voucher_mappings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT voucher_value, voucher_quantity
                FROM {$wpdb->prefix}unipin_voucher_mapping
                WHERE variation_id = %d",
                $variation_id
            ),
            ARRAY_A
        );

        foreach ($voucher_mappings as $mapping) {
            $voucher_value = $mapping['voucher_value'];
            $voucher_quantity = $mapping['voucher_quantity'] * $item->get_quantity();

            if (isset($expected_allocations[$voucher_value])) {
                $expected_allocations[$voucher_value] += $voucher_quantity;
            } else {
                $expected_allocations[$voucher_value] = $voucher_quantity;
            }
        }
    }

    return $expected_allocations;
}



function compare_voucher_allocations($order_id) {
    $actual_allocations = get_actual_voucher_allocations_by_order($order_id);
    $expected_allocations = get_expected_voucher_allocations_by_order($order_id);

    $order = wc_get_order($order_id);

    foreach ($expected_allocations as $voucher_value => $expected_quantity) {
        $actual_quantity = isset($actual_allocations[$voucher_value]) ? $actual_allocations[$voucher_value] : 0;

        if ($actual_quantity != $expected_quantity) {
            $order->add_order_note("Voucher allocation mismatch for value $voucher_value. Expected: $expected_quantity, Actual: $actual_quantity.");
            return false;
        }
    }

    foreach ($actual_allocations as $voucher_value => $actual_quantity) {
        if (!isset($expected_allocations[$voucher_value])) {
            $order->add_order_note("Unexpected voucher allocation for value $voucher_value. Actual: $actual_quantity, but no expected allocation found.");
            return false;
        }
    }

    return true;
}



function send_order_data_to_unipin_server($order_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'payment_check';

    // Check if the order ID is found more than once in the payment check table
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE order_id = %d", $order_id));

    if ($count > 1) {
        $order = wc_get_order($order_id);
        $order->add_order_note('Order data not sent to UniPin server. Duplicate entries found in payment check table.');
        return;
    }

    $order = wc_get_order($order_id);

    // Check if the order status is 'waiting'
    if ($order->get_status() !== 'waiting') {
        return;
    }

    // Compare voucher allocations
    if (!compare_voucher_allocations($order_id)) {
        $order->add_order_note('Voucher allocations do not match. API request not sent.');
        return;
    }

    // Get the JSON payload from the post meta
    $json_payload = get_post_meta($order_id, '_unipin_order_payload', true);
    if (empty($json_payload)) {
        $order->add_order_note('No API request payload found for this order.');
        return;
    }

    // Get the server URL from the backend
    $server_url = get_option('unipin_voucher_server_url', '');
    if (!empty($server_url)) {
        $max_retries = 3;
        $retry_count = 0;

        while ($retry_count < $max_retries) {
            // Send the request using wp_remote_post with blocking set to false
            $response = wp_remote_post($server_url, array(
                'method' => 'POST',
                'body' => $json_payload,
                'headers' => array('Content-Type' => 'application/json'),
                'blocking' => false, // Set to false to make the request non-blocking
            ));

            // Check for any errors in the API request
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $order->add_order_note('API request failed. Error: ' . $error_message);
                error_log('API request failed for order #' . $order_id . '. Error: ' . $error_message);

                $retry_count++;
                if ($retry_count < $max_retries) {
                    $order->add_order_note('Retrying API request. Attempt #' . ($retry_count + 1));
                    continue;
                } else {
                    $order->add_order_note('API request failed after ' . $max_retries . ' attempts.');
                    break;
                }
            }

            // Add order note with the formatted JSON payload
            $order->add_order_note('API request sent. JSON payload:' . "\n" . $json_payload);

            // Set the flag to indicate that the API request has been sent for the order
            update_post_meta($order_id, '_api_request_sent', true);

            // Change the order status to "loading"
            $order->update_status('wc-loading', 'API request sent. Order status changed to Loading.');

            // Log the request
            log_api_request($order_id, $json_payload);

            break;
        }
    } else {
        $error_message = 'Unipin server URL not configured. Failed to send API request.';
        $order->add_order_note($error_message);
        error_log($error_message);
    }
}

add_action('woocommerce_order_status_changed', 'send_order_data_to_unipin_server', 10, 1);


function is_duplicate_request_in_file($transaction_id) {
    $log_file = WP_CONTENT_DIR . '/uploads/apiprevention.txt';

    if (!file_exists($log_file)) {
        file_put_contents($log_file, '');
    }

    $log_data = file_get_contents($log_file);
    $log_entries = explode("\n", $log_data);

    foreach ($log_entries as $entry) {
        if (strpos($entry, $transaction_id) !== false) {
            return true;
        }
    }

    return false;
}

function is_duplicate_request_in_database($transaction_id) {
    global $wpdb;
    $meta_keys = array('woo_bkash_trans_id', 'woo_nagad_trans_id', 'woo_rocket_trans_id');

    $duplicate_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ('" . implode("','", $meta_keys) . "') 
                AND meta_value = %s
            ORDER BY meta_id DESC
            LIMIT 30",
            $transaction_id
        )
    );

    return $duplicate_count > 1;
}

function log_api_request($transaction_id, $message) {
    $log_file = WP_CONTENT_DIR . '/uploads/apireq.txt';
    if (!file_exists($log_file)) {
        file_put_contents($log_file, '');
    }

    $log_entry = date('Y-m-d H:i:s') . ' - ' . $transaction_id . ' - ' . $message . "\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}




// Register the new "loading" order status
function register_loading_order_status() {
    register_post_status('wc-loading', array(
        'label' => 'Loading',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Loading <span class="count">(%s)</span>', 'Loading <span class="count">(%s)</span>')
    ));
}
add_action('init', 'register_loading_order_status');

// Add the "loading" order status to the list of WooCommerce order statuses
function add_loading_to_order_statuses($order_statuses) {
    $new_order_statuses = array();
    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-processing' === $key) {
            $new_order_statuses['wc-loading'] = 'Loading';
        }
    }
    return $new_order_statuses;
}
add_filter('wc_order_statuses', 'add_loading_to_order_statuses');





function display_low_stock_voucher_notice() {
    global $wpdb;
    $low_stock_vouchers = array();
    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '161', '800', '162');

    foreach ($voucher_values as $voucher_value) {
        $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
        $query = "SELECT COUNT(*) FROM `$table_name` WHERE used = 'no'";
        $stock_count = $wpdb->get_var($query);

        if ($stock_count < 20) {
            $low_stock_vouchers[$voucher_value] = $stock_count;
        }
    }

    if (!empty($low_stock_vouchers)) {
        $low_stock_message = 'Low stock vouchers: ';

        foreach ($low_stock_vouchers as $voucher_value => $stock) {
            $low_stock_message .= $voucher_value . ' (' . $stock . '), ';
        }

        $low_stock_message = rtrim($low_stock_message, ', ');
        $low_stock_message .= '. Please add new stock for these vouchers.';

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>' . $low_stock_message . '</p>';
        echo '</div>';
    }
}

add_action('admin_notices', 'display_low_stock_voucher_notice');



// Create the "waiting" order status if it doesn't exist
function create_waiting_order_status() {
    $status_slug = 'wc-waiting';
    $status_label = 'Waiting';

    // Check if the "waiting" order status already exists
    $existing_status = get_term_by('slug', $status_slug, 'shop_order_status');

    if (!$existing_status) {
        // Create the "waiting" order status
        wp_insert_term(
            $status_label,
            'shop_order_status',
            array(
                'slug' => $status_slug,
                'label' => $status_label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop($status_label . ' <span class="count">(%s)</span>', $status_label . ' <span class="count">(%s)</span>')
            )
        );
    }

    // Register the "waiting" post status
    register_post_status($status_slug, array(
        'label' => $status_label,
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop($status_label . ' <span class="count">(%s)</span>', $status_label . ' <span class="count">(%s)</span>')
    ));
}
add_action('init', 'create_waiting_order_status');

// Add the "waiting" order status to the list of WooCommerce order statuses
function add_waiting_to_order_statuses($order_statuses) {
    $new_order_statuses = array();
    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ('wc-processing' === $key) {
            $new_order_statuses['wc-waiting'] = 'Waiting';
        }
    }
    return $new_order_statuses;
}
add_filter('wc_order_statuses', 'add_waiting_to_order_statuses');