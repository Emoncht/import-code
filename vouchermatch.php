<?php

function get_expected_voucher_allocations($order_id) {
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

function get_actual_voucher_allocations($order_id) {
    global $wpdb;
    $actual_allocations = array();

    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');

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

function update_voucher_allocation_match($order_id) {
    global $wpdb;

    // Get the expected and actual voucher allocations
    $expected_allocations = get_expected_voucher_allocations($order_id);
    $actual_allocations = get_actual_voucher_allocations($order_id);

    // Compare the expected and actual allocations
    $allocation_match = ($expected_allocations == $actual_allocations);

    // Store the allocation match result in the post meta
    update_post_meta($order_id, '_voucher_allocation_match', $allocation_match);
}

function update_displayed_voucher_allocation_matches() {
    global $post;

    if (is_admin() && $post && $post->post_type === 'shop_order') {
        $order_id = $post->ID;
        update_voucher_allocation_match($order_id);
    }
}
add_action('admin_head', 'update_displayed_voucher_allocation_matches');

