<?php
/**
 * Plugin Name: WooCommerce Delivery Refusal & Payment Manager
 * Description: Mitigates the impact of delivery refusals by tracking refused deliveries and automatically hiding risky payment methods (e.g. Cash on Delivery) from customers with a history of refusing orders.
 * Version: 2.0.0
 * Author: Your Name
 * Text Domain: delivery_refusal_payment_manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.1
 * WC tested up to: 9.4
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

define('DRPM_VERSION', '2.0.0');
define('DRPM_PLUGIN_NAME', 'WooCommerce Delivery Refusal & Payment Manager');
define('DRPM_PLUGIN_SHORT_NAME', 'DRPM');
define('DRPM_PLUGIN_FILE', __FILE__);
define('DRPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DRPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DRPM_TEXT_DOMAIN', 'delivery_refusal_payment_manager');

/**
 * Initializes the plugin by setting up localization, loading dependencies,
 * and defining admin and public hooks.
 */
class DeliveryRefusalPaymentManager
{
    /**
     * Constructor.
     *
     * Declares WooCommerce feature compatibility and defers everything else until
     * all plugins are loaded, so we can safely check that WooCommerce is present.
     */
    public function __construct()
    {
        add_action('before_woocommerce_init', array($this, 'declareCompatibility'));
        add_action('plugins_loaded', array($this, 'init'), 20);
    }

    /**
     * Boots the plugin once all plugins are loaded.
     */
    public function init()
    {
        $this->setLocale();

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'missingWooCommerceNotice'));
            return;
        }

        $this->loadDependencies();
        DRPM_Index::maybeUpgrade();

        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminStyles'));
        add_filter('plugin_action_links_' . plugin_basename(DRPM_PLUGIN_FILE), array($this, 'addSettingsLink'));
    }

    /**
     * Declares compatibility with HPOS (custom order tables) and the Cart & Checkout blocks.
     */
    public function declareCompatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DRPM_PLUGIN_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', DRPM_PLUGIN_FILE, true);
        }
    }

    /**
     * Load the required dependencies for the plugin.
     */
    private function loadDependencies()
    {
        require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-identity.php';
        require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-index.php';
        require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-risk.php';
        require_once DRPM_PLUGIN_DIR . 'includes/order-status.php';
        require_once DRPM_PLUGIN_DIR . 'includes/hide-payment-gateway.php';

        if (is_admin()) {
            require_once DRPM_PLUGIN_DIR . 'admin/admin-settings.php';
            require_once DRPM_PLUGIN_DIR . 'admin/admin-order-meta.php';
            require_once DRPM_PLUGIN_DIR . 'admin/admin-users-meta.php';
        }

        if (defined('WP_CLI') && WP_CLI) {
            require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-cli.php';
        }
    }

    /**
     * Loads the plugin's text domain for translation.
     */
    private function setLocale()
    {
        load_plugin_textdomain(DRPM_TEXT_DOMAIN, false, dirname(plugin_basename(DRPM_PLUGIN_FILE)) . '/languages/');
    }

    /**
     * Handles plugin activation logic.
     *
     * Refuses to activate without WooCommerce, creates the order index table and
     * queues a backfill of existing orders.
     */
    public static function activate()
    {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(DRPM_PLUGIN_FILE));
            wp_die(
                esc_html__('This plugin requires WooCommerce. Please install and activate WooCommerce first.', DRPM_TEXT_DOMAIN),
                '',
                array('back_link' => true)
            );
        }

        require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-identity.php';
        require_once DRPM_PLUGIN_DIR . 'includes/class-drpm-index.php';
        DRPM_Index::install();
        update_option('drpm_needs_backfill', 'yes', false);
    }

    /**
     * Handles plugin deactivation logic.
     */
    public static function deactivate()
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('drpm_backfill_batch');
        }
    }

    /**
     * Enqueues admin-specific styles.
     */
    public function enqueueAdminStyles()
    {
        wp_enqueue_style('delivery_refusal_payment_manager-admin-style', DRPM_PLUGIN_URL . 'assets/css/admin.css', array(), DRPM_VERSION, 'all');
    }

    /**
     * Adds a settings link to the plugin action links.
     *
     * @param array $links Existing plugin action links.
     * @return array The modified list of plugin action links.
     */
    public function addSettingsLink($links)
    {
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=delivery_refusal_payment_manager')) . '">' . esc_html__('Settings', DRPM_TEXT_DOMAIN) . '</a>';
        array_unshift($links, $settingsLink);
        return $links;
    }

    /**
     * Displays an admin notice when WooCommerce is not active.
     */
    public function missingWooCommerceNotice()
    {
        echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce Delivery Refusal & Payment Manager requires WooCommerce to be installed and active.', DRPM_TEXT_DOMAIN) . '</p></div>';
    }

    /**
     * Checks if the WooCommerce High-Performance Order Storage feature is enabled.
     *
     * @return bool
     */
    public static function isHPOSEnabled()
    {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}

// Instantiate and initialize the plugin.
$deliveryRefusalPaymentManager = new DeliveryRefusalPaymentManager();

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('DeliveryRefusalPaymentManager', 'activate'));
register_deactivation_hook(__FILE__, array('DeliveryRefusalPaymentManager', 'deactivate'));
