<?php
// Include the necessary WordPress files
require_once '/home/u159033492/domains/asibur.com/public_html/wp-load.php';

// Get the order ID from the POST data
$order_id = $_POST['order_id'];

// Retrieve the order data and send the API request
$order = wc_get_order($order_id);
$payment_method = $order->get_payment_method();

if (in_array($payment_method, array('woo_bkash', 'woo_nagad', 'woo_rocket'))) {
    if ($order->get_status() !== 'waiting') {
        exit;
    }
} else {
    if ($order->get_status() !== 'processing') {
        exit;
    }
}

global $wpdb;
$order_number = $order->get_order_number();

// Get the ID from the wp_postmeta table
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

// Prepare the order items data
$items = $order->get_items();
$order_items_data = array();

foreach ($items as $item_id => $item) {
    $product = $item->get_product();
    $parent_product_id = $product->get_parent_id();
    $product_id = $parent_product_id ? $parent_product_id : $product->get_id();
    $variation_id = $item->get_variation_id();

    // Check if the variation ID exists in the wp_unipin_voucher_mapping table
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
        // Variation ID not found in the mapping table, skip sending the request for this order
        exit;
    }

    $variation_data = $variation_id ? $product->get_variation_attributes() : array();
    $variation_name = '';
    if (!empty($variation_data)) {
        $variation_name = implode(', ', $variation_data);
    }

    // Retrieve the player_id from the order item meta
    $player_id = wc_get_order_item_meta($item->get_id(), 'Player ID', true);
    $voucher_data = array();

    foreach ($voucher_mappings as $mapping) {
        $voucher_value = $mapping['voucher_value'];
        $voucher_quantity = $mapping['voucher_quantity'] * $item->get_quantity();
        $topup_url = $mapping['topup_url'];

        // Modify the voucher_value based on the mapping
        switch ($voucher_value) {
            case '20':
                $voucher_value = '25 Diamond';
                break;
            case '36':
                $voucher_value = '50 Diamond';
                break;
            case '80':
                $voucher_value = '115 Diamond';
                break;
            case '160':
                $voucher_value = '240 Diamond';
                break;
            case '405':
                $voucher_value = '610 Diamond';
                break;
            case '810':
                $voucher_value = '1240 Diamond';
                break;
            case '1625':
                $voucher_value = '2530 Diamond';
                break;
            case '161':
                $voucher_value = 'Weekly Membership';
                break;
            case '162':
                $voucher_value = 'Level Up Pass';
                break;
            case '800':
                $voucher_value = 'Monthly Membership';
                break;
            default:
                // Keep the original voucher_value if not matched
                break;
        }

        // Retrieve unused voucher codes from the corresponding table
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

        if (count($unused_vouchers) === $voucher_quantity) {
            $voucher_data[] = array(
                'voucher_value' => $voucher_value,
                'voucher_quantity' => $voucher_quantity,
                'voucher_codes' => $unused_vouchers,
            );

            // Update the voucher codes as used
            foreach ($unused_vouchers as $code) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $table_name
                        SET used = 'yes', order_id = %d, used_time = NOW()
                        WHERE voucher = %s",
                        $order_id,
                        $code
                    )
                );
            }
        } else {
            // Insufficient unused voucher codes, skip sending the request for this order
            exit;
        }
    }

    if (empty($voucher_data)) {
        // No voucher data available for this item, skip sending the request for this order
        exit;
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

// Get the domain from the site URL
$domain = get_site_url();

$order_data = array(
    'domain' => $domain,
    'order_id' => $order_number,
    'order_items' => $order_items_data,
    'status' => $payment_method === 'cod' ? 'processing' : 'waiting',
    'trxid' => strval($transaction_id)
);

// Get the server URL from the backend
$server_url = get_option('unipin_voucher_server_url', '');

if (!empty($server_url)) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $server_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

    // Execute the request and wait for the response
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Add order note with the response and HTTP code when the request is completed
    $order->add_order_note('API response received. HTTP code: ' . $http_code . ', Response: ' . $response);

    // Handle the API response
    handle_api_response($order_id, $response, $http_code);
}