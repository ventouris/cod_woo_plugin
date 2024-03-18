<?php
if ( ! class_exists( 'SDCOD_Settings' ) ) {
// [--] Task: Setup and display the plugin settings in the WooCommerce settings area.
// Class: SDCOD_Settings
//   - Function: add_settings_tab() - Adds a new tab in WooCommerce settings for this plugin.
//   - Function: settings_tab() - Defines settings in the newly created tab (risk threshold, advanced mode, language support).
//   - Function: save_settings() - Processes and saves the settings from the settings form.
// [--] Add fields for risk threshold settings and advanced mode options.
    class SDCOD_Settings {

        public function __construct() {
            add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
            add_action( 'woocommerce_settings_tabs_safedeliverycod', array( $this, 'settings_tab' ) );
            add_action( 'woocommerce_update_options_safedeliverycod', array( $this, 'save_settings' ) );
        }

        /**
         * Add a new settings tab to the WooCommerce settings tabs.
         *
         * @param array $tabs The current tabs.
         * @return array Modified tabs.
         */
        public function add_settings_tab( $tabs ) {
            $tabs['safedeliverycod'] = __( 'SafeDeliveryCOD', 'safedeliverycod' );
            return $tabs;
        }

        /**
         * Uses WooCommerce built-in functions to define settings for the plugin's tab.
         */
        public function settings_tab() {
            woocommerce_admin_fields( $this->get_settings() );
        }

        /**
         * Uses WooCommerce built-in functions to save the settings.
         */
        public function save_settings() {
            woocommerce_update_options( $this->get_settings() );
        }

        /**
         * Defines the plugin settings.
         *
         * @return array The settings array.
         */
        private function get_settings() {
            $settings = array(
                array(
                    'title' => __( 'SafeDeliveryCOD Settings', 'safedeliverycod' ),
                    'type'  => 'title',
                    'desc'  => __( 'Manage settings for SafeDeliveryCOD.', 'safedeliverycod' ),
                    'id'    => 'safedeliverycod_settings',
					'class' => 'SDCOD_settings_title'
                ),
				//type of calculation
				 array(
                    'title' => __( 'SafeDeliveryCOD Settings', 'safedeliverycod' ),
                    'type'  => 'title',
                    'desc'  => __( 'Manage settings for SafeDeliveryCOD.', 'safedeliverycod' ),
                    'id'    => 'safedeliverycod_settings',
					'class' => 'SDCOD_settings_secondary_title'
                ),
				array(
                    'title'    => __( 'Type of Calculation', 'safedeliverycod' ),
                    'desc'     => __( 'Select if you want o choose the distinct number of canceled orders or a percentage', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type',
                    'type'    => 'radio',
    				'options' => array(
        				'number' => __('Number', 'safedeliverycod'),
        				'percentage' => __('Percentage', 'safedeliverycod'),
        				'advanced' => __('Advanced', 'safedeliverycod')
    				),
    				'default' => 'number',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				
				
                array(
                    'title'    => __( 'Risk Threshold', 'safedeliverycod' ),
                    'desc'     => __( 'The number of failed deliveries after which COD is disabled.', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_threshold',
                    'type'     => 'number',
                    'css'      => 'min-width:300px;',
                    'desc_tip' => true,
                ),
                array(
                    'title'    => __( 'Advanced Mode', 'safedeliverycod' ),
                    'desc'     => __( 'Enable advanced scoring mode for evaluating delivery risk.', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_advanced_mode',
                    'type'     => 'checkbox',
                    'default'  => 'no', // or yes, based on your preference
                ),
                array(
                    'type' => 'sectionend',
                    'id'   => 'safedeliverycod_settings'
                ),
            );

            return apply_filters( 'safedeliverycod_settings', $settings );
        }
    }


}
