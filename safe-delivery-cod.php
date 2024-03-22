<?php
/**
 * Plugin Name: SafeDeliveryCOD
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
        load_plugin_textdomain( 'safedeliverycod', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     */
    private function define_admin_hooks() {
        $plugin_admin = new SDCOD_Settings();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

        // other admin hooks...
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     */
    private function define_public_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles' ) );

        // public hooks...
    }

    /**
     * The code that runs during plugin activation.
     */
    public static function activate() {
        // Activation code here...
    }

    /**
     * The code that runs during plugin deactivation.
     */
    public static function deactivate() {
        // Deactivation code here...
    }
	
	public function enqueue_admin_styles() {
		wp_enqueue_style( 'SafeDeliveryCOD_admin_style', SDCOD_PLUGIN_URL . 'assets/css/SDCOD_admin.css', array(), SDCOD_VERSION, 'all' );
	}
	
	public function enqueue_public_styles() {
		wp_enqueue_style( 'SafeDeliveryCOD_public_style', SDCOD_PLUGIN_URL . 'assets/css/SDCOD_public.css', array(), SDCOD_VERSION, 'all' );
	}
}

// Instantiate the plugin class.
$safe_delivery_cod = new SafeDeliveryCOD();

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'SafeDeliveryCOD', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SafeDeliveryCOD', 'deactivate' ) );
