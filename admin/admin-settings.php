<?php
if ( ! class_exists( 'SDCOD_Settings' ) ) {
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
				
				//Intro
                array(
                    'title' => __( 'SafeDeliveryCOD Settings', 'safedeliverycod' ),
                    'type'  => 'title',
                    'desc'  => __( 'Manage settings for SafeDeliveryCOD.', 'safedeliverycod' ),
                    'id'    => 'safedeliverycod_settings',
					'class' => 'SDCOD_settings_title'
                ),
				
				//order fields
				array(
                    'title'    => __( 'Fields', 'safedeliverycod' ),
                    'desc'     => __( 'Email', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type1',
                    'type'    => 'checkbox',
    				'default' => 'yes',
					'checkboxgroup' => 'start',
					'disabled' => 'disabled',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				array(
                    'desc'     => __( 'Mobile number', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type2',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				array(
                    'desc'     => __( 'Shipping address', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type3',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				array(
                    'desc'     => __( 'Billing address', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type4',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				array(
                    'desc'     => __( 'Full name', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type4',
                    'type'    => 'checkbox',
    				'default' => 'no',
					'checkboxgroup' => 'end',
					'class' => 'SDCOD_settings_typeofcalc'
                ),
				
				//score calculations
				array(
                    'title'    => __( 'Type of Calculation', 'safedeliverycod' ),
                    'desc'     => __( 'Select if you want o choose the distinct number of canceled orders or a percentage', 'safedeliverycod' ),
                    'id'       => 'safedeliverycod_risk_calc_type',
                    'type'    => 'radio',
    				'options' => array(
        				'number' => __('Number', 'safedeliverycod'),
        				'percentage' => __('Percentage', 'safedeliverycod')
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
                    'type' => 'sectionend',
                    'id'   => 'safedeliverycod_settings'
                ),
            );

            return apply_filters( 'safedeliverycod_settings', $settings );
        }
    }
	new SDCOD_Settings();

}
				
