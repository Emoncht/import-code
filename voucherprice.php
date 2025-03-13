<?php
function unipin_voucher_price_page() {
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['usd_rate'])) {
            update_option('USD_RATE', sanitize_text_field($_POST['usd_rate']));
        }

        if (isset($_POST['voucher_prices']) && is_array($_POST['voucher_prices'])) {
            foreach ($_POST['voucher_prices'] as $denomination => $price) {
                update_option('voucher_price_' . sanitize_text_field($denomination), sanitize_text_field($price));
            }
        }
    }

    // Get current USD to BDT rate
    $usd_rate = get_option('USD_RATE', '');

    // Get current voucher prices
    $voucher_prices = [];
    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_prefix}unipin_voucher_%'");

    foreach ($tables as $table) {
        if ($table !== "{$table_prefix}unipin_voucher_import_log" && $table !== "{$table_prefix}unipin_voucher_mapping") {
            $denomination = str_replace("{$table_prefix}unipin_voucher_", '', $table);
            $voucher_prices[$denomination] = get_option('voucher_price_' . $denomination, '');
        }
    }

    // Sort voucher prices by denomination (ascending order)
    ksort($voucher_prices);
    ?>
    <div class="wrap">
        <h1 class="title">Voucher Prices</h1>
        <form method="post">
            <div class="form-group">
                <label for="usd_rate">USD to BDT Rate:</label>
                <input type="text" id="usd_rate" name="usd_rate" value="<?php echo esc_attr($usd_rate); ?>" required>
            </div>

            <h2 class="subtitle">Voucher Prices</h2>
            <?php foreach ($voucher_prices as $denomination => $price) : ?>
                <div class="form-group">
                    <label for="voucher_price_<?php echo esc_attr($denomination); ?>"><?php echo esc_html($denomination); ?> UC Price (in USD):</label>
                    <input type="text" id="voucher_price_<?php echo esc_attr($denomination); ?>" name="voucher_prices[<?php echo esc_attr($denomination); ?>]" value="<?php echo esc_attr($price); ?>" required>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="button-primary">Save Prices</button>
        </form>

        <h2 class="subtitle">Current Voucher Prices</h2>
        <table class="voucher-price-table">
            <thead>
                <tr>
                    <th>Voucher Amount</th>
                    <th>USD Price</th>
                    <th>BDT Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($voucher_prices as $denomination => $price) : ?>
                    <tr>
                        <td><?php echo esc_html($denomination); ?> UC</td>
                        <td><?php echo esc_html($price); ?></td>
                        <td><?php echo esc_html($price * $usd_rate); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <style>
        .wrap {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .title {
            color: #0073aa;
            font-size: 32px;
            text-align: center;
            margin-bottom: 30px;
        }

        .subtitle {
            color: #333;
            font-size: 24px;
            margin-top: 20px;
            margin-bottom: 10px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
        }

        input[type="text"] {
            width: calc(100% - 20px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .button-primary {
            background-color: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            display: block;
            margin: 0 auto;
        }

        .button-primary:hover {
            background-color: #006799;
        }

        .voucher-price-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .voucher-price-table th,
        .voucher-price-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }

        .voucher-price-table th {
            background-color: #0073aa;
            color: white;
        }
    </style>
    <?php
}
