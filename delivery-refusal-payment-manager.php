<?php
/**
 * Plugin Name: WooCommerce Delivery Refusal & Payment Manager
 * Description: The WooCommerce Delivery Refusal & Payment Manager is a powerful tool designed to mitigate the impact of delivery refusals by intelligently managing and restricting payment options based on customers' order history.
 * Version: 1.0.0
 * Author: Your Name
 * 
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

// use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initializes the plugin by setting up localization, loading dependencies,
 * and defining admin and public hooks.
 */
class DeliveryRefusalPaymentManager
{
    /**
     * Constructor.
     *
     * Sets up the necessary plugin constants, loads dependencies, defines locale settings,
     * and registers admin and public hooks.
     */
    public function __construct()
    {
        $this->defineConstants();
        $this->loadDependencies();
        $this->setLocale();
        $this->defineAdminHooks();
        $this->definePublicHooks();
    }

    /**
     * Define plugin constants.
     *
     * Sets up the basic constants used throughout the plugin, including the version,
     * directory paths, and URL paths.
     */
    private function defineConstants()
    {
        define( 'DRPM_VERSION', '1.0.0' );
		define( 'DRPM_PLUGIN_NAME', 'WooCommerce Delivery Refusal & Payment Manager' );
		define( 'DRPM_PLUGIN_SHORT_NAME', 'DRPM' );
        define( 'DRPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'DRPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    /**
     * Load the required dependencies for the plugin.
     *
     * Includes the necessary files for the plugin's functionality, such as admin settings,
     * meta boxes, and core plugin functionalities.
     */
    private function loadDependencies()
    {
        require_once DRPM_PLUGIN_DIR . 'admin/admin-settings.php';
        require_once DRPM_PLUGIN_DIR . 'admin/admin-order-meta.php';
        require_once DRPM_PLUGIN_DIR . 'admin/admin-users-meta.php';
        require_once DRPM_PLUGIN_DIR . 'includes/init.php';
        require_once DRPM_PLUGIN_DIR . 'includes/order-status.php';
        require_once DRPM_PLUGIN_DIR . 'includes/score-calculator.php';
        require_once DRPM_PLUGIN_DIR . 'includes/hide-payment-gateway.php';
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Loads the plugin's text domain for translation.
     */
    private function setLocale()
    {
        load_plugin_textdomain('delivery_refusal_payment_manager', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Register all hooks related to the admin area functionality of the plugin.
     *
     * This includes enqueueing admin styles, declaring WooCommerce compatibility,
     * adding settings links, and handling plugin deactivation checks.
     */
    private function defineAdminHooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminStyles']);
        
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DRPM_PLUGIN_DIR, true);
            }
        });
        
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'addSettingsLink'));
        
        add_action('plugins_loaded', array($this, 'checkDeactivatedPlugin'));
		
		add_action('admin_notices', array($this,'debugingCodeAdminNotices'));

	}

    /**
     * Register all hooks related to the public-facing functionality of the plugin.
     *
     * This primarily involves enqueueing public styles.
     */
    private function definePublicHooks()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueuePublicStyles'));
    }

    /**
     * Handles plugin activation logic.
     *
     * Checks if WooCommerce is active and deactivates this plugin if it's not.
     */
    public static function activate()
    {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires WooCommerce. Download it from the WooCommerce official website: https://woocommerce.com');
        }
    }

    /**
     * Handles plugin deactivation logic.
     */
    public static function deactivate()
    {
        // This method intentionally left blank.
    }

    /**
     * Enqueues admin-specific styles.
     */
    public function enqueueAdminStyles()
    {
        wp_enqueue_style('delivery_refusal_payment_manager-admin-style', DRPM_PLUGIN_URL . 'assets/css/admin.css', [], DRPM_VERSION, 'all');
    }

    /**
     * Enqueues public-facing styles.
     */
    public function enqueuePublicStyles()
    {
        wp_enqueue_style('delivery_refusal_payment_manager-public-style', DRPM_PLUGIN_URL . 'assets/css/public.css', [], DRPM_VERSION, 'all');
    }

    /**
     * Adds a settings link to the plugin action links.
     *
     * @param array $links Existing plugin action links.
     * @return array The modified list of plugin action links.
     */
    public function addSettingsLink($links)
    {
        $settingsLink = '<a href="' . admin_url('admin.php?page=wc-settings&tab=delivery_refusal_payment_manager') . '">' . __('Settings', 'delivery_refusal_payment_manager') . '</a>';
        array_push($links, $settingsLink);
        return $links;
    }

    /**
     * Checks if WooCommerce is active and deactivates this plugin if it is not.
     *
     * Intended to be called on the 'plugins_loaded' action hook to ensure
     * WooCommerce is active, deactivating this plugin if necessary.
     */
    public function checkDeactivatedPlugin()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_init', array($this, 'forceDeactivate'));
            add_action('admin_notices', array($this, 'pluginDeactivationNotice'));
        }
    }

    /**
     * Forces deactivation of this plugin.
     *
     * Utilized when WooCommerce is not found to be active, ensuring this
     * plugin does not remain active without its dependency.
     */
    public function forceDeactivate()
    {
        deactivate_plugins(plugin_basename(__FILE__));
    }

    /**
     * Displays an admin notice indicating this plugin has been deactivated.
     *
     * Provides a clear message to the admin that WooCommerce is required
     * for this plugin to function, shown upon forced deactivation.
     */
    public function pluginDeactivationNotice()
    {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Delivery Refusal & Payment Manager plugin has been deactivated because WooCommerce is not active. Please activate WooCommerce to use this plugin.', 'delivery_refusal_payment_manager') . '</p></div>';
    }

    /**
     * Checks if the new WooCommerce Custom Order Table feature is enabled.
     *
     * @return bool True if the new WooCommerce Custom Order Table feature is enabled, false otherwise.
     */
    public function isHPOSEnabled()
    {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return true;
		}
//         if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil') &&
//             method_exists('Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility')) {
//             return wc_get_container()->get(CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled();
//         }
        return false;
    }
	
	public function debugingCodeAdminNotices()
	{
		$isEnabled = $this->isHPOSEnabled();
		$message = $isEnabled ? 'enabled' : 'disabled';
		echo '<div class="notice notice-info is-dismissible"><p>The HPOS in WooCommerce is currently ' . esc_html($message) . '.</p></div>';    
	}
}

// Instantiate and initialize the plugin.
$deliveryRefusalPaymentManager = new DeliveryRefusalPaymentManager();

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('DeliveryRefusalPaymentManager', 'activate'));
register_deactivation_hook(__FILE__, array('DeliveryRefusalPaymentManager', 'deactivate'));
