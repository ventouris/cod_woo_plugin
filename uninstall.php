<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is only removed when "Delete all data and convert all Refused Delivery orders
 * to Cancelled" is enabled in the settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if ('yes' !== get_option('drpm_delete_data_on_uninstall')) {
    return;
}

global $wpdb;

// Move refused orders to Cancelled so they don't end up with an unknown status.
if (function_exists('wc_get_orders')) {
    // Our plugin is no longer loaded, so make WooCommerce aware of the status again.
    add_filter('wc_order_statuses', function ($statuses) {
        $statuses['wc-refused-delivery'] = 'Delivery refused';
        return $statuses;
    });

    do {
        $orders = wc_get_orders(array(
            'type'   => 'shop_order',
            'status' => array('wc-refused-delivery'),
            'limit'  => 100,
        ));
        foreach ($orders as $order) {
            $order->update_status('cancelled', 'COD Guard was removed: status changed from Delivery refused.');
        }
    } while (count($orders) === 100);
}

// Options.
$options = array(
    'drpm_enabled',
    'drpm_match_email',
    'drpm_match_phone',
    'drpm_match_shipping_address',
    'drpm_match_billing_address',
    'drpm_match_full_name',
    'drpm_threshold_type',
    'drpm_threshold_value',
    'drpm_min_orders',
    'drpm_lookback_months',
    'drpm_options_version',
    'drpm_customer_notice',
    'drpm_blocked_list',
    'drpm_trusted_list',
    'drpm_restock_on_refusal',
    'drpm_refusal_cost',
    'drpm_db_version',
    'drpm_key_fingerprint',
    'drpm_needs_backfill',
    'drpm_backfill_state',
    'drpm_delete_data_on_uninstall',
);
foreach ($options as $option) {
    delete_option($option);
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'drpm\\_hide\\_gateway\\_%'");

// User and order meta.
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_drpm_override', 'safedeliverycod_risk_score')");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_drpm\\_%'");
$hposMeta = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hposMeta)) === $hposMeta) {
    $wpdb->query("DELETE FROM {$hposMeta} WHERE meta_key LIKE '\\_drpm\\_%'");
}

// Index table and scheduled jobs.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}drpm_order_index");
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('drpm_backfill_batch');
}
