<?php
if ( ! class_exists( 'DRPM_Settings' ) ) {
    class DRPM_Settings {

        public function __construct() 
		{
            add_filter( 'woocommerce_settings_tabs_array', array( $this, 'addSettingsTab' ), 50 );
            add_action( 'woocommerce_settings_tabs_delivery_refusal_payment_manager', array( $this, 'settingsTab' ) );
            add_action( 'woocommerce_update_options_delivery_refusal_payment_manager', array( $this, 'saveSettings' ));
			add_action('admin_footer', array($this,'addSettingsValidationError'));
        }

        /**
         * Add a new settings tab to the WooCommerce settings tabs.
         *
         * @param array $tabs The current tabs.
         * @return array Modified tabs.
         */
        public function addSettingsTab( $tabs ) 
		{
            $tabs['delivery_refusal_payment_manager'] = __( 'Delivery Refusal & Payment Manager', 'delivery_refusal_payment_manager' );
            return $tabs;
        }

        /**
         * Uses WooCommerce built-in functions to define settings for the plugin's tab.
         */
        public function settingsTab() 
		{
            woocommerce_admin_fields( $this->get_settings() );
        }

        /**
         * Uses WooCommerce built-in functions to save the settings.
         */
        public function saveSettings() 
		{
            woocommerce_update_options( $this->get_settings() );
        }
		
		/**
		 * Fetch all the payment methods from Woocommerce
		 * 
		 */
		
		public function fetchAllWooPaymentMethods()
		{
			 // Get all available payment gateways
			$payment_gateways = WC()->payment_gateways->payment_gateways();

			$payment_methods = [];

			// Loop through the gateways to get their details
			foreach ($payment_gateways as $gateway_id => $gateway) {
				$payment_methods[$gateway_id] = [
					'title' => $gateway->get_title(),
					'id' => 'dprm_payment_method_' . $gateway_id
				];
			}

			return $payment_methods;
		}
		
		/**
		 * Check if validation of settings form is ok and return an error message
		 */
		public function addSettingsValidationError() 
		{
			// Check if on the correct settings page, adjust conditional check as needed
			if (!isset($_GET['tab']) || $_GET['tab'] !== 'delivery_refusal_payment_manager') {
				return;
			}
			?>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					// Select both groups of checkboxes
					const customerCheckboxes = document.querySelectorAll('[id^="customer_data_fields_"]');
					const paymentMethodCheckboxes = document.querySelectorAll('[id^="dprm_payment_method_"]');
					const saveButton = document.querySelector('button[name="save"]');
					const warningElement = document.getElementById('checkbox_warning');

					function updateButtonStatus() {
						// Check if any checkbox in either group is checked
						let isCustomerChecked = Array.from(customerCheckboxes).some(checkbox => checkbox.checked);
						let isPaymentMethodChecked = Array.from(paymentMethodCheckboxes).some(checkbox => checkbox.checked);

						if (isCustomerChecked && isPaymentMethodChecked) {
							saveButton.disabled = false;
							if (warningElement) {
								warningElement.style.display = 'none';
							}
						} else {
							saveButton.disabled = true;
							if (warningElement) warningElement.style.display = 'block';
						}
					}

					// Initial check
					updateButtonStatus();

					// Setup event listeners for all checkboxes
					[...customerCheckboxes, ...paymentMethodCheckboxes].forEach(function(checkbox) {
						checkbox.addEventListener('change', updateButtonStatus);
					});
				});
			</script>
			<?php
		}

		
        /**
         * Defines the plugin settings.
         */
        private function get_settings() 
		{
           				
        	$settings[] = array(
                    'title' => __( 'Risk Assessment Criteria', 'delivery_refusal_payment_manager' ),
                    'type'  => 'title',
                    'desc'  => __( 'Configure which customer fields should be used to assess the risk of delivery refusal based on historical data.', 'delivery_refusal_payment_manager'  ),
                    'id'    => 'risk_assessment_criteria_section'
                );
						
			$settings[] = array(
                    'title'    => __( 'Customer Data Fields', 'delivery_refusal_payment_manager' ),
                    'desc'     => __( 'Email', 'delivery_refusal_payment_manager' ),
                    'id'       => 'customer_data_fields_email',
                    'type'    => 'checkbox',
    				'default' => 'yes',
					'checkboxgroup' => 'start'
                );
			
			$settings[] = array(
                    'desc'     => __( 'Mobile number', 'delivery_refusal_payment_manager' ),
                    'id'       => 'customer_data_fields_email_mobile',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no'
                );
			
			$settings[] = array(
                    'desc'     => __( 'Shipping address', 'delivery_refusal_payment_manager' ),
                    'id'       => 'customer_data_fields_email_shipping_address',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no'
                );
			
			$settings[] = array(
                    'desc'     => __( 'Billing address', 'delivery_refusal_payment_manager' ),
                    'id'       => 'customer_data_fields_email_billing_address',
                    'type'    => 'checkbox',
					'checkboxgroup' => '',
    				'default' => 'no'
                );
			
			$settings[] = array(
                    'desc'     => __( 'Full name', 'delivery_refusal_payment_manager' ),
                    'id'       => 'customer_data_fields_email_full_name',
                    'type'    => 'checkbox',
    				'default' => 'no',
					'checkboxgroup' => 'end'
                );
				
			$settings[] = array(
                    'title'    => __( 'Threshold Type', 'delivery_refusal_payment_manager' ),
                    'id'       => 'threshold_type',
                    'type'    => 'radio',
		    'class' => 'threshold_type',
    		    'options' => array(
        	        'number' => __('Number', 'delivery_refusal_payment_manager'),
        		'percentage' => __('Percentage', 'delivery_refusal_payment_manager')
    			),
    		    'default' => 'number',
		    'desc' 	 => __('Specify whether to use a fixed number or a percentage of past refused orders to trigger risk detection.', 'delivery_refusal_payment_manager'),
		    //'desc_tip' => true
                );
				
			$settings[] = array(
                    'title'    => __( 'Threshold Value', 'delivery_refusal_payment_manager' ),
                    'desc'     => __( 'Enter the threshold number or percentage of refused orders that will trigger the risk detection process.' ),
                    'id'       => 'threshold_value',
                    'type'     => 'number',
                    'css'      => 'width:50px;',
                    //'desc_tip' => true,
					'value'    => 2
                );
			
			$settings[] = array(
						'type' => 'sectionend',
						'id'   => 'risk_assessment_criteria_section'
				);

			$settings[] = array(
						'title' => __( 'Payment Methods Restriction', 'delivery_refusal_payment_manager' ),
						'type'  => 'title',
						'desc'  => __( 'Select payment methods to be hidden at checkout for customers identified at risk of refusing delivery.', 'delivery_refusal_payment_manager' ),
						'id'    => 'payment_methods_restriction_section'
					
				);
			
			$payment_methods = $this->fetchAllWooPaymentMethods();
			$total_methods = count($payment_methods); // Total number of payment methods
			$current_index = 0; // Current index within the loop
			foreach ($payment_methods as $gateway_id => $gateway) {
				$current_index++; // Increment at the start of the loop
    			$checkboxGroupValue = $current_index === 1 ? 'start' : 
                          ($current_index === $total_methods ? 'end' : '');
				
				$settings[] = array(
						'title' => $current_index === 1 ? __( 'Payment Methods', 'delivery_refusal_payment_manager' ) : '', // Assuming 'title' key exists in your payment method details
						'desc'  => $gateway['title'], // Or any other description if needed
						'id'    => 'dprm_payment_method_' . $gateway_id,
						'type'  => 'checkbox',
						'default' =>  $gateway_id == 'cod' ? 'yes' : 'no',
					    'checkboxgroup' => $checkboxGroupValue, // Set based on current iteration
				);
			}
				
			$settings[] = array(
                    'type' => 'sectionend',
                    'id'   => 'payment_methods_restriction_section'
                );
				
			$settings[] = array(
                    'title' => __( 'Retention Period', 'delivery_refusal_payment_manager' ),
                    'type'  => 'title',
                    'desc'  => __( 'Define the time frame for reviewing past orders to identify customers with a history of refused deliveries.', 'delivery_refusal_payment_manager' ),
                    'id'    => 'retention_period_section',
                );
				
            $settings[] = array(
                    'title'    => __( 'Number of Months', 'delivery_refusal_payment_manager' ),
                    'desc'     => __( 'Specify how many months in the past to analyze for refused deliveries.', 'delivery_refusal_payment_manager' ),
                    'id'       => 'number_of_months',
                    'type'     => 'number',
                    'css'      => 'width:50px;',
                 //   'desc_tip' => true,
					'value'    => 24
                );

            $settings[] = array(
                    'type' => 'sectionend',
                    'id'   => 'payment_methods_restriction_section'
                );
				
			$settings[] = array(
                    'title' => __( 'Additional Settings', 'delivery_refusal_payment_manager' ),
                    'type'  => 'title',
                    'desc'  => __( 'Manage additional plugin settings.', 'delivery_refusal_payment_manager' ),
                    'id'    => 'additional_settings_section',
                );
				
			$settings[] = array(
					'title'	   => __('When plugin is deleted', 'delivery_refusal_payment_manager'),
                    'desc'     => __( 'Delete all data and convert all Refused Delivery orders to Cancelled.', 'delivery_refusal_payment_manager' ),
                    'id'       => 'delete_all_data_after_plugin_deletion',
                    'type'    => 'checkbox',
    				'default' => 'no',
                );

            $settings[] = array(
                    'type' => 'sectionend',
                    'id'   => 'additional_settings_section'
                );
			
			$settings[] = array(
					'title'  	=> ' ',
                    'text'     => '<span id="checkbox_warning">' . __( 'At least one field from each of Customer Data Fields and Payment Methods must be selected to save changes.', 'delivery_refusal_payment_manager' ) . '</span>',
                    'id'       => 'checkbox_warning',
                    'type'    => 'info',
                );

            return apply_filters( 'delivery_refusal_payment_manager_settings', $settings );
		}
    }
	new DRPM_Settings();

}
				
