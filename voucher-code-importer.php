<?php
/*
Plugin Name: Voucher Code Importer
Plugin URI: http://example.com/voucher-code-importer
Description: Import voucher codes into separate tables based on voucher value.
Version: 1.13
Author: Your Name
Author URI: http://example.com
*/

// Enqueue CSS files
function enqueue_voucher_styles() {
    wp_enqueue_style('voucher-codes-style', plugins_url('css/style.css', __FILE__));
    wp_enqueue_style('bootstrap', 'https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css');
}

add_action('admin_enqueue_scripts', 'enqueue_voucher_styles');

require_once plugin_dir_path(__FILE__) . 'woocommerce-integration.php';
require_once plugin_dir_path(__FILE__) . 'api.php';
require_once plugin_dir_path(__FILE__) . 'ucvoucher.php';
require_once plugin_dir_path(__FILE__) . 'voucherprice.php';
require_once plugin_dir_path(__FILE__) . 'vouchercheck.php';




function create_voucher_tables() {
    global $wpdb;
    $table_prefix = $wpdb->prefix;
    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');
    $charset_collate = $wpdb->get_charset_collate();

    foreach ($voucher_values as $value) {
        $table_name = $table_prefix . 'unipin_voucher_' . $value;
        
        // Create table if it does not exist
        $sql_create_table = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            voucher VARCHAR(255) NOT NULL,
            used VARCHAR(20) NOT NULL DEFAULT 'no',
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            import_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_time DATETIME,
            imported_by BIGINT(20) UNSIGNED NOT NULL,
            calculation_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY voucher (voucher)
        ) $charset_collate;";
        
        $wpdb->query($sql_create_table);

        // Check if the 'calculation_time' column exists
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM $table_name LIKE %s",
            'calculation_time'
        ));

        // Add 'calculation_time' column if it does not exist
        if (empty($column_exists)) {
            $sql_add_column = "ALTER TABLE $table_name ADD COLUMN calculation_time DATETIME DEFAULT CURRENT_TIMESTAMP";
            $wpdb->query($sql_add_column);
        }
    }

    // Create the wp_unipin_voucher_mapping table
    $mapping_table_name = $table_prefix . 'unipin_voucher_mapping';
    $mapping_table_sql = "CREATE TABLE IF NOT EXISTS $mapping_table_name (
        id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT(11) UNSIGNED NOT NULL,
        variation_id INT(11) UNSIGNED NOT NULL,
        voucher_value VARCHAR(20) NOT NULL,
        voucher_quantity INT(11) UNSIGNED NOT NULL,
        topup_url VARCHAR(255),
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY variation_id (variation_id)
    ) $charset_collate;";
    
    $wpdb->query($mapping_table_sql);

    // Create the wp_unipin_voucher_import_log table
    $import_log_table_name = $table_prefix . 'unipin_voucher_import_log';
    $import_log_table_sql = "CREATE TABLE IF NOT EXISTS $import_log_table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        voucher_value VARCHAR(20) NOT NULL,
        imported_by BIGINT(20) UNSIGNED NOT NULL,
        import_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        voucher_codes LONGTEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    $wpdb->query($import_log_table_sql);
}

register_activation_hook(__FILE__, 'create_voucher_tables');


function voucher_codes_page() {
    global $wpdb;
    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');

    echo '<div class="wrap">';
    echo '<h1>Voucher Code Importer</h1>';

    echo '<div class="voucher-import">';
    echo '<h2>Import Voucher Codes</h2>';
    echo '<form method="post" action="" class="voucher-import-form">';
    echo '<div class="form-group">';
    echo '<textarea name="voucher_codes" rows="5" class="form-control" placeholder="Enter voucher codes (one per line)"></textarea>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<select name="voucher_value" class="form-control">';
    foreach ($voucher_values as $value) {
        echo '<option value="' . $value . '">' . $value . ' UC</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<input type="submit" name="import_vouchers" value="Import" class="btn btn-primary">';
    echo '</form>';
    echo '</div>';

    if (isset($_POST['import_vouchers'])) {
        $voucher_codes = sanitize_textarea_field($_POST['voucher_codes']);
        $voucher_value = sanitize_text_field($_POST['voucher_value']);
        $current_user_id = get_current_user_id();
        $import_result = import_voucher_codes($voucher_codes, $voucher_value, $current_user_id);
        $imported_codes = $import_result['imported_codes'];
        $success_count = $import_result['success_count'];

        echo '<div class="notice notice-success"><p>' . $success_count . ' voucher codes imported successfully for ' . $voucher_value . ' UC.</p>';
        if (!empty($imported_codes)) {
            echo '<ul>';
            foreach ($imported_codes as $code) {
                echo '<li>' . $code . '</li>';
            }
            echo '</ul>';
            echo '<form method="post" class="undo-form">';
            echo '<input type="hidden" name="voucher_value" value="' . $voucher_value . '">';
            echo '<input type="hidden" name="imported_voucher_codes" value="' . implode(',', $imported_codes) . '">';
            echo '<input type="submit" name="undo_import" value="Undo" class="btn btn-secondary">';
            echo '</form>';
        }
        echo '</div>';

        if (!empty($import_result['failed_codes'])) {
            echo '<div class="notice notice-error"><p>Failed to import the following voucher codes (already exist or invalid):</p><ul>';
            foreach ($import_result['failed_codes'] as $failed_code) {
                echo '<li>' . $failed_code . '</li>';
            }
            echo '</ul></div>';
        }
    }

    if (isset($_POST['undo_import'])) {
        $voucher_value = sanitize_text_field($_POST['voucher_value']);
        $imported_voucher_codes = explode(',', sanitize_text_field($_POST['imported_voucher_codes']));
        undo_voucher_import($voucher_value, $imported_voucher_codes);
        echo '<div class="notice notice-success"><p>Import has been undone successfully.</p></div>';
    }

    if (isset($_POST['delete_used_codes'])) {
        $voucher_value = sanitize_text_field($_POST['voucher_value']);
        delete_used_codes($voucher_value);
        echo '<div class="notice notice-success"><p>Used voucher codes deleted successfully.</p></div>';
    }

    echo '<div class="voucher-summary">';
    echo '<h2>Voucher Code Summary</h2>';
    echo '<div class="voucher-summary-list row">';
    foreach ($voucher_values as $value) {
        $total_count = get_voucher_count($value);
        $used_count = get_voucher_count($value, 'yes');
        $unused_count = $total_count - $used_count;
        echo '<div class="voucher-summary-item voucher-' . $value . ' col-md-4">';
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">' . $value . ' UC</h5>';
        echo '<p class="card-text">Total: ' . $total_count . ', Used: ' . $used_count . ', Unused: ' . $unused_count . '</p>';
        echo '<a href="?page=voucher-codes-table&view=' . $value . '" class="btn btn-primary">View Codes</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    // Add daily usage analytics section
    echo '<div class="voucher-analytics">';
    echo '<h2>Usage Analytics</h2>';
    echo '<form method="get" class="form-inline">';
    echo '<input type="hidden" name="page" value="voucher-codes">';
    echo '<div class="form-group mr-2">';
    echo '<label for="from_date">From Date:</label>';
    echo '<input type="date" id="from_date" name="from_date" class="form-control" value="' . esc_attr(isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d')) . '">';
    echo '</div>';
    echo '<div class="form-group mr-2">';
    echo '<label for="to_date">To Date:</label>';
    echo '<input type="date" id="to_date" name="to_date" class="form-control" value="' . esc_attr(isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d')) . '">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Show Analytics</button>';
    echo '</form>';

    $from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : date('Y-m-d');
    $to_date = isset($_GET['to_date']) ? sanitize_text_field($_GET['to_date']) : date('Y-m-d');

    echo '<h3>Usage Analytics from ' . $from_date . ' to ' . $to_date . '</h3>';

    echo '<div class="row">';
    foreach ($voucher_values as $value) {
        $used_codes = get_used_codes_by_date_range($value, $from_date, $to_date);
        $used_count = count($used_codes);

        echo '<div class="voucher-analytics-item col-md-4">';
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo '<h5 class="card-title">' . $value . ' UC</h5>';
        echo '<p class="card-text">Used Codes: ' . $used_count . '</p>';
        echo '<a href="?page=voucher-codes&action=view_used_codes&voucher_value=' . $value . '&from_date=' . $from_date . '&to_date=' . $to_date . '" class="btn btn-secondary btn-sm">View Used Codes</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>';

    echo '</div>';
}

function view_used_codes_page() {
    global $wpdb;
    $voucher_value = isset($_GET['voucher_value']) ? intval($_GET['voucher_value']) : 0;
    $from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : date('Y-m-d');
    $to_date = isset($_GET['to_date']) ? sanitize_text_field($_GET['to_date']) : date('Y-m-d');

    echo '<div class="wrap">';
    echo '<h1>Used Voucher Codes (' . $voucher_value . ' UC)</h1>';
    echo '<p>Showing used codes from ' . $from_date . ' to ' . $to_date . '</p>';

    // Check if the voucher value is valid
    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');
    if (in_array($voucher_value, $voucher_values)) {
        $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
        $used_codes = $wpdb->get_results($wpdb->prepare("SELECT voucher, used_time FROM $table_name WHERE used = 'yes' AND DATE(used_time) BETWEEN %s AND %s", $from_date, $to_date));

        if (!empty($used_codes)) {
            echo '<table class="table table-striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Voucher Code</th>';
            echo '<th>Used Time</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($used_codes as $code) {
                echo '<tr>';
                echo '<td>' . $code->voucher . '</td>';
                echo '<td>' . $code->used_time . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>No used codes found for the selected date range.</p>';
        }
    } else {
        echo '<p>Invalid voucher value.</p>';
    }

    echo '<a href="?page=voucher-codes" class="btn btn-primary">Back to Analytics</a>';

    echo '</div>';
}

// Add submenu page for viewing used codes
function voucher_menu() {
    add_menu_page(
        'Voucher Codes',
        'Voucher Codes',
        'manage_options',
        'voucher-codes',
        'voucher_codes_page',
        'dashicons-sticky',
        26
    );

    add_submenu_page(
        'voucher-codes',
        'Voucher Codes Table',
        'Voucher Codes Table',
        'manage_options',
        'voucher-codes-table',
        'voucher_codes_table_page'
    );

    add_submenu_page(
        'voucher-codes',
        'Import Log',
        'Import Log',
        'manage_options',
        'import-log',
        'import_log_page'
    );

    add_submenu_page(
        'voucher-codes',
        'View Used Codes',
        'View Used Codes',
        'manage_options',
        'voucher-codes',
        'view_used_codes_page'
    );
}
add_action('admin_menu', 'voucher_menu');


// Function to get the used codes for a specific voucher value and date range
function get_used_codes_by_date_range($voucher_value, $from_date, $to_date) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    $used_codes = $wpdb->get_col($wpdb->prepare("SELECT voucher FROM $table_name WHERE used = 'yes' AND DATE(used_time) BETWEEN %s AND %s", $from_date, $to_date));

    return $used_codes;
}






function export_voucher_codes($voucher_value, $export_type) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    if ($export_type === 'used') {
        $voucher_codes = $wpdb->get_col("SELECT voucher FROM $table_name WHERE used = 'yes' ORDER BY id ASC");
    } elseif ($export_type === 'unused') {
        $voucher_codes = $wpdb->get_col("SELECT voucher FROM $table_name WHERE used = 'no' ORDER BY id ASC");
    } else {
        $voucher_codes = $wpdb->get_col("SELECT voucher FROM $table_name ORDER BY id ASC");
    }

    $filename = 'voucher_codes_' . $voucher_value . '_' . $export_type . '.txt';

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo implode("\n", $voucher_codes);
    exit;
}





// Function to import voucher codes into the appropriate table
function import_voucher_codes($voucher_codes, $voucher_value, $current_user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
    $import_log_table_name = $wpdb->prefix . 'unipin_voucher_import_log';

    $voucher_codes = explode("\n", str_replace("\r", "", $voucher_codes));
    $success_count = 0;
    $failed_codes = array();
    $imported_codes = array();

    foreach ($voucher_codes as $voucher_code) {
        $voucher_code = trim($voucher_code);
        if (!empty($voucher_code)) {
            $existing_voucher = $wpdb->get_var($wpdb->prepare("SELECT voucher FROM $table_name WHERE voucher = %s", $voucher_code));
            if ($existing_voucher === null) {
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'voucher' => $voucher_code,
                        'used' => 'no',
                        'order_id' => NULL,
                        'import_time' => current_time('mysql'),
                        'imported_by' => $current_user_id
                    )
                );
                if ($result !== false) {
                    $success_count++;
                    $imported_codes[] = $voucher_code;
                } else {
                    $failed_codes[] = $voucher_code;
                }
            } else {
                $failed_codes[] = $voucher_code;
            }
        }
    }

    // Store import log
    $voucher_codes_str = implode("\n", $imported_codes);
    $wpdb->insert(
        $import_log_table_name,
        array(
            'voucher_value' => $voucher_value,
            'imported_by' => $current_user_id,
            'voucher_codes' => $voucher_codes_str
        )
    );

    return array(
        'success_count' => $success_count,
        'failed_codes' => $failed_codes,
        'imported_codes' => $imported_codes
    );
}


// Function to undo the import of voucher codes
function undo_voucher_import($voucher_value, $imported_codes) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    $voucher_codes_placeholders = rtrim(str_repeat('%s,', count($imported_codes)), ',');
    $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE voucher IN ($voucher_codes_placeholders)", $imported_codes));
}

// Function to get the count of voucher codes for a specific value and status
function get_voucher_count($voucher_value, $used = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    if (!empty($used)) {
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE used = %s", $used));
    } else {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }

    return $count;
}

// Function to delete used voucher codes for a specific value
function delete_used_codes($voucher_value) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE used = %s", 'yes'));
}

// Render the page for displaying voucher codes in a table
function voucher_codes_table_page() {
    global $wpdb;
    $voucher_values = array('20', '36', '80', '160', '405', '810', '1625', '2000', '161', '800', '162');

    echo '<div class="wrap">';
    echo '<h1>Voucher Codes Table</h1>';

    if (isset($_GET['view'])) {
        $voucher_value = sanitize_text_field($_GET['view']);
        display_voucher_codes_table($voucher_value);
    } else {
        echo '<p>Please select a voucher value to view the corresponding voucher codes.</p>';
        echo '<ul>';
        foreach ($voucher_values as $value) {
            echo '<li><a href="?page=voucher-codes-table&view=' . $value . '">' . $value . ' UC</a></li>';
        }
        echo '</ul>';
    }

    echo '</div>';
}


function display_voucher_codes_table($voucher_value) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;

    // Prepare the search query
    $search_query = '';
    $search_voucher = isset($_GET['search_voucher']) ? sanitize_text_field($_GET['search_voucher']) : '';
    $search_order_id = isset($_GET['search_order_id']) ? intval($_GET['search_order_id']) : 0;

    if (!empty($search_voucher)) {
        $search_query .= $wpdb->prepare(" AND voucher LIKE %s", '%' . $wpdb->esc_like($search_voucher) . '%');
    }

    if (!empty($search_order_id)) {
        $search_query .= $wpdb->prepare(" AND order_id = %d", $search_order_id);
    }

    // Prepare pagination
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $items_per_page = 20;
    $offset = ($current_page - 1) * $items_per_page;

    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE 1=1 $search_query");
    $total_pages = ceil($total_items / $items_per_page);

    // Retrieve voucher codes based on search and pagination
    $voucher_codes = $wpdb->get_results("SELECT * FROM $table_name WHERE 1=1 $search_query ORDER BY id DESC LIMIT $offset, $items_per_page");

    echo '<div class="wrap">';
    echo '<h1>Voucher Codes Table</h1>';

    // Display export options
    echo '<div class="export-options mb-3">';
    echo '<a href="' . esc_url(add_query_arg(array('action' => 'export', 'export_type' => 'used', 'voucher_value' => $voucher_value))) . '" class="btn btn-primary mr-2">Export Used Codes</a>';
    echo '<a href="' . esc_url(add_query_arg(array('action' => 'export', 'export_type' => 'unused', 'voucher_value' => $voucher_value))) . '" class="btn btn-primary mr-2">Export Unused Codes</a>';
    echo '<a href="' . esc_url(add_query_arg(array('action' => 'export', 'export_type' => 'all', 'voucher_value' => $voucher_value))) . '" class="btn btn-primary">Export All Codes</a>';
    echo '</div>';

    // Handle export functionality
    if (isset($_GET['action']) && $_GET['action'] === 'export') {
        $export_type = sanitize_text_field($_GET['export_type']);
        export_voucher_codes($voucher_value, $export_type);
        exit; // Stop further execution
    }

    // Display search form
    echo '<form method="get" class="search-form form-inline mb-3">';
    echo '<input type="hidden" name="page" value="voucher-codes-table">';
    echo '<input type="hidden" name="view" value="' . $voucher_value . '">';
    echo '<div class="form-group mr-2">';
    echo '<label for="search_voucher" class="sr-only">Search Voucher Code:</label>';
    echo '<input type="text" name="search_voucher" id="search_voucher" class="form-control" placeholder="Search Voucher Code" value="' . esc_attr($search_voucher) . '">';
    echo '</div>';
    echo '<div class="form-group mr-2">';
    echo '<label for="search_order_id" class="sr-only">Search Order ID:</label>';
    echo '<input type="number" name="search_order_id" id="search_order_id" class="form-control" placeholder="Search Order ID" value="' . esc_attr($search_order_id) . '">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Search</button>';
    echo '</form>';

    echo '<table class="table table-striped table-bordered table-responsive">';
    echo '<thead class="thead-dark">';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Voucher Code</th>';
    echo '<th>Import Time</th>';
    echo '<th>Used</th>';
    echo '<th>Order ID</th>';
    echo '<th>Used Time</th>';
    echo '<th>Imported By</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($voucher_codes as $voucher_code) {
        echo '<tr>';
        echo '<td>' . $voucher_code->id . '</td>';
        echo '<td>' . $voucher_code->voucher . '</td>';
        echo '<td>' . $voucher_code->import_time . '</td>';
        echo '<td>' . $voucher_code->used . '</td>';
        echo '<td>' . ($voucher_code->order_id ? $voucher_code->order_id : '-') . '</td>';
        echo '<td>' . ($voucher_code->used_time ? $voucher_code->used_time : '-') . '</td>';
        echo '<td>' . get_user_by('id', $voucher_code->imported_by)->display_name . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<div class="tablenav">';
    echo '<div class="tablenav-pages">';
    $page_links = paginate_links(array(
        'base' => add_query_arg(array('paged' => '%#%', 'view' => $voucher_value)),
        'format' => '',
        'prev_text' => __('&laquo;'),
        'next_text' => __('&raquo;'),
        'total' => $total_pages,
        'current' => $current_page
    ));
    echo $page_links;
    echo '</div>';
    echo '</div>';

    echo '<form method="post" class="delete-form mt-3">';
    echo '<input type="hidden" name="voucher_value" value="' . $voucher_value . '">';
    echo '<button type="submit" name="delete_used_codes" class="btn btn-danger" onclick="return confirm(\'Are you sure you want to delete the used voucher codes?\')">Delete Used Codes</button>';
    echo '</form>';

    echo '</div>';
}

// Render the page for displaying import logs
function import_log_page() {
    global $wpdb;
    $import_log_table_name = $wpdb->prefix . 'unipin_voucher_import_log';

    echo '<div class="wrap">';
    echo '<h1>Import Log</h1>';

    $import_logs = $wpdb->get_results("SELECT * FROM $import_log_table_name ORDER BY import_time DESC");

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Import Time</th>';
    echo '<th>Voucher Value</th>';
    echo '<th>Imported By</th>';
    echo '<th>Voucher Codes</th>';
    echo '<th>Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($import_logs as $import_log) {
        echo '<tr>';
        echo '<td>' . $import_log->import_time . '</td>';
        echo '<td>' . $import_log->voucher_value . ' UC</td>';
        echo '<td>' . get_user_by('id', $import_log->imported_by)->display_name . '</td>';
        echo '<td>' . substr($import_log->voucher_codes, 0, 50) . '...</td>';
        echo '<td>';
        echo '<form method="post" action="" style="display: inline-block;">';
        echo '<input type="hidden" name="import_log_id" value="' . $import_log->id . '">';
        echo '<input type="submit" name="delete_import_log" value="Delete" onclick="return confirm(\'Are you sure you want to delete this import log?\');" class="button delete-button">';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    if (isset($_POST['delete_import_log'])) {
        $import_log_id = intval($_POST['import_log_id']);
        $import_log_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $import_log_table_name WHERE id = %d", $import_log_id));

        if ($import_log_data) {
            $voucher_value = $import_log_data->voucher_value;
            $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
            $voucher_codes = explode("\n", $import_log_data->voucher_codes);

            $voucher_codes_placeholders = rtrim(str_repeat('%s,', count($voucher_codes)), ',');
            $deleted_voucher_codes = $wpdb->get_col($wpdb->prepare("SELECT voucher FROM $table_name WHERE voucher IN ($voucher_codes_placeholders)", $voucher_codes));

            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE voucher IN ($voucher_codes_placeholders)", $voucher_codes));
            $wpdb->delete($import_log_table_name, array('id' => $import_log_id));

            echo '<div class="notice notice-info"><p>The following voucher codes have been deleted:</p><ul>';
            foreach ($deleted_voucher_codes as $code) {
                echo '<li>' . $code . '</li>';
            }
            echo '</ul>';
            echo '<form method="post">';
            echo '<input type="hidden" name="voucher_value" value="' . $voucher_value . '">';
            echo '<input type="hidden" name="deleted_voucher_codes" value="' . implode(',', $deleted_voucher_codes) . '">';
            echo '<input type="submit" name="restore_voucher_codes" value="Restore" class="button button-primary">';
            echo '</form>';
            echo '</div>';
        }
    }

    if (isset($_POST['restore_voucher_codes'])) {
        $voucher_value = sanitize_text_field($_POST['voucher_value']);
        $deleted_voucher_codes = explode(',', sanitize_text_field($_POST['deleted_voucher_codes']));

        $table_name = $wpdb->prefix . 'unipin_voucher_' . $voucher_value;
        $voucher_codes_placeholders = rtrim(str_repeat('%s,', count($deleted_voucher_codes)), ',');

        $wpdb->query($wpdb->prepare("INSERT INTO $table_name (voucher, used, order_id, import_time, imported_by) VALUES " . implode(', ', $voucher_codes_placeholders . " ('no', NULL, NOW(), %d)"), array_merge($deleted_voucher_codes, array_fill(0, count($deleted_voucher_codes), get_current_user_id()))));

        echo '<div class="notice notice-success"><p>The deleted voucher codes have been restored.</p></div>';
    }

    echo '</div>';
}