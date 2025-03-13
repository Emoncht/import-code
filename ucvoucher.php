<?php
function unipin_voucher_manager_analytics_page() {
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // Get the last calculation time from the options table
    $last_calculation_time = get_option('unipin_calculation_time', 'Never');

    // Get the USD to BDT rate
    $usd_rate = get_option('USD_RATE', 0);

    // Get all voucher tables
    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_prefix}unipin_voucher_%'");
    $voucher_stats = [];
    $calculated_voucher_stats = [];
    $total_worth_usd = 0;
    $total_worth_bdt = 0;
    $calculated_total_worth_usd = 0;
    $calculated_total_worth_bdt = 0;
    $total_used_worth_usd = 0;
    $total_used_worth_bdt = 0;
    $total_unused_worth_usd = 0;
    $total_unused_worth_bdt = 0;
    $calculated_total_used_worth_usd = 0;
    $calculated_total_used_worth_bdt = 0;
    $calculated_total_unused_worth_usd = 0;
    $calculated_total_unused_worth_bdt = 0;
$current_time = current_time('mysql');


    foreach ($tables as $table) {
        if ($table !== "{$table_prefix}unipin_voucher_import_log" && $table !== "{$table_prefix}unipin_voucher_mapping") {
            // Get the denomination from the table name
            $denomination = str_replace("{$table_prefix}unipin_voucher_", '', $table);

            // Get the price per voucher
            $price_per_unit = get_option('voucher_price_' . $denomination, 0);

            // Query to get counts of used and unused vouchers
            $total_vouchers = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $used_vouchers = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE used = 'yes'");
            $unused_vouchers = $total_vouchers - $used_vouchers;

$current_time = current_time('mysql');



            // Query to get counts of vouchers used after last calculation time
            $calc_used_vouchers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE used = 'yes' AND used_time > %s", $last_calculation_time));
$calc_unused_vouchers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE used = 'no' AND calculation_time = %s", $last_calculation_time));



            // Calculate worth in USD and BDT
            $used_cost_usd = $used_vouchers * $price_per_unit;
            $unused_cost_usd = $unused_vouchers * $price_per_unit;
            $used_cost_bdt = $used_cost_usd * $usd_rate;
            $unused_cost_bdt = $unused_cost_usd * $usd_rate;

            $calculated_used_cost_usd = $calc_used_vouchers * $price_per_unit;
            $calculated_unused_cost_usd = $calc_unused_vouchers * $price_per_unit;
            $calculated_used_cost_bdt = $calculated_used_cost_usd * $usd_rate;
            $calculated_unused_cost_bdt = $calculated_unused_cost_usd * $usd_rate;

            $total_worth_usd += $used_cost_usd + $unused_cost_usd;
            $total_worth_bdt += $used_cost_bdt + $unused_cost_bdt;
            $total_used_worth_usd += $used_cost_usd;
            $total_unused_worth_usd += $unused_cost_usd;
            $total_used_worth_bdt += $used_cost_bdt;
            $total_unused_worth_bdt += $unused_cost_bdt;

            $calculated_total_worth_usd += $calculated_used_cost_usd + $calculated_unused_cost_usd;
            $calculated_total_worth_bdt += $calculated_used_cost_bdt + $calculated_unused_cost_bdt;
            $calculated_total_used_worth_usd += $calculated_used_cost_usd;
            $calculated_total_unused_worth_usd += $calculated_unused_cost_usd;
            $calculated_total_used_worth_bdt += $calculated_used_cost_bdt;
            $calculated_total_unused_worth_bdt += $calculated_unused_cost_bdt;

            $voucher_stats[] = [
                'denomination' => $denomination,
                'total' => $total_vouchers,
                'used' => $used_vouchers,
                'unused' => $unused_vouchers,
                'used_cost_usd' => $used_cost_usd,
                'unused_cost_usd' => $unused_cost_usd,
                'used_cost_bdt' => $used_cost_bdt,
                'unused_cost_bdt' => $unused_cost_bdt,
            ];





            $calculated_voucher_stats[] = [
                'denomination' => $denomination,
                'used' => $calc_used_vouchers,
                'unused' => $calc_unused_vouchers,
                'used_cost_usd' => $calculated_used_cost_usd,
                'unused_cost_usd' => $calculated_unused_cost_usd,
                'used_cost_bdt' => $calculated_used_cost_bdt,
                'unused_cost_bdt' => $calculated_unused_cost_bdt,
            ];
        }
    }

    // Sort the voucher stats by denomination
    usort($voucher_stats, function($a, $b) {
        return $a['denomination'] - $b['denomination'];
    });

    usort($calculated_voucher_stats, function($a, $b) {
        return $a['denomination'] - $b['denomination'];
    });
    ?>

<div class="wrap">
    <h1 class="title">Unipin Voucher Manager Analytics</h1>

    <div class="button-container">
        <button type="button" class="button-red" id="reset-time-button" onclick="resetTime()">Reset Time</button>
        <button type="button" class="button-primary" onclick="window.location.href='<?php echo admin_url('admin.php?page=unipin-voucher-price'); ?>'">Voucher Price</button>
    </div>
    <div id="last-reset-time">Last reset: <?php echo esc_html($last_calculation_time); ?></div>

    <div class="date-range-filter">
        <input type="date" id="start-date">
        <input type="date" id="end-date">
        <button type="button" class="button-primary" id="filter-button" onclick="filterByDateRange()">Filter</button>
    </div>

    <h2 class="subtitle">ALL Voucher</h2>
    <div class="voucher-stats-container" id="all-voucher-stats-container">
        <?php foreach ($voucher_stats as $stat) : ?>
            <div class="voucher-stat" onclick="viewVoucherCodes('<?php echo esc_attr($stat['denomination']); ?>')">
                <h3><?php echo esc_html($stat['denomination']); ?> UC</h3>
                <p>Total: <span class="value"><?php echo esc_html($stat['total']); ?></span></p>
                <p>Used: <span class="value"><?php echo esc_html($stat['used']); ?></span></p>
                <p>Unused: <span class="value"><?php echo esc_html($stat['unused']); ?></span></p>
                <p>Used Total: <span class="value"><?php echo esc_html($stat['used_cost_usd']); ?>$</span></p>
                <p>Unused Total: <span class="value"><?php echo esc_html($stat['unused_cost_usd']); ?>$</span></p>
                <p>Used Total: <span class="value"><?php echo esc_html(number_format($stat['used_cost_bdt'], 2)); ?>৳</span></p>
                <p>Unused Total: <span class="value"><?php echo esc_html(number_format($stat['unused_cost_bdt'], 2)); ?>৳</span></p>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 class="subtitle">Calculated voucher after <?php echo esc_html($last_calculation_time); ?></h2>
    <div class="voucher-stats-container" id="calculated-voucher-stats-container">
        <?php foreach ($calculated_voucher_stats as $stat) : ?>
            <div class="voucher-stat" onclick="viewVoucherCodesAfterCalculation('<?php echo esc_attr($stat['denomination']); ?>')">
                <h3><?php echo esc_html($stat['denomination']); ?> UC</h3>
                <p>Used: <span class="value"><?php echo esc_html($stat['used']); ?></span></p>
                <p>Unused: <span class="value"><?php echo esc_html($stat['unused']); ?></span></p>
                <p>Used Total: <span class="value"><?php echo esc_html($stat['used_cost_usd']); ?>$</span></p>
                <p>Unused Total: <span class="value"><?php echo esc_html($stat['unused_cost_usd']); ?>$</span></p>
                <p>Used Total: <span class="value"><?php echo esc_html(number_format($stat['used_cost_bdt'], 2)); ?>৳</span></p>
                <p>Unused Total: <span class="value"><?php echo esc_html(number_format($stat['unused_cost_bdt'], 2)); ?>৳</span></p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="total-worth">
        <h2>Total Worth of Vouchers</h2>
        <table class="total-worth-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount (USD)</th>
                    <th>Amount (BDT)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total Worth</td>
                    <td class="amount"><?php echo esc_html($total_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($total_worth_bdt, 2)); ?>৳</td>
                </tr>
                <tr>
                    <td>Total Used Worth</td>
                    <td class="amount"><?php echo esc_html($total_used_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($total_used_worth_bdt, 2)); ?>৳</td>
                </tr>
                <tr>
                    <td>Total Unused Worth</td>
                    <td class="amount"><?php echo esc_html($total_unused_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($total_unused_worth_bdt, 2)); ?>৳</td>
                </tr>
            </tbody>
        </table>

        <h2>Total Worth of Calculated Vouchers</h2>
        <table class="total-worth-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount (USD)</th>
                    <th>Amount (BDT)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total Codes</td>
                    <td class="amount"><?php echo esc_html($calculated_total_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($calculated_total_worth_bdt, 2)); ?>৳</td>
                </tr>
                <tr>
                    <td>Total Used Worth</td>
                    <td class="amount"><?php echo esc_html($calculated_total_used_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($calculated_total_used_worth_bdt, 2)); ?>৳</td>
                </tr>
                <tr>
                    <td>Total Unused Worth</td>
                    <td class="amount"><?php echo esc_html($calculated_total_unused_worth_usd); ?>$</td>
                    <td class="amount"><?php echo esc_html(number_format($calculated_total_unused_worth_bdt, 2)); ?>৳</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div id="voucher-codes-container" class="voucher-codes-container"></div>
    <div id="filtered-voucher-summary"></div>
</div>

<style>
.wrap {
    font-family: Arial, sans-serif;
    max-width: 1000px;
    margin: 1%;
    padding: 3px;
    background-color: #f9f9f9;
    border-radius: 3px;
    box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
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

.button-container {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-bottom: 20px;
}

.button-red, .button-primary {
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    transition: background-color 0.3s;
}

.button-red {
    background-color: #dc3545;
}

.button-red:hover {
    background-color: #c82333;
}

.button-primary {
    background-color: #0073aa;
}

.button-primary:hover {
    background-color: #006799;
}

#last-reset-time {
    display: block;
    margin-top: 10px;
    font-size: 14px;
    color: #555;
    text-align: center;
}

.date-range-filter {
    display: flex;
    flex-direction: row;
    align-items: center;
    margin-bottom: 20px;
    padding: 5px;
    background-color: #eef;
    border-radius: 5px;
}

.date-range-filter button {
    margin-top: 10px;
}

.voucher-stats-container {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: center;
}

.voucher-stat {
    background-color: #ffffff;
    border: 1px solid #ccc;
    border-radius: 3px;
    padding: 1px;
    width: calc(50% - 2px);
    cursor: pointer;
    text-align: left;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s, box-shadow 0.3s;
}

.voucher-stat:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.voucher-stat h3 {
    margin-top: 0;
    color: #0073aa;
    font-size: 18px;
    text-align: center;
}

.voucher-stat p {
    margin: 2px 0;
    font-size: 13px;
    font-weight: bold;
    background-color: #fffacd;
    padding: 2px;
    border-radius: 3px;
}

.voucher-stat p span.value {
    font-size: 15px;
    color: #000080;
    font-weight: bold;
}

.voucher-codes-container {
    margin-top: 20px;
    padding: 2px;
    border: 1px solid #ccc;
    border-radius: 5px;
    background-color: #ffffff;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.total-worth {
    margin-top: 20px;
}

.total-worth h2 {
    font-size: 24px;
    color: #0073aa;
}

.total-worth-table {
    width: 100%;
    margin: 20px auto;
    border-collapse: collapse;
    border: 1px solid #ddd;
}

.total-worth-table th, .total-worth-table td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: center;
}

.total-worth-table th {
    background-color: #0073aa;
    color: white;
}

.total-worth-table tr:nth-child(even) {
    background-color: #f2f2f2;
}

.total-worth-table td.amount {
    font-size: 18px;
    font-weight: bold;
    color: #000080;
}

#calculated-voucher-container {
    margin-top: 20px;
}

.swal-modal {
    background-color: #fff;
    border-radius: 5px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.swal-button--confirm {
    background-color: #0073aa;
    color: #fff;
    border-radius: 5px;
    padding: 10px 20px;
}

.swal-button--cancel {
    background-color: #c82333;
    color: #fff;
    border-radius: 5px;
    padding: 10px 20px;
}
</style>

<script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
<script>
    function resetTime() {
        var resetButton = document.getElementById('reset-time-button');
        resetButton.innerText = 'Resetting...';

        swal({
            title: "Are you sure?",
            text: "This will reset the calculation time and update the date and time for all unused vouchers.",
            icon: "warning",
            buttons: true,
            dangerMode: true,
        }).then((willReset) => {
            if (willReset) {
                jQuery.post(ajaxurl, { action: 'reset_calculation_time' }, function(response) {
                    resetButton.innerText = 'Reset Time';

                    if (response.success) {
                        swal("Success", "Calculation time reset successfully.", "success");
                        document.getElementById('last-reset-time').innerText = 'Last reset: ' + response.data.time;
                    } else {
                        swal("Error", response.data, "error");
                    }
                });
            } else {
                resetButton.innerText = 'Reset Time';
            }
        });
    }

    function viewVoucherCodes(denomination) {
        swal({
            title: "View Voucher Codes",
            text: "Do you want to view used or unused codes?",
            buttons: {
                cancel: "Cancel",
                used: {
                    text: "View Used",
                    value: "used",
                },
                unused: {
                    text: "View Unused",
                    value: "unused",
                },
            },
        }).then((value) => {
            if (value) {
                fetchVoucherCodes(denomination, value);
            }
        });
    }

    function viewVoucherCodesAfterCalculation(denomination) {
        swal({
            title: "View Voucher Codes",
            text: "Do you want to view used or unused codes?",
            buttons: {
                cancel: "Cancel",
                used: {
                    text: "View Used",
                    value: "used",
                },
                unused: {
                    text: "View Unused",
                    value: "unused",
                },
            },
        }).then((value) => {
            if (value) {
                fetchVoucherCodesAfterCalculation(denomination, value);
            }
        });
    }

    function fetchVoucherCodes(denomination, type) {
    var data = {
        action: 'fetch_voucher_codes',
        denomination: denomination,
        type: type
    };

    jQuery.post(ajaxurl, data, function(response) {
        if (response.success) {
            var container = document.getElementById('voucher-codes-container');
            container.innerHTML = ''; // Clear previous codes

            var header = document.createElement('h3');
            header.textContent = `${denomination} UC ${type.charAt(0).toUpperCase() + type.slice(1)} Codes:`;
            container.appendChild(header);

            var ul = document.createElement('ul');
            response.data.codes.forEach(function(code) {
                var li = document.createElement('li');
                li.textContent = code;
                ul.appendChild(li);
            });
            container.appendChild(ul);

            // Add copy button
            var copyButton = document.createElement('button');
            copyButton.textContent = 'Copy Codes';
            copyButton.onclick = function() {
                copyToClipboard(response.data.codes.join('\n'));
            };
            container.appendChild(copyButton);
        } else {
            swal("Error", response.data, "error");
        }
    });
}


    function fetchVoucherCodesAfterCalculation(denomination, type) {
        var data = {
            action: 'fetch_voucher_codes_after_calculation',
            denomination: denomination,
            type: type
        };

        jQuery.post(ajaxurl, data, function(response) {
            if (response.success) {
                var hiddenField = document.getElementById('hidden-voucher-codes');
                hiddenField.style.display = 'block';
                hiddenField.innerHTML = response.data.codes.join('<br>');

                displayVoucherCodes(response.data.codes, denomination, type);
            } else {
                swal("Error", response.data, "error");
            }
        });
    }

    function displayVoucherCodes(codes, denomination, type) {
        var container = document.getElementById('voucher-codes-container');
        container.innerHTML = '';

        var header = document.createElement('h3');
        header.textContent = `${denomination} UC ${type.charAt(0).toUpperCase() + type.slice(1)} Codes:`;
        container.appendChild(header);

        var ul = document.createElement('ul');
        codes.forEach(function(code) {
            var li = document.createElement('li');
            li.textContent = code;
            ul.appendChild(li);
        });
        container.appendChild(ul);

        // Add copy button
        var copyButton = document.createElement('button');
        copyButton.textContent = 'Copy Codes';
        copyButton.onclick = function() {
            copyToClipboard(codes.join('\n'));
        };
        container.appendChild(copyButton);
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            swal("Copied", "Voucher codes copied to clipboard.", "success");
        }, function(err) {
            swal("Error", "Failed to copy text: " + err, "error");
        });
    }

    function filterByDateRange() {
        var startDate = document.getElementById('start-date').value;
        var endDate = document.getElementById('end-date').value;
        var filterButton = document.getElementById('filter-button');
        filterButton.innerText = 'Filtering...';

        if (!startDate || !endDate) {
            swal("Error", "Please select both start and end dates.", "error");
            filterButton.innerText = 'Filter';
            return;
        }

        var data = {
            action: 'filter_vouchers_by_date',
            start_date: startDate,
            end_date: endDate
        };

        jQuery.post(ajaxurl, data, function(response) {
            filterButton.innerText = 'Filter';

            if (response.success) {
                var container = document.getElementById('all-voucher-stats-container');
                container.innerHTML = '';

                response.data.vouchers.forEach(function(stat) {
                    var statDiv = document.createElement('div');
                    statDiv.className = 'voucher-stat';
                    statDiv.onclick = function() {
                        viewVoucherCodes(stat.denomination);
                    };

                    var h3 = document.createElement('h3');
                    h3.textContent = stat.denomination + ' UC';
                    statDiv.appendChild(h3);

                    var totalP = document.createElement('p');
                    totalP.textContent = 'Total: ' + stat.total;
                    statDiv.appendChild(totalP);

                    var usedP = document.createElement('p');
                    usedP.textContent = 'Used: ' + stat.used;
                    statDiv.appendChild(usedP);

                    var unusedP = document.createElement('p');
                    unusedP.textContent = 'Unused: ' + stat.unused;
                    statDiv.appendChild(unusedP);

                    var usedCostUsdP = document.createElement('p');
                    usedCostUsdP.textContent = 'Used Cost (USD): ' + stat.used_cost_usd;
                    statDiv.appendChild(usedCostUsdP);

                    var unusedCostUsdP = document.createElement('p');
                    unusedCostUsdP.textContent = 'Unused Cost (USD): ' + stat.unused_cost_usd;
                    statDiv.appendChild(unusedCostUsdP);

                    var usedCostBdtP = document.createElement('p');
                    usedCostBdtP.textContent = 'Used Cost (BDT): ' + stat.used_cost_bdt;
                    statDiv.appendChild(usedCostBdtP);

                    var unusedCostBdtP = document.createElement('p');
                    unusedCostBdtP.textContent = 'Unused Cost (BDT): ' + stat.unused_cost_bdt;
                    statDiv.appendChild(unusedCostBdtP);

                    container.appendChild(statDiv);
                });

                updateTotalWorth(response.data);
                displayFilteredSummary(response.data);
            } else {
                swal("Error", response.data, "error");
            }
        });
    }

    function updateTotalWorth(data) {
        var totalWorthUsd = 0;
        var totalWorthBdt = 0;
        var totalUsedWorthUsd = 0;
        var totalUsedWorthBdt = 0;
        var totalUnusedWorthUsd = 0;
        var totalUnusedWorthBdt = 0;

        data.vouchers.forEach(function(stat) {
            totalWorthUsd += parseFloat(stat.used_cost_usd) + parseFloat(stat.unused_cost_usd);
            totalWorthBdt += parseFloat(stat.used_cost_bdt) + parseFloat(stat.unused_cost_bdt);
            totalUsedWorthUsd += parseFloat(stat.used_cost_usd);
            totalUsedWorthBdt += parseFloat(stat.used_cost_bdt);
            totalUnusedWorthUsd += parseFloat(stat.unused_cost_usd);
            totalUnusedWorthBdt += parseFloat(stat.unused_cost_bdt);
        });

        document.querySelector('.total-worth .total-worth-usd').textContent = 'Total Worth (USD): ' + totalWorthUsd.toFixed(2);
        document.querySelector('.total-worth .total-worth-bdt').textContent = 'Total Worth (BDT): ' + totalWorthBdt.toFixed(2);
        document.querySelector('.total-worth .total-used-worth-usd').textContent = 'Total Used Worth (USD): ' + totalUsedWorthUsd.toFixed(2);
        document.querySelector('.total-worth .total-used-worth-bdt').textContent = 'Total Used Worth (BDT): ' + totalUsedWorthBdt.toFixed(2);
        document.querySelector('.total-worth .total-unused-worth-usd').textContent = 'Total Unused Worth (USD): ' + totalUnusedWorthUsd.toFixed(2);
        document.querySelector('.total-worth .total-unused-worth-bdt').textContent = 'Total Unused Worth (BDT): ' + totalUnusedWorthBdt.toFixed(2);
    }

    function displayFilteredSummary(data) {
        var container = document.getElementById('filtered-voucher-summary');
        container.innerHTML = '';

        var table = document.createElement('table');
        table.className = 'total-worth-table';

        var thead = document.createElement('thead');
        var tr = document.createElement('tr');
        tr.innerHTML = '<th>Description</th><th>Amount (USD)</th><th>Amount (BDT)</th>';
        thead.appendChild(tr);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');

        var totalWorthUsd = 0;
        var totalWorthBdt = 0;
        var totalUsedWorthUsd = 0;
        var totalUsedWorthBdt = 0;
        var totalUnusedWorthUsd = 0;
        var totalUnusedWorthBdt = 0;

        data.vouchers.forEach(function(stat) {
            totalWorthUsd += parseFloat(stat.used_cost_usd) + parseFloat(stat.unused_cost_usd);
            totalWorthBdt += parseFloat(stat.used_cost_bdt) + parseFloat(stat.unused_cost_bdt);
            totalUsedWorthUsd += parseFloat(stat.used_cost_usd);
            totalUsedWorthBdt += parseFloat(stat.used_cost_bdt);
            totalUnusedWorthUsd += parseFloat(stat.unused_cost_usd);
            totalUnusedWorthBdt += parseFloat(stat.unused_cost_bdt);
        });

        var totalRow = document.createElement('tr');
        totalRow.innerHTML = `<td>Total Worth</td><td class="amount">${totalWorthUsd.toFixed(2)}</td><td class="amount">${totalWorthBdt.toFixed(2)}</td>`;
        tbody.appendChild(totalRow);

        var usedRow = document.createElement('tr');
        usedRow.innerHTML = `<td>Total Used Worth</td><td class="amount">${totalUsedWorthUsd.toFixed(2)}</td><td class="amount">${totalUsedWorthBdt.toFixed(2)}</td>`;
        tbody.appendChild(usedRow);

        var unusedRow = document.createElement('tr');
        unusedRow.innerHTML = `<td>Total Unused Worth</td><td class="amount">${totalUnusedWorthUsd.toFixed(2)}</td><td class="amount">${totalUnusedWorthBdt.toFixed(2)}</td>`;
        tbody.appendChild(unusedRow);

        table.appendChild(tbody);
        container.appendChild(table);
    }
</script>
<?php
}

// AJAX handler to reset calculation time
add_action('wp_ajax_reset_calculation_time', 'handle_reset_calculation_time');
function handle_reset_calculation_time() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    $current_time = current_time('mysql');
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_20 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_36 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_80 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_160 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_161 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_162 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_405 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_800 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_810 SET calculation_time = '$current_time' WHERE used = 'no'");
    $wpdb->query("UPDATE {$wpdb->prefix}unipin_voucher_1625 SET calculation_time = '$current_time' WHERE used = 'no'");

    update_option('unipin_calculation_time', $current_time);

    wp_send_json_success(['time' => $current_time]);
}

// AJAX handler to fetch voucher codes
add_action('wp_ajax_fetch_voucher_codes', 'handle_fetch_voucher_codes');
function handle_fetch_voucher_codes() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;
    $denomination = sanitize_text_field($_POST['denomination']);
    $type = sanitize_text_field($_POST['type']);
    $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);

    if ($type === 'used') {
        $voucher_codes = $wpdb->get_col("SELECT voucher FROM $table_name WHERE used = 'yes'");
    } else {
        $voucher_codes = $wpdb->get_col("SELECT voucher FROM $table_name WHERE used = 'no'");
    }

    if (!empty($voucher_codes)) {
        wp_send_json_success(['codes' => $voucher_codes]);
    } else {
        wp_send_json_error("No voucher codes found.");
    }
}

// AJAX handler to fetch voucher codes after last calculation time
add_action('wp_ajax_fetch_voucher_codes_after_calculation', 'handle_fetch_voucher_codes_after_calculation');
function handle_fetch_voucher_codes_after_calculation() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;
    $denomination = sanitize_text_field($_POST['denomination']);
    $type = sanitize_text_field($_POST['type']);
    $last_calculation_time = get_option('unipin_calculation_time', 'Never');
    $table_name = $wpdb->prefix . 'unipin_voucher_' . strtolower($denomination);

    if ($type === 'used') {
        $voucher_codes = $wpdb->get_col($wpdb->prepare("SELECT voucher FROM $table_name WHERE used = 'yes' AND used_time > %s", $last_calculation_time));
    } else {
        $voucher_codes = $wpdb->get_col($wpdb->prepare("SELECT voucher FROM $table_name WHERE used = 'no' AND calculation_time > %s", $last_calculation_time));
    }

    if (!empty($voucher_codes)) {
        wp_send_json_success(['codes' => $voucher_codes]);
    } else {
        wp_send_json_error("No voucher codes found.");
    }
}

// AJAX handler to filter vouchers by date range
add_action('wp_ajax_filter_vouchers_by_date', 'handle_filter_vouchers_by_date');
function handle_filter_vouchers_by_date() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("You do not have permission to perform this action.");
        return;
    }

    global $wpdb;

    if (isset($_POST['start_date']) && isset($_POST['end_date'])) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $table_prefix = $wpdb->prefix;

        // Get all voucher tables
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_prefix}unipin_voucher_%'");
        $voucher_stats = [];

        foreach ($tables as $table) {
            if ($table !== "{$table_prefix}unipin_voucher_import_log" && $table !== "{$table_prefix}unipin_voucher_mapping") {
                // Get the denomination from the table name
                $denomination = str_replace("{$table_prefix}unipin_voucher_", '', $table);

                // Get the price per voucher
                $price_per_unit = get_option('voucher_price_' . $denomination, 0);

                // Query to get counts of used and unused vouchers in date range
                $total_vouchers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE import_time BETWEEN %s AND %s", $start_date, $end_date));
                $used_vouchers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE used = 'yes' AND import_time BETWEEN %s AND %s", $start_date, $end_date));
                $unused_vouchers = $total_vouchers - $used_vouchers;

                // Calculate worth in USD and BDT
                $used_cost_usd = $used_vouchers * $price_per_unit;
                $unused_cost_usd = $unused_vouchers * $price_per_unit;
                $used_cost_bdt = $used_cost_usd * get_option('USD_RATE', 0);
                $unused_cost_bdt = $unused_cost_usd * get_option('USD_RATE', 0);

                $voucher_stats[] = [
                    'denomination' => $denomination,
                    'total' => $total_vouchers,
                    'used' => $used_vouchers,
                    'unused' => $unused_vouchers,
                    'used_cost_usd' => $used_cost_usd,
                    'unused_cost_usd' => $unused_cost_usd,
                    'used_cost_bdt' => $used_cost_bdt,
                    'unused_cost_bdt' => $unused_cost_bdt,
                ];
            }
        }

        // Sort the voucher stats by denomination
        usort($voucher_stats, function($a, $b) {
            return $a['denomination'] - $b['denomination'];
        });

        wp_send_json_success(['vouchers' => $voucher_stats]);
    } else {
        wp_send_json_error("Invalid request.");
    }
}
?>
