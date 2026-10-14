<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Settings')) {
    /**
     * Plugin settings, rendered on the "Settings" tab of WooCommerce → Delivery refusals
     * using the WooCommerce settings API (same field types, styles and storage).
     */
    class DRPM_Settings
    {
        /** Former WooCommerce settings tab ID, kept to redirect old links. */
        const TAB = 'delivery_refusal_payment_manager';

        /** @var DRPM_Settings|null */
        private static $instance = null;

        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public static function url($fragment = '')
        {
            return DRPM_Dashboard::url(array('tab' => 'settings')) . ($fragment ? '#' . $fragment : '');
        }

        private function __construct()
        {
            add_action('woocommerce_admin_field_drpm_index_status', array($this, 'renderIndexStatus'));
            add_action('woocommerce_admin_field_drpm_threshold', array($this, 'renderThresholdField'));
            add_action('admin_post_drpm_rebuild_index', array($this, 'handleRebuildIndex'));
            add_action('admin_init', array($this, 'redirectLegacyTab'));
            add_action('admin_footer', array($this, 'addSettingsValidationError'));
        }

        /**
         * Settings used to live under WooCommerce → Settings → Delivery Refusal (2.0).
         */
        public function redirectLegacyTab()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (isset($_GET['page'], $_GET['tab']) && 'wc-settings' === $_GET['page'] && self::TAB === $_GET['tab']) {
                wp_safe_redirect(self::url());
                exit;
            }
        }

        private static function loadWooSettingsApi()
        {
            if (!class_exists('WC_Admin_Settings', false)) {
                include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
            }
        }

        /**
         * Saves the form. Runs on the page's load hook, before any output.
         */
        public function handleSave()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (empty($_POST['drpm_save_settings']) || !current_user_can('manage_woocommerce')) {
                return;
            }
            check_admin_referer('drpm_save_settings');
            self::loadWooSettingsApi();

            if ($this->saveSettings()) {
                WC_Admin_Settings::add_message(__('Your settings have been saved.', 'cod-guard'));
            }
        }

        /**
         * Outputs the settings form.
         */
        public function render()
        {
            self::loadWooSettingsApi();
            WC_Admin_Settings::show_messages();
            ?>
            <form method="post" id="mainform" action="<?php echo esc_url(self::url()); ?>" class="drpm-settings">
                <?php woocommerce_admin_fields($this->get_settings()); ?>
                <p class="submit">
                    <?php wp_nonce_field('drpm_save_settings'); ?>
                    <button name="drpm_save_settings" class="button-primary woocommerce-save-button" type="submit" value="1"><?php esc_html_e('Save changes', 'cod-guard'); ?></button>
                </p>
            </form>
            <?php
        }

        /**
         * Validates and saves the settings.
         *
         * @return bool Whether the settings were saved.
         */
        public function saveSettings()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handleSave().
            $hasField = false;
            foreach (DRPM_Identity::FIELDS as $option) {
                $hasField = $hasField || !empty($_POST[$option]);
            }
            $hasGateway = false;
            foreach (array_keys($this->fetchAllWooPaymentMethods()) as $gatewayId) {
                $hasGateway = $hasGateway || !empty($_POST[DRPM_Risk::GATEWAY_OPTION_PREFIX . $gatewayId]);
            }
            // phpcs:enable

            if (!$hasField || !$hasGateway) {
                WC_Admin_Settings::add_error(__('Settings were not saved. Pick at least one way to recognise customers and one payment method to hide.', 'cod-guard'));
                return false;
            }

            woocommerce_update_options($this->get_settings());
            $this->saveThresholdField();

            // Keep an unchanged default message language-neutral, so customers see it in their language.
            if (trim((string) get_option('drpm_customer_notice')) === DRPM_Checkout::defaultNotice()) {
                update_option('drpm_customer_notice', DRPM_Checkout::DEFAULT_NOTICE);
            }

            return true;
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
                    $title .= ' ' . __('(disabled)', 'cod-guard');
                }
                $payment_methods[$gateway_id] = array(
                    'title' => $title,
                    'id'    => DRPM_Risk::GATEWAY_OPTION_PREFIX . $gateway_id,
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
            if (!isset($_GET['page'], $_GET['tab']) || DRPM_Dashboard::PAGE !== $_GET['page'] || 'settings' !== $_GET['tab']) {
                return;
            }
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    const customerCheckboxes = document.querySelectorAll('[id^="drpm_match_"]');
                    const paymentMethodCheckboxes = document.querySelectorAll('[id^="drpm_hide_gateway_"]');
                    const saveButton = document.querySelector('#mainform .woocommerce-save-button');
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

                    // WooCommerce scripts may re-enable the save button on change, so run after them.
                    document.querySelector('#mainform')?.addEventListener('change', function () {
                        setTimeout(updateButtonStatus, 0);
                    });
                    updateButtonStatus();
                });
            </script>
            <?php
        }

        /**
         * Order index status and the rebuild tool.
         */
        public function renderIndexStatus($field)
        {
            $state = get_option('drpm_backfill_state', array());
            $status = $state['status'] ?? 'never';
            $rebuildUrl = wp_nonce_url(admin_url('admin-post.php?action=drpm_rebuild_index'), 'drpm_rebuild_index');
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc"><?php esc_html_e('Order index', 'cod-guard'); ?></th>
                <td class="forminp">
                    <p>
                        <?php
                        if ('running' === $status) {
                            echo esc_html(sprintf(
                                /* translators: %d: number of orders */
                                __('Being built in the background (%d orders processed so far).', 'cod-guard'),
                                (int) ($state['processed'] ?? 0)
                            ));
                        } elseif ('complete' === $status) {
                            echo esc_html(sprintf(
                                /* translators: 1: number of orders, 2: date */
                                __('Contains %1$s orders, last rebuilt %2$s.', 'cod-guard'),
                                number_format_i18n((int) ($state['processed'] ?? 0)),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($state['finished'] ?? time()))
                            ));
                        } else {
                            esc_html_e('Existing orders have not been indexed yet.', 'cod-guard');
                        }
                        ?>
                        <a href="<?php echo esc_url($rebuildUrl); ?>" class="button button-secondary drpm-rebuild"><?php esc_html_e('Rebuild order index', 'cod-guard'); ?></a>
                    </p>
                    <p class="description"><?php esc_html_e('New and updated orders are indexed automatically. Rebuild only if numbers look wrong.', 'cod-guard'); ?></p>
                </td>
            </tr>
            <?php
        }

        public function handleRebuildIndex()
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(esc_html__('You are not allowed to do this.', 'cod-guard'), 403);
            }
            check_admin_referer('drpm_rebuild_index');

            DRPM_Index::install();
            DRPM_Index::startBackfill();

            wp_safe_redirect(self::url());
            exit;
        }

        /**
         * The restriction rule as one sentence: "Restrict when a customer has refused [2] [or more orders]".
         */
        public function renderThresholdField($field)
        {
            $type = get_option('drpm_threshold_type', 'number');
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc"><label for="drpm_threshold_value"><?php esc_html_e('Restriction rule', 'cod-guard'); ?></label></th>
                <td class="forminp drpm-rule">
                    <?php esc_html_e('Restrict when a customer has refused', 'cod-guard'); ?>
                    <input type="number" name="drpm_threshold_value" id="drpm_threshold_value" min="0" step="any" value="<?php echo esc_attr(get_option('drpm_threshold_value', 2)); ?>" style="width:70px;" />
                    <select name="drpm_threshold_type" id="drpm_threshold_type" aria-label="<?php esc_attr_e('Rule type', 'cod-guard'); ?>">
                        <option value="number" <?php selected($type, 'number'); ?>><?php esc_html_e('or more orders', 'cod-guard'); ?></option>
                        <option value="percentage" <?php selected($type, 'percentage'); ?>><?php esc_html_e('% or more of their orders', 'cod-guard'); ?></option>
                    </select>
                    <span class="drpm-rule__min" <?php echo 'percentage' === $type ? '' : 'hidden'; ?>>
                        <?php esc_html_e('once they have at least', 'cod-guard'); ?>
                        <input type="number" name="drpm_min_orders" id="drpm_min_orders" min="1" step="1" value="<?php echo esc_attr(get_option('drpm_min_orders', 1)); ?>" style="width:60px;" />
                        <?php esc_html_e('completed or refused orders', 'cod-guard'); ?>
                    </span>
                    <p class="description"><?php esc_html_e('Set it to 0 to turn off automatic restriction. The exception lists still apply.', 'cod-guard'); ?></p>
                    <script>
                        document.getElementById('drpm_threshold_type').addEventListener('change', function () {
                            document.querySelector('.drpm-rule__min').hidden = 'percentage' !== this.value;
                        });
                    </script>
                </td>
            </tr>
            <?php
        }

        private function saveThresholdField()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handleSave().
            $type = isset($_POST['drpm_threshold_type']) && 'percentage' === $_POST['drpm_threshold_type'] ? 'percentage' : 'number';
            $value = isset($_POST['drpm_threshold_value']) ? max(0, (float) wc_clean(wp_unslash($_POST['drpm_threshold_value']))) : 2;
            $minOrders = isset($_POST['drpm_min_orders']) ? max(1, absint($_POST['drpm_min_orders'])) : 1;
            // phpcs:enable

            if ('percentage' === $type) {
                $value = min(100, $value);
            }
            update_option('drpm_threshold_type', $type);
            update_option('drpm_threshold_value', $value);
            update_option('drpm_min_orders', $minOrders);
        }

        /**
         * Defines the plugin settings.
         */
        private function get_settings()
        {
            $settings = array();

            $settings[] = array(
                'title' => __('General', 'cod-guard'),
                'type'  => 'title',
                'id'    => 'drpm_general_section',
            );
            $settings[] = array(
                'title'   => __('Protect checkout', 'cod-guard'),
                'desc'    => __('Hide risky payment methods from customers with a history of refused deliveries', 'cod-guard'),
                'id'      => 'drpm_enabled',
                'type'    => 'checkbox',
                'default' => 'yes',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_general_section',
            );

            $settings[] = array(
                'title' => __('Recognise returning customers by', 'cod-guard'),
                'type'  => 'title',
                'desc'  => __('A past order counts towards a customer\'s history when it matches any of the details below. Logged-in customers are always matched by their account too. Addresses and names can also match other people (housemates, common names), so use them together with email or phone.', 'cod-guard'),
                'id'    => 'drpm_matching_section',
            );
            $matchFields = array(
                'email'            => 'yes',
                'phone'            => 'no',
                'shipping_address' => 'no',
                'billing_address'  => 'no',
                'full_name'        => 'no',
            );
            $index = 0;
            foreach ($matchFields as $key => $default) {
                $settings[] = array(
                    'title'         => 0 === $index ? __('Match on', 'cod-guard') : '',
                    'desc'          => DRPM_Risk::fieldLabel($key),
                    'id'            => DRPM_Identity::FIELDS[$key],
                    'type'          => 'checkbox',
                    'default'       => $default,
                    'checkboxgroup' => 0 === $index ? 'start' : (count($matchFields) - 1 === $index ? 'end' : ''),
                );
                $index++;
            }
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_matching_section',
            );

            $settings[] = array(
                'title' => __('When to restrict', 'cod-guard'),
                'type'  => 'title',
                'id'    => 'drpm_rule_section',
            );
            $settings[] = array(
                'type'      => 'drpm_threshold',
                'id'        => 'drpm_threshold',
                'is_option' => false,
            );
            $settings[] = array(
                'title'             => __('Look-back period', 'cod-guard'),
                'desc'              => __('Only refusals from this many months back count. 0 means all time.', 'cod-guard'),
                'id'                => 'drpm_lookback_months',
                'type'              => 'number',
                'css'               => 'width:70px;',
                'suffix'            => __('months', 'cod-guard'),
                'default'           => 24,
                'custom_attributes' => array('min' => 0, 'step' => 1),
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_rule_section',
            );

            $settings[] = array(
                'title' => __('Hide these payment methods', 'cod-guard'),
                'type'  => 'title',
                'desc'  => __('Hidden at checkout for customers who meet the rule. If hiding them would leave no way to pay, nothing is hidden.', 'cod-guard'),
                'id'    => 'drpm_methods_section',
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
                    'title'         => 1 === $current_index ? __('Payment methods', 'cod-guard') : '',
                    'desc'          => $gateway['title'],
                    'id'            => $gateway['id'],
                    'type'          => 'checkbox',
                    'default'       => 'cod' === $gateway_id ? 'yes' : 'no',
                    'checkboxgroup' => $checkboxGroupValue,
                );
            }
            $settings[] = array(
                'title'   => __('Checkout message', 'cod-guard'),
                'desc'    => __('Shown above the "Place order" button when payment methods are hidden (classic checkout). Leave it empty to hide them without a message.', 'cod-guard'),
                'id'      => 'drpm_customer_notice',
                'type'    => 'textarea',
                'css'     => 'min-width:400px;height:60px;',
                'default' => DRPM_Checkout::defaultNotice(),
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_methods_section',
            );

            $settings[] = array(
                'title' => __('Exceptions', 'cod-guard'),
                'type'  => 'title',
                'desc'  => __('Email addresses or phone numbers, one per line. You can also set this per customer on their user profile.', 'cod-guard'),
                'id'    => 'drpm_exceptions_section',
            );
            $settings[] = array(
                'title' => __('Always block', 'cod-guard'),
                'desc'  => __('Never offered the hidden payment methods.', 'cod-guard'),
                'id'    => 'drpm_blocked_list',
                'type'  => 'textarea',
                'css'   => 'min-width:400px;height:80px;',
            );
            $settings[] = array(
                'title' => __('Always trust', 'cod-guard'),
                'desc'  => __('Never restricted, whatever their history.', 'cod-guard'),
                'id'    => 'drpm_trusted_list',
                'type'  => 'textarea',
                'css'   => 'min-width:400px;height:80px;',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_exceptions_section',
            );

            $settings[] = array(
                'title' => __('Refused orders', 'cod-guard'),
                'type'  => 'title',
                'id'    => 'drpm_refused_section',
            );
            $settings[] = array(
                'title'             => __('Cost per refused parcel', 'cod-guard'),
                'desc'              => __('Shipping out and back plus handling. Used for the "Cost of refusals" figure on the dashboard.', 'cod-guard'),
                'id'                => 'drpm_refusal_cost',
                'type'              => 'number',
                'css'               => 'width:90px;',
                'suffix'            => html_entity_decode(get_woocommerce_currency_symbol()),
                'default'           => '',
                'custom_attributes' => array('min' => 0, 'step' => '0.01'),
            );
            $settings[] = array(
                'title'   => __('Restock', 'cod-guard'),
                'desc'    => __('Put items back in stock when an order is marked "Delivery refused"', 'cod-guard'),
                'id'      => 'drpm_restock_on_refusal',
                'type'    => 'checkbox',
                'default' => 'yes',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_refused_section',
            );

            $settings[] = array(
                'title' => __('Advanced', 'cod-guard'),
                'type'  => 'title',
                'id'    => 'drpm_advanced_section',
            );
            $settings[] = array(
                'type'      => 'drpm_index_status',
                'id'        => 'drpm_index_status',
                'is_option' => false,
            );
            $settings[] = array(
                'title'   => __('Uninstall', 'cod-guard'),
                'desc'    => __('Remove all plugin data when the plugin is deleted (orders marked "Delivery refused" become Cancelled)', 'cod-guard'),
                'id'      => 'drpm_delete_data_on_uninstall',
                'type'    => 'checkbox',
                'default' => 'no',
            );
            $settings[] = array(
                'type' => 'sectionend',
                'id'   => 'drpm_advanced_section',
            );

            $settings[] = array(
                'title' => ' ',
                'text'  => '<span id="checkbox_warning">' . esc_html__('Pick at least one way to recognise customers and one payment method to hide.', 'cod-guard') . '</span>',
                'id'        => 'checkbox_warning',
                'type'      => 'info',
                'is_option' => false,
            );

            return apply_filters('drpm_settings', $settings);
        }
    }

    DRPM_Settings::instance();
}
