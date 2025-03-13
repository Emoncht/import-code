<?php
// Add menu for WooCommerce integration
function unipin_voucher_woocommerce_menu() {
    add_menu_page(
        'UniPin Voucher WooCommerce Integration',
        'UniPin Voucher WC',
        'manage_options',
        'unipin-voucher-woocommerce',
        'unipin_voucher_woocommerce_settings_callback',
        'dashicons-cart',
        56
    );
}
add_action('admin_menu', 'unipin_voucher_woocommerce_menu');

// Add settings page for WooCommerce integration
function unipin_voucher_woocommerce_settings_page() {
    add_submenu_page(
        'unipin-voucher-woocommerce',
        'WooCommerce Integration Settings',
        'Settings',
        'manage_options',
        'unipin-voucher-woocommerce-settings',
        'unipin_voucher_woocommerce_settings_callback'
    );
}
add_action('admin_menu', 'unipin_voucher_woocommerce_settings_page');


function unipin_voucher_woocommerce_settings_callback() {
    $server_url = get_option('unipin_voucher_server_url', '');
    $server_url_1 = get_option('unipin_voucher_server_url_1', '');
    $server_url_2 = get_option('unipin_voucher_server_url_2', '');
    $server_status = get_option('unipin_server_status', 'off');

    echo '<div class="wrap">';
    echo '<h1>WooCommerce Integration Settings</h1>';

    echo '<form id="unipin-server-url-form">';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="server_url">Server URL</label></th>';
    echo '<td><input type="text" name="server_url" id="server_url" value="' . esc_attr($server_url) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="server_url_1">Server URL 1</label></th>';
    echo '<td><input type="text" name="server_url_1" id="server_url_1" value="' . esc_attr($server_url_1) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="server_url_2">Server URL 2</label></th>';
    echo '<td><input type="text" name="server_url_2" id="server_url_2" value="' . esc_attr($server_url_2) . '" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="server_status">Server Status</label></th>';
    echo '<td>
            <label><input type="radio" name="server_status" value="on" ' . checked($server_status, 'on', false) . '> On</label>
            <label><input type="radio" name="server_status" value="off" ' . checked($server_status, 'off', false) . '> Off</label>
          </td>';
    echo '</tr>';
    echo '</table>';
    wp_nonce_field('update_unipin_server_url_nonce', 'unipin_server_url_nonce');
    echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save"></p>';
    echo '</form>';

    echo '<h2>WooCommerce Products</h2>';
    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Product Name</th>';
    echo '<th>Action</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    $products = wc_get_products(array('limit' => -1));
    foreach ($products as $product) {
        echo '<tr>';
        echo '<td>' . $product->get_name() . '</td>';
        echo '<td><a href="' . admin_url('admin.php?page=unipin-voucher-product-settings&product_id=' . $product->get_id()) . '" class="button">Configure</a></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';

    // Inline JavaScript
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script type="text/javascript">
    jQuery(document).ready(function ($) {
        $("#unipin-server-url-form").on("submit", function (e) {
            e.preventDefault();
            
            var server_url = $("#server_url").val();
            var server_url_1 = $("#server_url_1").val();
            var server_url_2 = $("#server_url_2").val();
            var server_status = $("input[name=\'server_status\']:checked").val();
            var nonce = $("#unipin-server-url-form").find("[name=\'unipin_server_url_nonce\']").val();

            $("#submit").val("Saving...").prop("disabled", true);

            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "update_unipin_server_url",
                    server_url: server_url,
                    server_url_1: server_url_1,
                    server_url_2: server_url_2,
                    server_status: server_status,
                    unipin_server_url_nonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        Swal.fire({
                            icon: "success",
                            title: "Success",
                            text: response.data,
                        });
                    } else {
                        Swal.fire({
                            icon: "error",
                            title: "Error",
                            text: response.data,
                        });
                    }
                    $("#submit").val("Save").prop("disabled", false);
                },
                error: function () {
                    Swal.fire({
                        icon: "error",
                        title: "Error",
                        text: "An error occurred while updating the server URL.",
                    });
                    $("#submit").val("Save").prop("disabled", false);
                }
            });
        });
    });
    </script>';
}

// AJAX handler to update UniPin server URLs and status
function update_unipin_server_url() {
    if (!isset($_POST['unipin_server_url_nonce']) || !wp_verify_nonce($_POST['unipin_server_url_nonce'], 'update_unipin_server_url_nonce')) {
        wp_send_json_error('Nonce verification failed');
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to perform this action');
    }

    if (isset($_POST['server_url'], $_POST['server_url_1'], $_POST['server_url_2'], $_POST['server_status'])) {
        $server_url = sanitize_text_field($_POST['server_url']);
        $server_url_1 = sanitize_text_field($_POST['server_url_1']);
        $server_url_2 = sanitize_text_field($_POST['server_url_2']);
        $server_status = sanitize_text_field($_POST['server_status']) === 'on' ? 'on' : 'off';

        update_option('unipin_voucher_server_url', $server_url);
        update_option('unipin_voucher_server_url_1', $server_url_1);
        update_option('unipin_voucher_server_url_2', $server_url_2);
        update_option('unipin_server_status', $server_status);

        wp_send_json_success('Settings updated successfully');
    } else {
        wp_send_json_error('Invalid data');
    }
}
add_action('wp_ajax_update_unipin_server_url', 'update_unipin_server_url');





// Add product settings page for UniPin voucher mapping
function unipin_voucher_product_settings_page() {
    add_submenu_page(
        'unipin-voucher-woocommerce',
        'Product Settings',
        'Product Settings',
        'manage_options',
        'unipin-voucher-product-settings',
        'unipin_voucher_product_settings_callback'
    );
}
add_action('admin_menu', 'unipin_voucher_product_settings_page');

function unipin_voucher_product_settings_callback() {
    global $wpdb;

    if (isset($_GET['product_id'])) {
        $product_id = intval($_GET['product_id']);
        $product = wc_get_product($product_id);

        if ($product) {
            if (isset($_POST['unipin_voucher_mapping'])) {
                $mapping = $_POST['unipin_voucher_mapping'];
                $topup_urls = $_POST['topup_url'];

                // Delete existing mapping data for the product
                $wpdb->delete(
                    "{$wpdb->prefix}unipin_voucher_mapping",
                    array('product_id' => $product_id),
                    array('%d')
                );

                foreach ($mapping as $variation_id => $data) {
                    $topup_url = isset($topup_urls[$variation_id]) ? $topup_urls[$variation_id] : '';

                    foreach ($data['values'] as $index => $value) {
                        $quantity = isset($data['quantities'][$index]) ? $data['quantities'][$index] : '';
                        if (!empty($quantity) && $quantity !== 'none') {
                            $wpdb->insert(
                                "{$wpdb->prefix}unipin_voucher_mapping",
                                array(
                                    'product_id' => $product_id,
                                    'variation_id' => $variation_id,
                                    'voucher_value' => $value,
                                    'voucher_quantity' => $quantity,
                                    'topup_url' => $topup_url,
                                ),
                                array('%d', '%d', '%s', '%d', '%s')
                            );
                        }
                    }
                }
            }

            if (isset($_POST['reset_mapping'])) {
                $variation_id = intval($_POST['variation_id']);
                $wpdb->delete(
                    "{$wpdb->prefix}unipin_voucher_mapping",
                    array(
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                    ),
                    array('%d', '%d')
                );
            }

            $mapping = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT vm.variation_id, vm.voucher_value, vm.voucher_quantity, vm.topup_url, p.post_title AS variation_name
                    FROM {$wpdb->prefix}unipin_voucher_mapping vm
                    JOIN {$wpdb->posts} p ON vm.variation_id = p.ID
                    WHERE vm.product_id = %d
                    ORDER BY vm.variation_id, vm.voucher_value",
                    $product_id
                ),
                ARRAY_A
            );

            $mapped_variations = array();
            foreach ($mapping as $data) {
                $variation_id = $data['variation_id'];
                $voucher_value = $data['voucher_value'];
                $voucher_quantity = $data['voucher_quantity'];
                $variation_name = $data['variation_name'];
                $topup_url = $data['topup_url'];

                if (!isset($mapped_variations[$variation_id])) {
                    $mapped_variations[$variation_id] = array(
                        'variation_name' => $variation_name,
                        'voucher_data' => array(),
                        'topup_url' => $topup_url,
                    );
                }

                $mapped_variations[$variation_id]['voucher_data'][] = array(
                    'voucher_value' => $voucher_value,
                    'voucher_quantity' => $voucher_quantity,
                );
            }

            echo '<div class="wrap">';
            echo '<h1>Product Settings: ' . $product->get_name() . '</h1>';

            echo '<form method="post" class="form-horizontal">';

            $variations = $product->get_available_variations();
            foreach ($variations as $variation) {
                $variation_id = $variation['variation_id'];
                $variation_name = implode(', ', $variation['attributes']);

                echo '<h3>' . $variation_name . '</h3>';
                echo '<div class="form-group">';
                echo '<label for="topup_url_' . $variation_id . '">Top-up URL:</label>';
                echo '<input type="text" name="topup_url[' . $variation_id . ']" id="topup_url_' . $variation_id . '" value="' . (isset($mapped_variations[$variation_id]['topup_url']) ? $mapped_variations[$variation_id]['topup_url'] : '') . '" placeholder="Enter top-up URL" class="form-control">';
                echo '</div>';

                echo '<table class="table table-striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>UniPin Voucher Value</th>';
                echo '<th>Quantity</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                echo '<tr>';
                echo '<td>';
                echo '<select name="unipin_voucher_mapping[' . $variation_id . '][values][]" class="form-control unipin-voucher-select">';
                echo '<option value="">Select UniPin Voucher Value</option>';

                $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');
                foreach ($voucher_values as $value) {
                    echo '<option value="' . $value . '">' . $value . '</option>';
                }

                echo '</select>';
                echo '</td>';
                echo '<td>';
                echo '<select name="unipin_voucher_mapping[' . $variation_id . '][quantities][]" class="form-control">';
                echo '<option value="none">None</option>';
                for ($i = 1; $i <= 100; $i++) {
                    echo '<option value="' . $i . '">' . $i . '</option>';
                }
                echo '</select>';
                echo '</td>';
                echo '</tr>';

                if (isset($mapped_variations[$variation_id])) {
                    $selected_data = $mapped_variations[$variation_id]['voucher_data'];

                    foreach ($selected_data as $index => $data) {
                        echo '<tr>';
                        echo '<td>';
                        echo '<select name="unipin_voucher_mapping[' . $variation_id . '][values][]" class="form-control unipin-voucher-select">';
                        echo '<option value="">Select UniPin Voucher Value</option>';

                        foreach ($voucher_values as $voucher_value) {
                            $selected = ($data['voucher_value'] == $voucher_value) ? 'selected' : '';
                            echo '<option value="' . $voucher_value . '" ' . $selected . '>' . $voucher_value . '</option>';
                        }

                        echo '</select>';
                        echo '</td>';
                        echo '<td>';
                        echo '<select name="unipin_voucher_mapping[' . $variation_id . '][quantities][]" class="form-control">';
                        echo '<option value="none">None</option>';
                        for ($i = 1; $i <= 100; $i++) {
                            $selected = ($data['voucher_quantity'] == $i) ? ' selected' : '';
                            echo '<option value="' . $i . '"' . $selected . '>' . $i . '</option>';
                        }
                        echo '</select>';
                        echo '</td>';
                        echo '</tr>';
                    }
                }

                echo '</tbody>';
                echo '</table>';
            }

            echo '<div class="form-group">';
            echo '<div class="col-sm-offset-2 col-sm-10">';
            echo '<button type="submit" name="submit" id="submit" class="btn btn-primary">Save Changes</button>';
            echo '</div>';
            echo '</div>';
            echo '</form>';

            echo '<h2>Mapped Variations</h2>';
echo '<div class="table-responsive">';
echo '<table class="table table-striped table-bordered table-hover">';
echo '<thead class="thead-dark">';
echo '<tr>';
echo '<th>Variation ID</th>';
echo '<th>Variation Name</th>';
echo '<th>UniPin Value and Quantity</th>';
echo '<th>Top-up URL</th>';
echo '<th>Action</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

$row_class = '';
foreach ($mapped_variations as $variation_id => $data) {
    $variation_name = $data['variation_name'];
    $voucher_data = $data['voucher_data'];
    $topup_url = $data['topup_url'];

    $row_class = ($row_class == '') ? 'table-primary' : 'table-secondary';

    echo '<tr class="' . $row_class . '">';
    echo '<td>#' . $variation_id . '</td>';
    echo '<td>' . $variation_name . '</td>';
    echo '<td>';
    $unipin_values = array_map(function ($item) {
        return $item['voucher_value'] . ' UC * ' . $item['voucher_quantity'];
    }, $voucher_data);
    echo implode(' and ', $unipin_values);
    echo '</td>';
    echo '<td>' . $topup_url . '</td>';
    echo '<td>';
    echo '<form method="post">';
    echo '<input type="hidden" name="variation_id" value="' . $variation_id . '">';
    echo '<button type="submit" name="reset_mapping" class="btn btn-danger btn-sm" onclick="return confirm(\'Are you sure you want to reset the mapping for this variation?\')"><i class="fas fa-trash-alt"></i> Reset</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
        }
    }
}




// Reset UniPin voucher mapping for a specific variation
function reset_unipin_voucher_mapping() {
    if (isset($_POST['variation_id'])) {
        $variation_id = intval($_POST['variation_id']);
        $product_id = wp_get_post_parent_id($variation_id);

        if ($product_id) {
            $mapping = get_option('unipin_voucher_mapping_' . $product_id, array());

            if (isset($mapping[$variation_id])) {
                unset($mapping[$variation_id]);
                update_option('unipin_voucher_mapping_' . $product_id, $mapping);
            }
        }
    }

    wp_die();
}
add_action('wp_ajax_reset_unipin_voucher_mapping', 'reset_unipin_voucher_mapping');

// Enqueue JavaScript for dynamic voucher value fields
function unipin_voucher_enqueue_scripts($hook) {
    if ($hook == 'unipin-voucher_page_unipin-voucher-product-settings') {
        wp_enqueue_script('unipin-voucher-script', plugin_dir_url(__FILE__) . 'js/script.js', array('jquery'), '1.0', true);
    }
}
add_action('admin_enqueue_scripts', 'unipin_voucher_enqueue_scripts');