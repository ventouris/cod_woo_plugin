<?php
/**
 * Plugin Name: Safe Delivery COD
 * Description: A WooCommerce plugin to manage and restrict COD options based on customer delivery history.
 * Version: 1.0.0
 * Author: Your Name
 */

 // [--] Task: Initialize the plugin, including hooks and loading of necessary components.
// Class: SafeDeliveryCOD
//   - Function: init() - Setup action and filter hooks for the plugin.
//   - Function: load_dependencies() - Include the admin and includes files.
//   - Function: activate() - Code to run on plugin activation (setup custom order status, maybe default settings).
//   - Function: deactivate() - Cleanup settings or temporary data on deactivation, if necessary.
// [--] Load plugin text domain for internationalization.


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class SafeDeliveryCOD {
    /**
     * Construct the plugin.
     */
    public function __construct() {
        $this->define_constants();
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Define plugin constants.
     */
    private function define_constants() {
        define( 'SDCOD_VERSION', '1.0.0' );
	define( 'SDCOD_PLUGIN_NAME', 'Safe Delivery COD' );
	define( 'SDCOD_PLUGIN_SHORT_NAME', 'SDCOD' );
        define( 'SDCOD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'SDCOD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        require_once SDCOD_PLUGIN_DIR . 'admin/admin-settings.php';
        require_once SDCOD_PLUGIN_DIR . 'admin/admin-order-meta.php';
        require_once SDCOD_PLUGIN_DIR . 'admin/admin-users-meta.php';
        require_once SDCOD_PLUGIN_DIR . 'includes/init.php';
        require_once SDCOD_PLUGIN_DIR . 'includes/order-status.php';
        require_once SDCOD_PLUGIN_DIR . 'includes/score-calculator.php';
        require_once SDCOD_PLUGIN_DIR . 'includes/hide-cod.php';
    }

    /**
     * Define the locale for this plugin for internationalization.
     */
    private function set_locale() {
        // Load text domain for internationalization
        load_plugin_textdomain( SDCOD_PLUGIN_SHORT_NAME, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     */
    private function define_admin_hooks() {
        $plugin_admin = new SDCOD_Settings();
		add_action( 'admin_enqueue_scripts', array( $this, 'SDCOD_enqueue_admin_styles' ) );
		
		//declare compatibility with Woocommerce HPOS
		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SDCOD_PLUGIN_DIR, true );
			}
		} );
		
		//add settings link to plugins list page
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array($this, 'SDCOD_settings_link' ));

		//deactivate this plugin if woocommerce is deactivated
		add_action( 'plugins_loaded', array($this, 'SDCOD_check_deactivated_plugin'), 10, 2 );

        // other admin hooks...
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     */
    private function define_public_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'SDCOD_enqueue_public_styles' ) );

        // public hooks...
    }

    /**
     * The code that runs during plugin activation.
     */
    public static function activate() {
        // Activation code here...
        if ( !class_exists( 'WooCommerce' ) ) {
			deactivate_plugins(SDCOD_PLUGIN_DIR);
			wp_die( esc_html__( 'This plugin requires WooCommerce. Download it from WooCommerce official website', SDCOD_PLUGIN_SHORT_NAME ) . ' https://woocommerce.com' );
			exit;
		}
		
    }

    /**
     * The code that runs during plugin deactivation.
     */
    public static function deactivate() {
        // Deactivation code here...
        // TODO: Add code to handle missing status order
    }
	
	public function SDCOD_enqueue_admin_styles() {
		wp_enqueue_style( 'SafeDeliveryCOD_admin_style', SDCOD_PLUGIN_URL . 'assets/css/SDCOD_admin.css', array(), SDCOD_VERSION, 'all' );
	}
	
	public function SDCOD_enqueue_public_styles() {
		wp_enqueue_style( 'SafeDeliveryCOD_public_style', SDCOD_PLUGIN_URL . 'assets/css/SDCOD_public.css', array(), SDCOD_VERSION, 'all' );
	}
	
	public function SDCOD_settings_link( array $links ) {
		$url = get_admin_url() . "/admin.php?page=wc-settings&tab=safedeliverycod";
		$settings_link = '<a href="' . $url . '">' . __('Settings', SDCOD_PLUGIN_SHORT_NAME) . '</a>';
		$links[] = $settings_link;
		return $links;
	}
	
	public function SDCOD_check_deactivated_plugin( ) {
		
		// Check if the deactivated plugin is WooCommerce
		if( !class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_init', array($this, 'SDCOD_force_deactivate') );
            add_action( 'admin_notices', array($this, 'SDCOD_plugin_deactivation_notice') );
		}
	}
	
	public function SDCOD_force_deactivate() {
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
	
	public function SDCOD_plugin_deactivation_notice() {
		echo '<div class="error"><p>Safe Delivery COD plugin has been deactivated because WooCommerce is not active. Please activate WooCommerce to use this plugin.</p>	</div>';
	}
}

// Instantiate the plugin class.
$safe_delivery_cod = new SafeDeliveryCOD();

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'SafeDeliveryCOD', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SafeDeliveryCOD', 'deactivate' ) );
