<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Settings')) {
    class DRPM_Settings
    {
        const TAB = 'delivery_refusal_payment_manager';

        public function __construct()
        {
            add_filter('woocommerce_settings_tabs_array', array($this, 'addSettingsTab'), 50);
            add_action('woocommerce_settings_tabs_' . self::TAB, array($this, 'settingsTab'));
            add_action('woocommerce_update_options_' . self::TAB, array($this, 'saveSettings'));
            add_action('woocommerce_admin_field_drpm_overview', array($this, 'renderOverview'));
            add_action('admin_post_drpm_rebuild_index', array($this, 'handleRebuildIndex'));
            add_action('admin_footer', array($this, 'addSettingsValidationError'));
        }

        /**
         * Add a new settings tab to the WooCommerce settings tabs.
         *
         * @param array $tabs The current tabs.
         * @return array Modified tabs.
         */
        public function addSettingsTab($tabs)
        {
            $tabs[self::TAB] = __('Delivery Refusal', DRPM_TEXT_DOMAIN);
            return $tabs;
        }

        /**
         * Uses WooCommerce built-in functions to render the settings for the plugin's tab.
         */
        public function settingsTab()
        {
            woocommerce_admin_fields($this->get_settings());
        }

        /**
         * Validates and saves the settings.
         */
        public function saveSettings()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce before this hook.
            $hasField = false;
            foreach (DRPM_Identity::FIELDS as $option) {
                $hasField = $hasField || !empty($_POST[$option]);
            }
            $hasGateway = false;
            foreach (array_keys($this->fetchAllWooPaymentMethods()) as $gatewayId) {
                $hasGateway = $hasGateway || !empty($_POST['dprm_payment_method_' . $gatewayId]);
            }
            // phpcs:enable

            if (!$hasField || !$hasGateway) {
                WC_Admin_Settings::add_error(__('Settings were not saved: select at least one Customer Data Field and one Payment Method.', DRPM_TEXT_DOMAIN));
                return;
            }

            woocommerce_update_options($this->get_settings());

            if ('percentage' === get_option('threshold_type') && (float) get_option('threshold_value') > 100) {
                update_option('threshold_value', 100);
            }
        }

        /**
         * Fetch all the payment methods from WooCommerce.
         */
        public function fetchAllWooPaymentMethods()
        {
            $payment_methods = array();

            foreach (WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway) {
                $title = $gateway->get_method_title() ?: $gateway->get_title();
                if ('yes' !== $gateway->enabled) {
                    $title .= ' ' . __('(disabled)', DRPM_TEXT_DOMAIN);
                }
                $payment_methods[$gateway_id] = array(
                    'title' => $title,
                    'id'    => 'dprm_payment_method_' . $gateway_id,
                );
            }

            return $payment_methods;
        }

        /**
         * Keeps the save button disabled until at least one field from each required group is checked.
         */
        public function addSettingsValidationError()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!isset($_GET['tab']) || self::TAB !== $_GET['tab']) {
                return;
            }
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    const customerCheckboxes = document.querySelectorAll('[id^="customer_data_fields_"]');
                    const paymentMethodCheckboxes = document.querySelectorAll('[id^="dprm_payment_method_"]');
                    const saveButton = document.querySelector('.woocommerce-save-button, button[name="save"]');
                    const warningElement = document.getElementById('checkbox_warning');
                    if (!saveButton) {
                        return;
                    }

                    function updateButtonStatus() {
                        const isCustomerChecked = Array.from(customerCheckboxes).some(checkbox => checkbox.checked);
                        const isPaymentMethodChecked = Array.from(paymentMethodCheckboxes).some(checkbox => checkbox.checked);
                        const valid = isCustomerChecked && isPaymentMethodChecked;

                        saveButton.disabled = !valid;
                        if (warningElement) {
                            warningElement.style.display = valid ? 'none' : 'block';
                        }
                    }

                    // WooCommerce re-enables the save button on any change, so run after it.
                    document.querySelector('#mainform')?.addEventListener('change', function () {
                        setTimeout(updateButtonStatus, 0);
                    });
                    updateButtonStatus();
                });
            </script>
            <?php
        }

        /**
         * Overview panel: index status, store-wide refusal stats and the rebuild tool.
         */
        public function renderOverview($field)
        {
            $months = DRPM_Risk::months();
            $stats = DRPM_Index::storeStats($months);
            $refused = $stats[DRPM_Order_Status::STATUS] ?? 0;
            $delivered = 0;
            foreach (DRPM_Risk::deliveredStatuses() as $status) {
                $delivered += $stats[$status] ?? 0;
            }
            $rate = ($refused + $delivered) > 0 ? round($refused / ($refused + $delivered) * 100, 1) : 0;
            $flagged = $this->countFlaggedOrders($months);

            $state = get_option('drpm_backfill_state', array());
            $status = $state['status'] ?? 'never';
            $rebuildUrl = wp_nonce_url(admin_url('admin-post.php?action=drpm_rebuild_index'), 'drpm_rebuild_index');
            ?>
            <tr valign="top">
                <td colspan="2" class="drpm-overview-cell">
                    <div class="drpm-overview">
                        <div class="drpm-stat">
                            <span class="drpm-stat__value"><?php echo esc_html(number_format_i18n($refused)); ?></span>
                            <span class="drpm-stat__label"><?php esc_html_e('Refused deliveries', DRPM_TEXT_DOMAIN); ?></span>
                        </div>
                        <div class="drpm-stat">
                            <span class="drpm-stat__value"><?php echo esc_html(number_format_i18n($rate, 1)); ?>%</span>
                            <span class="drpm-stat__label"><?php esc_html_e('Refusal rate', DRPM_TEXT_DOMAIN); ?></span>
                        </div>
                        <div class="drpm-stat">
                            <span class="drpm-stat__value"><?php echo esc_html(number_format_i18n($flagged)); ?></span>
                            <span class="drpm-stat__label"><?php esc_html_e('Orders placed with methods restricted', DRPM_TEXT_DOMAIN); ?></span>
                        </div>
                    </div>
                    <p class="description">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: months */
                            $months > 0 ? __('Statistics cover the last %d months.', DRPM_TEXT_DOMAIN) : __('Statistics cover all orders.', DRPM_TEXT_DOMAIN),
                            $months
                        ));
                        echo ' ';
                        if ('running' === $status) {
                            echo esc_html(sprintf(
                                /* translators: %d: number of orders */
                                __('Order index is being built in the background (%d orders processed so far).', DRPM_TEXT_DOMAIN),
                                (int) ($state['processed'] ?? 0)
                            ));
                        } elseif ('complete' === $status) {
                            echo esc_html(sprintf(
                                /* translators: 1: number of orders, 2: date */
                                __('Order index contains %1$d orders, last rebuilt %2$s.', DRPM_TEXT_DOMAIN),
                                (int) ($state['processed'] ?? 0),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($state['finished'] ?? time()))
                            ));
                        } else {
                            esc_html_e('Existing orders have not been indexed yet.', DRPM_TEXT_DOMAIN);
                        }
                        ?>
                        <a href="<?php echo esc_url($rebuildUrl); ?>" class="button button-secondary drpm-rebuild"><?php esc_html_e('Rebuild order index', DRPM_TEXT_DOMAIN); ?></a>
                    </p>
                </td>
            </tr>
            <?php
        }

        private function countFlaggedOrders($months)
        {
            $args = array(
                'type'       => 'shop_order',
                'limit'      => 1,
                'paginate'   => true,
                'return'     => 'ids',
                'meta_key'   => DRPM_Checkout::FLAG_META, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
            );
            if ($months > 0) {
                $args['date_created'] = '>=' . strtotime("-{$months} months");
            }

            return (int) wc_get_orders($args)->total;
        }

        public function handleRebuildIndex()
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You are not allowed to do this.', DRPM_TEXT_DOMAIN), 403);
            }
            check_admin_referer('drpm_rebuild_index');

            DRPM_Index::install();
            DRPM_Index::startBackfill();

            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=' . self::TAB));
            exit;
        }

        /**
         * Defines the plugin settings.
         */
        private function get_settings()
        {
            $settings = array();

            $settings[] = array(
                'title' => __('Overview', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'id'    => 'drpm_overview_section',
            );
            $settings[] = array(
                'type' => 'drpm_overview',
                'id'   => 'drpm_overview',
            );
            $settings[] = array(
                'title'   => __('Enable restriction', DRPM_TEXT_DOMAIN),
                'desc'    => __('Hide the selected payment methods from customers at risk of refusing delivery', DRPM_TEXT_DOMAIN),
                'id'      => 'drpm_enabled',
                'type'    => 'checkbox',
                'default' => 'yes',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_overview_section',
            );

            $settings[] = array(
                'title' => __('Risk Assessment Criteria', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Configure which customer fields should be used to recognise a returning customer. A past order matching any selected field counts towards their history. Registered customers are always matched by their account too.', DRPM_TEXT_DOMAIN),
                'id'    => 'risk_assessment_criteria_section',
            );

            $settings[] = array(
                'title'         => __('Customer Data Fields', DRPM_TEXT_DOMAIN),
                'desc'          => __('Email', DRPM_TEXT_DOMAIN),
                'id'            => 'customer_data_fields_email',
                'type'          => 'checkbox',
                'default'       => 'yes',
                'checkboxgroup' => 'start',
            );
            $settings[] = array(
                'desc'          => __('Mobile number', DRPM_TEXT_DOMAIN),
                'id'            => 'customer_data_fields_email_mobile',
                'type'          => 'checkbox',
                'checkboxgroup' => '',
                'default'       => 'no',
            );
            $settings[] = array(
                'desc'          => __('Shipping address', DRPM_TEXT_DOMAIN),
                'id'            => 'customer_data_fields_email_shipping_address',
                'type'          => 'checkbox',
                'checkboxgroup' => '',
                'default'       => 'no',
            );
            $settings[] = array(
                'desc'          => __('Billing address', DRPM_TEXT_DOMAIN),
                'id'            => 'customer_data_fields_email_billing_address',
                'type'          => 'checkbox',
                'checkboxgroup' => '',
                'default'       => 'no',
            );
            $settings[] = array(
                'desc'          => __('Full name', DRPM_TEXT_DOMAIN),
                'id'            => 'customer_data_fields_email_full_name',
                'type'          => 'checkbox',
                'default'       => 'no',
                'checkboxgroup' => 'end',
            );

            $settings[] = array(
                'title'   => __('Threshold Type', DRPM_TEXT_DOMAIN),
                'id'      => 'threshold_type',
                'type'    => 'radio',
                'class'   => 'threshold_type',
                'options' => array(
                    'number'     => __('Number', DRPM_TEXT_DOMAIN),
                    'percentage' => __('Percentage', DRPM_TEXT_DOMAIN),
                ),
                'default' => 'number',
                'desc'    => __('Number: restrict after this many refused orders. Percentage: restrict when refused orders make up at least this share of the customer\'s delivered + refused orders.', DRPM_TEXT_DOMAIN),
            );
            $settings[] = array(
                'title'             => __('Threshold Value', DRPM_TEXT_DOMAIN),
                'desc'              => __('Number or percentage of refused orders that triggers the restriction. 0 disables automatic restriction.', DRPM_TEXT_DOMAIN),
                'id'                => 'threshold_value',
                'type'              => 'number',
                'css'               => 'width:80px;',
                'default'           => 2,
                'custom_attributes' => array('min' => 0, 'step' => 'any'),
            );
            $settings[] = array(
                'title'             => __('Minimum orders (percentage)', DRPM_TEXT_DOMAIN),
                'desc'              => __('With the percentage threshold, only judge customers with at least this many delivered + refused orders.', DRPM_TEXT_DOMAIN),
                'id'                => 'drpm_min_orders',
                'type'              => 'number',
                'css'               => 'width:80px;',
                'default'           => 1,
                'custom_attributes' => array('min' => 1, 'step' => 1),
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'risk_assessment_criteria_section',
            );

            $settings[] = array(
                'title' => __('Payment Methods Restriction', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Select payment methods to be hidden at checkout for customers identified at risk of refusing delivery. If every available method would be hidden, none are hidden, so customers can always pay.', DRPM_TEXT_DOMAIN),
                'id'    => 'payment_methods_restriction_section',
            );

            $payment_methods = $this->fetchAllWooPaymentMethods();
            $total_methods = count($payment_methods);
            $current_index = 0;
            foreach ($payment_methods as $gateway_id => $gateway) {
                $current_index++;
                $checkboxGroupValue = '';
                if (1 === $current_index) {
                    $checkboxGroupValue = 'start';
                } elseif ($current_index === $total_methods) {
                    $checkboxGroupValue = 'end';
                }

                $settings[] = array(
                    'title'         => 1 === $current_index ? __('Payment Methods', DRPM_TEXT_DOMAIN) : '',
                    'desc'          => $gateway['title'],
                    'id'            => $gateway['id'],
                    'type'          => 'checkbox',
                    'default'       => 'cod' === $gateway_id ? 'yes' : 'no',
                    'checkboxgroup' => $checkboxGroupValue,
                );
            }

            $settings[] = array(
                'title'       => __('Message to customer', DRPM_TEXT_DOMAIN),
                'desc'        => __('Optional. Shown above the "Place order" button when payment methods have been hidden (classic checkout). Leave empty to hide methods silently.', DRPM_TEXT_DOMAIN),
                'id'          => 'drpm_customer_notice',
                'type'        => 'textarea',
                'css'         => 'min-width:400px;height:60px;',
                'default'     => '',
                'placeholder' => __('Some payment methods are not available for this order.', DRPM_TEXT_DOMAIN),
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'payment_methods_restriction_section',
            );

            $settings[] = array(
                'title' => __('Manual Lists', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Email addresses or phone numbers, one per line. Registered customers can also be overridden individually from their user profile.', DRPM_TEXT_DOMAIN),
                'id'    => 'drpm_lists_section',
            );
            $settings[] = array(
                'title' => __('Always restrict', DRPM_TEXT_DOMAIN),
                'desc'  => __('These customers never see the restricted payment methods.', DRPM_TEXT_DOMAIN),
                'id'    => 'drpm_blocked_list',
                'type'  => 'textarea',
                'css'   => 'min-width:400px;height:80px;',
            );
            $settings[] = array(
                'title' => __('Always allow', DRPM_TEXT_DOMAIN),
                'desc'  => __('These customers are never restricted, whatever their history.', DRPM_TEXT_DOMAIN),
                'id'    => 'drpm_trusted_list',
                'type'  => 'textarea',
                'css'   => 'min-width:400px;height:80px;',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_lists_section',
            );

            $settings[] = array(
                'title' => __('Retention Period', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Define the time frame for reviewing past orders to identify customers with a history of refused deliveries.', DRPM_TEXT_DOMAIN),
                'id'    => 'retention_period_section',
            );
            $settings[] = array(
                'title'             => __('Number of Months', DRPM_TEXT_DOMAIN),
                'desc'              => __('Specify how many months in the past to analyze for refused deliveries. 0 means all time.', DRPM_TEXT_DOMAIN),
                'id'                => 'number_of_months',
                'type'              => 'number',
                'css'               => 'width:80px;',
                'default'           => 24,
                'custom_attributes' => array('min' => 0, 'step' => 1),
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'retention_period_section',
            );

            $settings[] = array(
                'title' => __('Additional Settings', DRPM_TEXT_DOMAIN),
                'type'  => 'title',
                'desc'  => __('Manage additional plugin settings.', DRPM_TEXT_DOMAIN),
                'id'    => 'additional_settings_section',
            );
            $settings[] = array(
                'title'   => __('Restock refused orders', DRPM_TEXT_DOMAIN),
                'desc'    => __('Return items to stock when an order is marked as refused delivery (like a cancellation).', DRPM_TEXT_DOMAIN),
                'id'      => 'drpm_restock_on_refusal',
                'type'    => 'checkbox',
                'default' => 'yes',
            );
            $settings[] = array(
                'title'   => __('When plugin is deleted', DRPM_TEXT_DOMAIN),
                'desc'    => __('Delete all data and convert all Refused Delivery orders to Cancelled.', DRPM_TEXT_DOMAIN),
                'id'      => 'delete_all_data_after_plugin_deletion',
                'type'    => 'checkbox',
                'default' => 'no',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'additional_settings_section',
            );

            $settings[] = array(
                'title' => ' ',
                'text'  => '<span id="checkbox_warning">' . esc_html__('At least one field from each of Customer Data Fields and Payment Methods must be selected to save changes.', DRPM_TEXT_DOMAIN) . '</span>',
                'id'    => 'checkbox_warning',
                'type'  => 'info',
            );

            return apply_filters('delivery_refusal_payment_manager_settings', $settings);
        }
    }

    new DRPM_Settings();
}
