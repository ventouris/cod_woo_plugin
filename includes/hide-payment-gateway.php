<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Checkout')) {
    /**
     * Hides the restricted payment methods for customers at risk of refusing delivery.
     *
     * Works with the classic checkout, the Checkout block (Store API) and the
     * "Pay for order" page. Because WooCommerce re-validates the chosen payment method
     * against the available gateways when placing the order, the restriction can't be
     * bypassed by tampering with the checkout form.
     */
    class DRPM_Checkout
    {
        const FLAG_META = '_drpm_payment_method_hidden';
        const SNAPSHOT_META = '_drpm_risk_snapshot';

        /** The default checkout message, untranslated (how it's stored when left unchanged). */
        const DEFAULT_NOTICE = 'Some payment methods aren\'t available for this order. Please choose one of the options below, or contact us if you think this is a mistake.';

        public static function defaultNotice()
        {
            return __('Some payment methods aren\'t available for this order. Please choose one of the options below, or contact us if you think this is a mistake.', 'cod-guard');
        }

        /**
         * An unchanged default message is shown in the visitor's language, whatever
         * language the admin had when the settings were saved.
         */
        public static function translateDefaultNotice($value)
        {
            $stored = trim((string) $value);
            if (self::DEFAULT_NOTICE === $stored) {
                return self::defaultNotice();
            }

            return $value;
        }

        /** @var bool Whether gateways were hidden during this request. */
        private static $hidden = false;

        public static function register()
        {
            add_filter('option_drpm_customer_notice', array(__CLASS__, 'translateDefaultNotice'));
            add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'filterGateways'), 100);

            // Flag new orders placed by at-risk customers (classic + block checkout).
            add_action('woocommerce_checkout_create_order', array(__CLASS__, 'flagOrder'), 10, 1);
            add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'flagOrder'), 10, 1);

            // Classic checkout: make the identifying fields available while the customer types.
            add_filter('woocommerce_billing_fields', array(__CLASS__, 'refreshOnIdentityChange'), 100);
            add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'syncCustomerFromReview'));

            // Optional explanation shown to the customer in the classic checkout.
            add_action('woocommerce_review_order_before_submit', array(__CLASS__, 'renderNotice'));
            add_action('woocommerce_pay_order_before_submit', array(__CLASS__, 'renderNotice'));
        }

        /**
         * The classic checkout only refreshes payment methods when address fields change.
         * Make email and phone refresh them too, so restrictions apply before "Place order".
         */
        public static function refreshOnIdentityChange($fields)
        {
            if (!DRPM_Risk::isEnabled()) {
                return $fields;
            }
            foreach (array('billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name') as $key) {
                if (isset($fields[$key])) {
                    $fields[$key]['class'] = array_unique(array_merge((array) ($fields[$key]['class'] ?? array()), array('update_totals_on_change')));
                }
            }

            return $fields;
        }

        /**
         * WooCommerce only copies address fields to the session when refreshing the order
         * review; copy the other identifying fields so guests can be recognised too.
         *
         * @param string $postData URL-encoded checkout form.
         */
        public static function syncCustomerFromReview($postData)
        {
            if (!DRPM_Risk::isEnabled() || !WC()->customer instanceof WC_Customer) {
                return;
            }

            parse_str((string) $postData, $data);
            $props = array(
                'billing_email'      => 'sanitize_email',
                'billing_phone'      => 'wc_sanitize_phone_number',
                'billing_first_name' => 'sanitize_text_field',
                'billing_last_name'  => 'sanitize_text_field',
                'shipping_phone'     => 'wc_sanitize_phone_number',
            );
            foreach ($props as $prop => $sanitize) {
                if (isset($data[$prop]) && is_string($data[$prop]) && is_callable(array(WC()->customer, 'set_' . $prop))) {
                    WC()->customer->{'set_' . $prop}(call_user_func($sanitize, wp_unslash($data[$prop])));
                }
            }
        }

        /**
         * @param WC_Payment_Gateway[] $gateways
         * @return WC_Payment_Gateway[]
         */
        public static function filterGateways($gateways)
        {
            if (!is_array($gateways) || !DRPM_Risk::isEnabled() || !self::isCustomerContext()) {
                return $gateways;
            }

            $risk = self::currentRisk();
            if (!$risk || !$risk['at_risk']) {
                return $gateways;
            }

            $restricted = DRPM_Risk::restrictedGateways();
            $remaining = array_diff_key($gateways, array_flip($restricted));

            // Never leave the customer without any way to pay unless explicitly allowed.
            if (!$remaining && !apply_filters('drpm_allow_empty_gateways', false, $gateways)) {
                return $gateways;
            }

            self::$hidden = count($remaining) < count($gateways);

            return $remaining;
        }

        /**
         * Only act for shoppers: checkout pages, AJAX checkout refreshes and the Store API.
         */
        private static function isCustomerContext()
        {
            if (is_admin() && !wp_doing_ajax()) {
                return false;
            }

            return function_exists('WC') && WC()->customer instanceof WC_Customer;
        }

        /**
         * Risk for whoever is paying right now.
         */
        private static function currentRisk()
        {
            $order = self::payForOrder();
            if ($order) {
                return DRPM_Risk::evaluateOrder($order);
            }

            $customer = WC()->customer;
            // Nothing entered yet: no way (or reason) to evaluate.
            if (!$customer->get_billing_email() && !$customer->get_billing_phone() && !$customer->get_id()) {
                return null;
            }

            return DRPM_Risk::evaluateCustomer($customer);
        }

        /**
         * The order on the "Pay for order" page, if that's where we are.
         *
         * @return WC_Order|null
         */
        private static function payForOrder()
        {
            if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
                return null;
            }
            $orderId = absint(get_query_var('order-pay'));
            $order = $orderId ? wc_get_order($orderId) : null;

            return $order ?: null;
        }

        /**
         * Records on the order that payment methods were restricted, with a snapshot of
         * the history that triggered it.
         *
         * @param WC_Order $order
         */
        public static function flagOrder($order)
        {
            if (!$order instanceof WC_Order || !DRPM_Risk::isEnabled()) {
                return;
            }

            $risk = DRPM_Risk::evaluateOrder($order);
            if ($risk['at_risk']) {
                $order->update_meta_data(self::FLAG_META, 'yes');
                $order->update_meta_data(self::SNAPSHOT_META, array(
                    'refused'   => $risk['refused'],
                    'delivered' => $risk['delivered'],
                    'rate'      => $risk['rate'],
                    'reason'    => $risk['reason'],
                    'time'      => time(),
                ));
            } else {
                $order->delete_meta_data(self::FLAG_META);
                $order->delete_meta_data(self::SNAPSHOT_META);
            }
        }

        public static function renderNotice()
        {
            $message = trim((string) get_option('drpm_customer_notice', self::defaultNotice()));
            if ('' === $message || !self::$hidden) {
                return;
            }

            echo '<div class="drpm-checkout-notice woocommerce-info" role="status">' . wp_kses_post(wpautop($message)) . '</div>';
        }
    }

    DRPM_Checkout::register();
}
