<?php

// Register the menu
// Register the menu
add_action('admin_menu', 'unipin_voucher_manager_menu');
function unipin_voucher_manager_menu() {
    add_menu_page(
        'Unipin Voucher Manager',
        'Unipin Voucher Manager',
        'manage_options',
        'unipin-voucher-manager',
        'unipin_voucher_manager_page',
        'dashicons-tickets-alt',
        20
    );

    add_submenu_page(
        'unipin-voucher-manager',
        'Import Logs',
        'Import Logs',
        'manage_options',
        'unipin-voucher-manager-logs',
        'unipin_voucher_manager_logs_page'
    );

    add_submenu_page(
        'unipin-voucher-manager',
        'Analytics',
        'Analytics',
        'manage_options',
        'unipin-voucher-manager-analytics',
        'unipin_voucher_manager_analytics_page'
    );

    add_submenu_page(
        'unipin-voucher-manager',
        'Voucher Price',
        'Voucher Price',
        'manage_options',
        'unipin-voucher-price',
        'unipin_voucher_price_page'
    );
}




// Function to identify denomination based on prefix
function get_denomination($voucher_code) {
    $prefix = substr($voucher_code, 0, 6);
    $denominations = [
        '20' => ['UPBD-Q', 'BDMB-T'],
        '36' => ['UPBD-R', 'BDMB-U'],
        '80' => ['UPBD-G', 'BDMB-J'],
        '160' => ['UPBD-F', 'BDMB-I'],
        '161' => ['BDMB-Q', 'UPBD-N'],
        '162' => ['BDMB-R', 'UPBD-O'],
        '405' => ['UPBD-H', 'BDMB-K'],
        '800' => ['UPBD-P', 'BDMB-S'],
        '810' => ['UPBD-I', 'BDMB-L'],
        '1625' => ['UPBD-J', 'BDMB-M']
    ];

    foreach ($denominations as $denomination => $prefixes) {
        if (in_array($prefix, $prefixes)) {
            return $denomination;
        }
    }
    return 'INVALID';
}





// Handle the voucher import action via AJAX
add_action('wp_ajax_import_vouchers', 'handle_import_vouchers');
function handle_import_vouchers() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['denomination']) && isset($_POST['vouchers'])) {
        $denomination = sanitize_text_field($_POST['denomination']);
        $vouchers = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['vouchers']))));
        $import_time = current_time('mysql'); // WordPress time
        $calculation_time = get_option('unipin_calculation_time', $import_time); // Get the calculation time from options table, default to import_time
        $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);

        $user_id = get_current_user_id();
        $errors = [];
        $success_count = 0;
        foreach ($vouchers as $voucher) {
            // Check if the voucher already exists
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE voucher = %s", $voucher));
            if ($exists) {
                $errors[] = $voucher;
            } else {
                $wpdb->insert($table_name, [
                    'voucher' => $voucher,
                    'used' => 'no',
                    'import_time' => $import_time, // Use WordPress time
                    'calculation_time' => $calculation_time, // Use the reset time as calculation time
                    'imported_by' => $user_id
                ]);
                $success_count++;
            }
        }

        if ($success_count > 0) {
            $log_table = $wpdb->prefix . 'unipin_voucher_import_log';
            $wpdb->insert($log_table, [
                'import_time' => $import_time, // Use WordPress time
                'voucher_value' => $denomination,
                'imported_by' => $user_id,
                'voucher_codes' => implode(', ', $vouchers)
            ]);
        }

        if (empty($errors)) {
            wp_send_json_success("Total $success_count Unipin vouchers ($denomination) added into $table_name.");
        } else {
            wp_send_json_error("Some vouchers were not imported: " . implode(', ', $errors));
        }
    }
}






// Handle the update as fresh action via AJAX
add_action('wp_ajax_update_as_fresh', 'handle_update_as_fresh');
function handle_update_as_fresh() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['vouchers'])) {
        $vouchers = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['vouchers']))));
        $total_vouchers = count($vouchers);
        if ($total_vouchers === 0) {
            wp_send_json_error("Please enter vouchers first.");
        }

        $success_count = 0;
        $errors = [];

        foreach ($vouchers as $voucher) {
            $denomination = get_denomination($voucher);
            if ($denomination != 'INVALID') {
                $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);
                $updated = $wpdb->update($table_name, [
                    'used' => 'no',
                    'order_id' => null
                ], ['voucher' => $voucher]);

                if ($updated !== false) {
                    $success_count++;
                } else {
                    $errors[] = $voucher;
                }
            } else {
                $errors[] = $voucher;
            }
        }

        if (empty($errors)) {
            wp_send_json_success("Total $success_count vouchers updated as fresh.");
        } else {
            wp_send_json_error("Some vouchers were not updated: " . implode(', ', $errors));
        }
    }
}

// Handle the retrieve vouchers action via AJAX
add_action('wp_ajax_retrieve_vouchers', 'handle_retrieve_vouchers');
function handle_retrieve_vouchers() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['voucher_value']) && isset($_POST['quantity'])) {
        $voucher_value = sanitize_text_field($_POST['voucher_value']);
        $quantity = intval($_POST['quantity']);
        
        // Sanitize the table name to prevent SQL injection
        $table_name = $wpdb->prefix . 'unipin_voucher_' . preg_replace('/[^a-z0-9_]/', '', strtolower($voucher_value));

        // Use ORDER BY to sort in descending order
        $vouchers = $wpdb->get_col($wpdb->prepare(
            "SELECT voucher FROM $table_name WHERE used = 'no' ORDER BY id DESC LIMIT %d",
            $quantity
        ));

        if (!empty($vouchers)) {
            wp_send_json_success(['vouchers' => $vouchers, 'count' => count($vouchers)]);
        } else {
            wp_send_json_error("No vouchers found or not enough unused vouchers available.");
        }
    } else {
        wp_send_json_error("Missing required parameters.");
    }
}

// Handle the delete vouchers action via AJAX
add_action('wp_ajax_delete_vouchers', 'handle_delete_vouchers');
function handle_delete_vouchers() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['vouchers'])) {
        $vouchers = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['vouchers']))));
        $errors = [];
        $success_count = 0;

        foreach ($vouchers as $voucher) {
            $denomination = get_denomination($voucher);
            if ($denomination != 'INVALID') {
                $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);
                $deleted = $wpdb->delete($table_name, ['voucher' => $voucher]);

                if ($deleted !== false) {
                    $success_count++;
                } else {
                    $errors[] = $voucher;
                }
            } else {
                $errors[] = $voucher;
            }
        }

        if (empty($errors)) {
            wp_send_json_success("Total $success_count vouchers deleted.");
        } else {
            wp_send_json_error("Some vouchers were not deleted: " . implode(', ', $errors));
        }
    }
}

// Handle the mark as used action via AJAX
add_action('wp_ajax_mark_as_used', 'handle_mark_as_used');
function handle_mark_as_used() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['vouchers'])) {
        $vouchers = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['vouchers']))));
        $used_time = current_time('mysql'); // WordPress time
        $total_vouchers = count($vouchers);
        if ($total_vouchers === 0) {
            wp_send_json_error("Please enter vouchers first.");
        }

        $user_login = wp_get_current_user()->user_login;
        $success_count = 0;
        $errors = [];

        foreach ($vouchers as $voucher) {
            $denomination = get_denomination($voucher);
            if ($denomination != 'INVALID') {
                $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);
                $updated = $wpdb->update($table_name, [
                    'used' => 'yes',
                    'order_id' => $user_login,
                    'used_time' => $used_time // Use WordPress time
                ], ['voucher' => $voucher]);

                if ($updated !== false) {
                    $success_count++;
                } else {
                    $errors[] = $voucher;
                }
            } else {
                $errors[] = $voucher;
            }
        }

        if (empty($errors)) {
            wp_send_json_success("Total $success_count vouchers marked as used.");
        } else {
            wp_send_json_error("Some vouchers were not updated: " . implode(', ', $errors));
        }
    }
}

// Handle the search order action via AJAX
add_action('wp_ajax_search_order', 'handle_search_order');
function handle_search_order() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['order_id'])) {
        $order_id = sanitize_text_field($_POST['order_id']);
        $table_prefix = $wpdb->prefix;

        // Prepare search queries for all denomination tables
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_prefix}unipin_voucher_%'");
        $search_queries = [];
        foreach ($tables as $table) {
            if ($table !== "{$table_prefix}unipin_voucher_import_log" && $table !== "{$table_prefix}unipin_voucher_mapping") {
                $search_queries[] = "SELECT voucher, '{$table}' as table_name FROM `{$table}` WHERE order_id = '" . esc_sql($order_id) . "'";
            }
        }
        $search_query = implode(" UNION ALL ", $search_queries);

        if (!empty($search_query)) {
            $results = $wpdb->get_results($search_query, OBJECT);
            $vouchers_by_denomination = [];

            foreach ($results as $result) {
                $denomination = get_denomination($result->voucher);
                if ($denomination != 'INVALID') {
                    if (!isset($vouchers_by_denomination[$denomination])) {
                        $vouchers_by_denomination[$denomination] = [];
                    }
                    $vouchers_by_denomination[$denomination][] = $result->voucher;
                }
            }

            if (!empty($vouchers_by_denomination)) {
                wp_send_json_success(['vouchers' => $vouchers_by_denomination]);
            } else {
                wp_send_json_error("No vouchers found for the given order ID.");
            }
        } else {
            wp_send_json_error("Failed to search for vouchers. Please try again.");
        }
    }
}





// Render the menu page
function unipin_voucher_manager_page() {
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // Expected prefix-to-table mappings
    $expected_prefixes = [
        '20' => $table_prefix . 'unipin_voucher_20',
        '36' => $table_prefix . 'unipin_voucher_36',
        '80' => $table_prefix . 'unipin_voucher_80',
        '160' => $table_prefix . 'unipin_voucher_160',
        '161' => $table_prefix . 'unipin_voucher_161',
        '162' => $table_prefix . 'unipin_voucher_162',
        '405' => $table_prefix . 'unipin_voucher_405',
        '800' => $table_prefix . 'unipin_voucher_800',
        '810' => $table_prefix . 'unipin_voucher_810',
        '1625' => $table_prefix . 'unipin_voucher_1625'
    ];

    // Get available voucher values
    $voucher_values = array_keys($expected_prefixes);

    // Handle voucher search
    $used_vouchers = [];
    $unused_vouchers = [];
    $not_found_vouchers = [];
    $invalid_vouchers = [];
    $warnings = [];
    $total_vouchers_searched = 0;

    if (isset($_POST['search_vouchers'])) {
        $search_vouchers = sanitize_textarea_field($_POST['search_vouchers']);
        $voucher_codes = array_filter(array_map('trim', explode("\n", $search_vouchers)));
        $total_vouchers_searched = count($voucher_codes);
        $valid_vouchers = [];
        foreach ($voucher_codes as $voucher_code) {
            if (preg_match('/^(BDMB|UPBD)/', $voucher_code) && strlen($voucher_code) == 37) {
                $valid_vouchers[] = $voucher_code;
            } else {
                $invalid_vouchers[] = $voucher_code;
            }
        }
        $search_queries = [];
        foreach ($valid_vouchers as $voucher_code) {
            $table_search_queries = [];
            $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_prefix}unipin_voucher_%'");
            foreach ($tables as $table) {
                if ($table !== "{$table_prefix}unipin_voucher_import_log" && $table !== "{$table_prefix}unipin_voucher_mapping") {
                    $table_search_queries[] = "SELECT *, '$table' as table_name FROM `{$table}` WHERE voucher = '" . esc_sql($voucher_code) . "'";
                }
            }
            $search_queries[] = "(" . implode(" UNION ALL ", $table_search_queries) . ")";
        }
        $search_query = implode(" UNION ALL ", $search_queries);
        
        if (!empty($search_query)) {
            $search_results = $wpdb->get_results($search_query, OBJECT);
            $found_vouchers = [];
            foreach ($search_results as $result) {
                $found_vouchers[] = $result->voucher;
                $denomination = get_denomination($result->voucher);

                if ($denomination == 'INVALID') {
                    $invalid_vouchers[] = $result->voucher;
                    continue;
                }

                // Check if the voucher is in the correct table
                if (strpos($result->table_name, $expected_prefixes[$denomination]) === false) {
                    $warnings[] = "Voucher $result->voucher maybe inserted into wrong database table.";
                }

                if ($result->used == 'yes') {
                    $used_vouchers[$denomination][] = $result->voucher . ' :::::: ' . $result->order_id . ' :::::: ' . $result->used_time;
                } else {
                    $unused_vouchers[$denomination][] = $result->voucher;
                }
            }
            $not_found_vouchers = array_diff($valid_vouchers, $found_vouchers);
        }
    }

    // Categorize not found vouchers
    $not_found_vouchers_by_denomination = [];
    foreach ($not_found_vouchers as $voucher) {
        $denomination = get_denomination($voucher);
        if ($denomination == 'INVALID') {
            $invalid_vouchers[] = $voucher;
        } else {
            $not_found_vouchers_by_denomination[$denomination][] = $voucher;
        }
    }




    // Render the page
    ?>
    <div class="wrap">
        <h1>Unipin Voucher Manager</h1>
        <div id="retrieve-vouchers-container"></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=unipin-voucher-manager')); ?>">
            <div class="button-container">
                <button type="button" class="button-secondary" onclick="retrieveVouchers()">Retrieve Vouchers</button>
                <button type="button" class="button-secondary" onclick="searchOrder()">Search Order</button> <!-- New button -->
            </div>
            <h2>Search Vouchers</h2>
            <p>Enter voucher codes (one per line) to search for:</p>
            <textarea name="search_vouchers" rows="5" cols="50"><?php echo isset($_POST['search_vouchers']) ? esc_textarea($search_vouchers) : ''; ?></textarea>
            <div class="button-container">
                <input type="submit" name="search_submit" value="Search Vouchers" class="button-primary">
                <button type="button" class="button-secondary" onclick="updateAsFresh()">Update as Fresh</button>
                <button type="button" class="button-secondary" onclick="markAsUsed()">Mark Used</button>
            </div>
            <div id="search-results-container"></div> <!-- New container for search results -->
            <?php if (isset($_POST['search_vouchers'])) : ?>
                <p>Total "<?php echo $total_vouchers_searched; ?>" vouchers searched in the database.</p>
                    <button type="button" class="import-all-button" onclick="importAllNotFoundVouchers()">Import All Not Found Vouchers</button>

                <?php if (!empty($warnings)) : ?>
                    <div class="notice notice-warning">
                        <?php foreach ($warnings as $warning) : ?>
                            <p><?php echo esc_html($warning); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($used_vouchers)) : ?>
                    <h1>Used vouchers found</h1>
                    <?php foreach ($used_vouchers as $denomination => $vouchers) : ?>
                        <h3><?php echo $denomination; ?> Unipin vouchers (<?php echo count($vouchers); ?>) <button type="button" class="copy-button" onclick="copyToClipboard('used_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>', this)">Copy</button></h3>
                        <div id="used_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>" class="voucher-section used-section">
                            <ul>
                                <?php foreach ($vouchers as $voucher) : ?>
                                    <li><?php echo esc_html($voucher); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($unused_vouchers)) : ?>
                    <h1>Unused vouchers found</h1>
                    <?php foreach ($unused_vouchers as $denomination => $vouchers) : ?>
                        <h3><?php echo $denomination; ?> Unipin vouchers (<?php echo count($vouchers); ?>) <button type="button" class="copy-button" onclick="copyToClipboard('unused_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>', this)">Copy</button></h3>
                        <div id="unused_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>" class="voucher-section unused-section">
                            <ul>
                                <?php foreach ($vouchers as $voucher) : ?>
                                    <li><?php echo esc_html($voucher); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($not_found_vouchers_by_denomination)) : ?>
                    <h1>Vouchers not found</h1>
                    <?php foreach ($not_found_vouchers_by_denomination as $denomination => $vouchers) : ?>
                        <h3><?php echo $denomination; ?> Unipin vouchers (<?php echo count($vouchers); ?>) <button type="button" class="copy-button" onclick="copyToClipboard('not_found_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>', this)">Copy</button> <button type="button" class="import-button" onclick="importVouchers('<?php echo $denomination; ?>', 'not_found_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>', this)">Import Code</button></h3>
                       


                        <div id="not_found_vouchers_<?php echo str_replace(' ', '_', $denomination); ?>" class="voucher-section not-found-section">
                            <ul>
                                <?php foreach ($vouchers as $voucher) : ?>
                                    <li><?php echo esc_html($voucher); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($invalid_vouchers)) : ?>
                    <h1>Invalid vouchers</h1>
                    <h3><?php echo count($invalid_vouchers); ?> Invalid vouchers <button type="button" class="copy-button" onclick="copyToClipboard('invalid_vouchers', this)">Copy</button></h3>
                    <div id="invalid_vouchers" class="voucher-section invalid-section">
                        <ul>
                            <?php foreach ($invalid_vouchers as $voucher) : ?>
                                <li><?php echo esc_html($voucher); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    </div>

    <style>
        .wrap {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h1, h2, h3 {
            color: #333;
            margin-bottom: 20px;
        }

        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .button-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .button-primary, .button-secondary {
            background-color: #0073aa;
            border-color: #0073aa;
            color: #fff;
            text-decoration: none;
            text-shadow: none;
            box-shadow: none;
            padding: 15px 25px;
            border-radius: 4px;
            transition: background-color 0.3s;
            font-size: 16px;
        }

        .button-primary:hover, .button-secondary:hover {
            background-color: #006799;
        }

        .voucher-section {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .used-section {
            background-color: #ffcccc;
        }

        .unused-section {
            background-color: #ccffcc;
        }

        .not-found-section {
            background-color: #ccccff;
        }

        .invalid-section {
            background-color: #ffcc99;
        }

        .copy-button, .import-button, .undo-button, .mark-used-button {
            background-color: #0073aa;
            border: none;
            color: white;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .copy-button:hover, .import-button:hover, .undo-button:hover, .mark-used-button:hover {
            background-color: #006799;
        }

        .notice-warning {
            padding: 10px;
            margin: 10px 0;
            border-left: 5px solid #ffba00;
            background-color: #fffbe6;
        }

        .loading-animation {
            border: 4px solid #f3f3f3;
            border-radius: 50%;
            border-top: 4px solid #3498db;
            width: 20px;
            height: 20px;
            animation: spin 2s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notice-success {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }

        .notice-error {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }
        
.import-all-button {
    background-color: #28a745;
    border: none;
    color: white;
    padding: 10px 20px;
    text-align: center;
    text-decoration: none;
    display: inline-block;
    font-size: 14px;
    margin: 4px 2px;
    cursor: pointer;
    border-radius: 4px;
    transition: background-color 0.3s;
}

.import-all-button:hover {
    background-color: #218838;
}
        
        
    </style>





    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <script>
        function copyToClipboard(elementId, button) {
            var text = Array.from(document.getElementById(elementId).getElementsByTagName('li'))
                            .map(function(li) {
                                var fullText = li.childNodes[0].textContent.trim(); // Only get text node
                                return fullText;
                            })
                            .join('\n');
            navigator.clipboard.writeText(text).then(function() {
                button.innerText = "Copied!";
                setTimeout(function() {
                    button.innerText = "Copy";
                }, 2000);
            }, function(err) {
                console.error('Could not copy text: ', err);
            });
        }

        function importVouchers(denomination, elementId, button) {
            var vouchers = Array.from(document.getElementById(elementId).getElementsByTagName('li'))
                                .map(function(li) { return li.childNodes[0].textContent.trim(); }) // Get full text content
                                .join('\n');
            
            var data = {
                action: 'import_vouchers',
                denomination: denomination,
                vouchers: vouchers
            };

            // Show loading animation
            var loadingAnimation = document.createElement('div');
            loadingAnimation.className = 'loading-animation';
            button.parentNode.insertBefore(loadingAnimation, button.nextSibling);

            jQuery.post(ajaxurl, data, function(response) {
                var messageDiv = document.getElementById('import-message');
                loadingAnimation.remove(); // Remove loading animation

                if (response.success) {
                    button.innerText = "Success";
                    var successSpan = document.createElement('span');
                    successSpan.className = 'notice notice-success';
                    successSpan.innerHTML = '<p>' + response.data + '</p>';
                    button.parentNode.insertBefore(successSpan, button.nextSibling);
                } else {
                    button.innerText = "Failed";
                    var errorSpan = document.createElement('span');
                    errorSpan.className = 'notice notice-error';
                    errorSpan.innerHTML = '<p>' + response.data + '</p>';
                    button.parentNode.insertBefore(errorSpan, button.nextSibling);
                }
            });
        }

        function updateAsFresh() {
            var vouchers = document.querySelector('textarea[name="search_vouchers"]').value;
            
            if (vouchers.trim() === '') {
                swal("Error", "Please enter vouchers first.", "error");
                return;
            }
            
            var data = {
                action: 'update_as_fresh',
                vouchers: vouchers
            };

            var total_vouchers = vouchers.split("\n").filter(v => v.trim() !== '').length;

            swal({
                title: "Are you sure?",
                text: "This " + total_vouchers + " vouchers will update the selected vouchers as fresh (unused).",
                icon: "warning",
                buttons: true,
                dangerMode: true,
            })
            .then((willUpdate) => {
                if (willUpdate) {
                    jQuery.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            swal("Success", response.data, "success");
                        } else {
                            swal("Error", response.data, "error");
                        }
                    });
                }
            });
        }

        function markAsUsed() {
            var vouchers = document.querySelector('textarea[name="search_vouchers"]').value;

            if (vouchers.trim() === '') {
                swal("Error", "Please enter vouchers first.", "error");
                return;
            }
            
            var data = {
                action: 'mark_as_used',
                vouchers: vouchers
            };

            var total_vouchers = vouchers.split("\n").filter(v => v.trim() !== '').length;

            swal({
                title: "Are you sure?",
                text: "This " + total_vouchers + " vouchers will be marked as used.",
                icon: "warning",
                buttons: true,
                dangerMode: true,
            })
            .then((willUpdate) => {
                if (willUpdate) {
                    // Show loading animation
                    swal({
                        text: 'Updating vouchers as used...',
                        buttons: false,
                        closeOnClickOutside: false,
                        closeOnEsc: false
                    });

                    jQuery.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            swal("Success", response.data, "success");
                        } else {
                            swal("Error", response.data, "error");
                        }
                    });
                }
            });
        }


function importAllNotFoundVouchers() {
    // Get all not-found voucher sections
    var notFoundSections = document.querySelectorAll('.not-found-section');

    var allVouchers = [];
    notFoundSections.forEach(function(section) {
        var denomination = section.id.replace('not_found_vouchers_', '').replace(/_/g, ' ');
        var vouchers = Array.from(section.getElementsByTagName('li')).map(function(li) {
            return li.childNodes[0].textContent.trim();
        });
        allVouchers.push({ denomination: denomination, vouchers: vouchers });
    });

    if (allVouchers.length === 0) {
        swal("No Vouchers", "No not-found vouchers to import.", "info");
        return;
    }

    swal({
        title: "Are you sure?",
        text: "This will import all not-found vouchers into their respective tables.",
        icon: "warning",
        buttons: true,
        dangerMode: true,
    }).then((willImport) => {
        if (willImport) {
            // Show loading animation
            swal({
                text: 'Importing vouchers...',
                buttons: false,
                closeOnClickOutside: false,
                closeOnEsc: false
            });

            // Send AJAX request for each denomination
            var promises = allVouchers.map(function(voucherData) {
                return jQuery.post(ajaxurl, {
                    action: 'import_vouchers',
                    denomination: voucherData.denomination,
                    vouchers: voucherData.vouchers.join('\n')
                });
            });

            // Wait for all AJAX requests to complete
            Promise.all(promises).then(function(responses) {
                var successCount = 0;
                var failedCount = 0;
                var importedVouchers = [];
                var failedVouchers = [];

                responses.forEach(function(response, index) {
                    if (response.success) {
                        successCount++;
                        importedVouchers.push({
                            denomination: allVouchers[index].denomination,
                            count: allVouchers[index].vouchers.length,
                            message: response.data
                        });
                    } else {
                        failedCount++;
                        failedVouchers.push({
                            denomination: allVouchers[index].denomination,
                            message: response.data
                        });
                    }
                });

                // Prepare summary
                var summary = "Import Summary:\n\n";
                summary += "Successfully imported:\n";
                importedVouchers.forEach(function(voucher) {
                    summary += `- ${voucher.denomination}: ${voucher.count} vouchers\n`;
                });

                // Only add failed import summary if there are failed vouchers
                if (failedCount > 0) {
                    summary += "\nFailed to import:\n";
                    failedVouchers.forEach(function(voucher) {
                        summary += `- ${voucher.denomination}: ${voucher.message}\n`;
                    });
                }

                // Display summary and copy data to clipboard
                swal("Import Complete", summary, "info").then(() => {
                    // Copy summary to clipboard
                    navigator.clipboard.writeText(summary).then(() => {
                        console.log("Summary copied to clipboard.");
                    }).catch(err => {
                        console.error("Could not copy summary to clipboard: ", err);
                    });

                    // Optional: Handle any UI updates here instead of reloading the page
                });
            }).catch(function(error) {
                swal("Error", "An error occurred during import.", "error");
            });
        }
    });
}





        function retrieveVouchers() {
            var voucherValues = <?php echo json_encode(array_keys($expected_prefixes)); ?>;

            swal({
                title: "Retrieve Vouchers",
                text: "Select voucher value and enter quantity",
                content: {
                    element: "div",
                    attributes: {
                        innerHTML: `
                            <select id="voucher-value" style="margin-bottom: 10px;">
                                ${voucherValues.map(value => `<option value="${value}">${value}</option>`).join('')}
                            </select>
                            <input type="number" id="voucher-quantity" placeholder="Quantity" min="1" style="margin-bottom: 10px;">
                        `
                    },
                },
                buttons: true,
                dangerMode: true,
            }).then((value) => {
                if (value) {
                    var voucherValue = document.getElementById('voucher-value').value;
                    var quantity = document.getElementById('voucher-quantity').value;
                    retrieveAndDisplayVouchers(voucherValue, quantity);
                }
            });
        }

        function retrieveAndDisplayVouchers(voucherValue, quantity) {
            var data = {
                action: 'retrieve_vouchers',
                voucher_value: voucherValue,
                quantity: quantity
            };

            jQuery.post(ajaxurl, data, function(response) {
                if (response.success) {
                    var container = document.getElementById('retrieve-vouchers-container');
                    container.innerHTML = `
                        <h2>Retrieved Vouchers</h2>
                        <p>${response.data.count} vouchers retrieved:</p>
                        <div id="retrieved_vouchers" class="voucher-section unused-section">
                            <ul>${response.data.vouchers.map(v => `<li>${v} <button type="button" class="mark-used-button" onclick="markVoucherAsUsed('${v}', this)">Mark Used</button></li>`).join('')}</ul>
                        </div>
                        <button type="button" class="copy-button" onclick="copyToClipboard('retrieved_vouchers', this)">Copy</button>
                        <button type="button" class="import-button" onclick="markAllAsUsed()">Mark All As Used</button>
                        <button type="button" class="import-button" onclick="deleteRetrievedVouchers()">DELETE</button>
                    `;
                } else {
                    swal("Error", response.data, "error");
                }
            });
        }

        function markVoucherAsUsed(voucher, button) {
            var data = {
                action: 'mark_as_used',
                vouchers: voucher
            };

            // Show loading animation
            var loadingAnimation = document.createElement('div');
            loadingAnimation.className = 'loading-animation';
            button.parentNode.insertBefore(loadingAnimation, button.nextSibling);

            jQuery.post(ajaxurl, data, function(response) {
                loadingAnimation.remove(); // Remove loading animation
                if (response.success) {
                    button.innerText = "Used";
                    button.disabled = true;
                } else {
                    swal("Error", response.data, "error");
                }
            });
        }

        function markAllAsUsed() {
            var vouchers = Array.from(document.getElementById('retrieved_vouchers').getElementsByTagName('li'))
                                .map(function(li) { return li.childNodes[0].textContent.trim(); }) // Get full text content
                                .join('\n');

            var data = {
                action: 'mark_as_used',
                vouchers: vouchers
            };

            // Show loading animation
            swal({
                text: 'Updating vouchers as used...',
                buttons: false,
                closeOnClickOutside: false,
                closeOnEsc: false
            });

            jQuery.post(ajaxurl, data, function(response) {
                if (response.success) {
                    swal("Success", response.data, "success");
                    Array.from(document.getElementById('retrieved_vouchers').getElementsByTagName('button')).forEach(function(button) {
                        button.innerText = "Used";
                        button.disabled = true;
                    });
                } else {
                    swal("Error", response.data, "error");
                }
            });
        }

        function deleteRetrievedVouchers() {
            var vouchers = Array.from(document.getElementById('retrieved_vouchers').getElementsByTagName('li'))
                                .map(function(li) { return li.childNodes[0].textContent.trim(); }) // Get full text content
                                .join('\n');

            swal({
                title: "Are you sure?",
                text: "This will delete the selected vouchers.",
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((willDelete) => {
                if (willDelete) {
                    var data = {
                        action: 'delete_vouchers',
                        vouchers: vouchers
                    };

                    jQuery.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            swal("Success", "Vouchers deleted successfully.", "success");
                            document.getElementById('retrieve-vouchers-container').innerHTML = '';
                        } else {
                            swal("Error", response.data, "error");
                        }
                    });
                }
            });
        }

        function confirmUndoImport(logId, vouchers) {
            swal({
                title: "Are you sure?",
                text: "The following vouchers will be deleted:\n" + vouchers,
                icon: "warning",
                buttons: true,
                dangerMode: true,
            })
            .then((willDelete) => {
                if (willDelete) {
                    window.location.href = "<?php echo admin_url('admin.php?page=unipin-voucher-manager-logs&undo_log='); ?>" + logId;
                }
            });
        }

        function searchOrder() {
            swal({
                title: "Search Order",
                text: "Enter the order ID:",
                content: {
                    element: "input",
                    attributes: {
                        placeholder: "Order ID",
                        type: "text",
                        id: "order-id-input"
                    },
                },
                buttons: {
                    cancel: true,
                    confirm: {
                        text: "Search",
                        closeModal: false,
                    },
                },
            }).then((orderId) => {
                if (!orderId) throw null;

                var data = {
                    action: 'search_order',
                    order_id: orderId
                };

                // Show searching text on the button
                document.querySelector('button[onclick="searchOrder()"]').innerText = 'Searching...';

                jQuery.post(ajaxurl, data, function(response) {
                    // Reset button text
                    document.querySelector('button[onclick="searchOrder()"]').innerText = 'Search Order';

                    if (response.success) {
                        // Clear previous results
                        var container = document.getElementById('search-results-container');
                        container.innerHTML = '';

                        var vouchers = response.data.vouchers;
                        for (var denomination in vouchers) {
                            if (vouchers.hasOwnProperty(denomination)) {
                                var voucherList = vouchers[denomination];
                                var header = document.createElement('h3');
                                header.textContent = `${denomination} UC found (${voucherList.length}):`;
                                container.appendChild(header);

                                var ul = document.createElement('ul');
                                voucherList.forEach(function(voucher) {
                                    var li = document.createElement('li');
                                    li.textContent = voucher;
                                    ul.appendChild(li);
                                });
                                container.appendChild(ul);
                            }
                        }
                        swal.close(); // Close the SweetAlert popup after success
                    } else {
                        swal("Error", response.data, "error");
                    }
                }).catch(err => {
                    swal("Error", "Failed to search for vouchers. Please try again.", "error");
                });
            });
        }
    </script>
    <?php
}






// Handle the undo import action
add_action('admin_post_undo_import', 'handle_undo_import');
function handle_undo_import() {
    if (!current_user_can('manage_options')) {
        wp_redirect(admin_url('admin.php?page=unipin-voucher-manager-logs&message=undo_error'));
        exit;
    }

    global $wpdb;

    if (isset($_GET['undo_log'])) {
        $log_id = intval($_GET['undo_log']);
        $log_table = $wpdb->prefix . 'unipin_voucher_import_log';
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $log_table WHERE id = %d", $log_id));

        if ($log) {
            $voucher_table = $wpdb->prefix . 'unipin_voucher_' . strtolower($log->voucher_value);
            $voucher_codes = explode(', ', $log->voucher_codes);

            foreach ($voucher_codes as $voucher_code) {
                $wpdb->delete($voucher_table, ['voucher' => $voucher_code]);
            }

            $wpdb->delete($log_table, ['id' => $log_id]);

            wp_redirect(admin_url('admin.php?page=unipin-voucher-manager-logs&message=undo_success'));
            exit;
        } else {
            wp_redirect(admin_url('admin.php?page=unipin-voucher-manager-logs&message=undo_error'));
            exit;
        }
    }
}


function unipin_voucher_manager_logs_page() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'unipin_voucher_import_log';

    // Handle search/filter
    $search_query = "1=1"; // Default to no filtering
    if (isset($_GET['search'])) {
        $search = sanitize_text_field($_GET['search']);
        $search_query = "voucher_value LIKE '%$search%' OR imported_by LIKE '%$search%'";
    }

    // Handle pagination
    $logs_per_page = 10;
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $logs_per_page;

    // Retrieve logs with search and pagination
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $log_table WHERE $search_query ORDER BY import_time DESC LIMIT %d OFFSET %d",
        $logs_per_page, $offset
    ));
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $log_table WHERE $search_query");
    $total_pages = ceil($total_logs / $logs_per_page);

    // Display messages
    if (isset($_GET['message'])) {
        if ($_GET['message'] == 'undo_success') {
            echo '<div class="notice notice-success is-dismissible"><p>Import undone successfully.</p></div>';
        } elseif ($_GET['message'] == 'undo_error') {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to undo the import.</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>Import Logs</h1>
        <form method="get">
            <input type="hidden" name="page" value="unipin-voucher-manager-logs" />
            <input type="text" name="search" placeholder="Search logs" value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>" />
            <input type="submit" value="Search" class="button-secondary" />
        </form>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>Import Time</th>
                    <th>Voucher Value</th>
                    <th>Imported By</th>
                    <th>Voucher Codes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) :
                    $voucher_codes = explode(', ', $log->voucher_codes);
                    $total_vouchers = count($voucher_codes);

                    // Check if all vouchers are found
                    $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($log->voucher_value);
                    $placeholders = implode(',', array_fill(0, count($voucher_codes), '%s'));
                    $query = $wpdb->prepare("SELECT voucher FROM $table_name WHERE voucher IN ($placeholders)", ...$voucher_codes);
                    $found_vouchers = $wpdb->get_col($query);
                    $not_found_vouchers = array_diff($voucher_codes, $found_vouchers);
                    $row_class = empty($not_found_vouchers) ? 'voucher-found' : 'voucher-not-found';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><?php echo esc_html($log->import_time); ?></td>
                        <td><?php echo esc_html($log->voucher_value) . '*' . $total_vouchers; ?></td>
                        <td><?php echo esc_html(get_userdata($log->imported_by)->user_login); ?></td>
                        <td><button type="button" onclick="viewLogDetails('<?php echo esc_js($log->voucher_codes); ?>')">View Details</button></td>
                        <td><button type="button" onclick="viewNotFoundDetails('<?php echo esc_js(implode(', ', $not_found_vouchers)); ?>')"><?php echo count($not_found_vouchers); ?> Not Found</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'total' => $total_pages,
                    'current' => $current_page,
                ];
                echo paginate_links($pagination_args);
                ?>
            </div>
        </div>
    </div>

    <style>
        .voucher-found {
            background-color: #d4edda;
        }

        .voucher-not-found {
            background-color: #f8d7da;
        }

        .sweetalert-popup .swal-text {
            white-space: pre-wrap;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function viewLogDetails(voucherCodes) {
            const formattedCodes = voucherCodes.split(', ').join('\n');
            Swal.fire({
                title: 'Voucher Codes',
                html: '<pre>' + formattedCodes + '</pre>',
                showCancelButton: true,
                confirmButtonText: 'Copy',
                cancelButtonText: 'Close'
            }).then((result) => {
                if (result.isConfirmed) {
                    navigator.clipboard.writeText(formattedCodes);
                    Swal.fire('Copied!', '', 'success');
                }
            });
        }

        function viewNotFoundDetails(notFoundCodes) {
            if (notFoundCodes === '') {
                Swal.fire('No missing codes', '', 'info');
                return;
            }
            const formattedCodes = notFoundCodes.split(', ').join('\n');
            Swal.fire({
                title: 'Not Found Voucher Codes',
                html: '<pre>' + formattedCodes + '</pre>',
                showCancelButton: true,
                confirmButtonText: 'Copy',
                cancelButtonText: 'Close'
            }).then((result) => {
                if (result.isConfirmed) {
                    navigator.clipboard.writeText(formattedCodes);
                    Swal.fire('Copied!', '', 'success');
                }
            });
        }
    </script>
    <?php
}



// Create the log table on plugin activation
function unipin_voucher_manager_create_log_table() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'unipin_voucher_import_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $log_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        import_time DATETIME NOT NULL,
        voucher_value VARCHAR(255) NOT NULL,
        imported_by BIGINT(20) UNSIGNED NOT NULL,
        voucher_codes TEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'unipin_voucher_manager_create_log_table');
?>
