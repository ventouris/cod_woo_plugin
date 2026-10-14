<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Risk')) {
    /**
     * Decides whether a customer is at risk of refusing delivery, based on the plugin settings.
     */
    class DRPM_Risk
    {
        const USER_OVERRIDE_META = '_drpm_override';
        const GATEWAY_OPTION_PREFIX = 'drpm_hide_gateway_';
        const OPTIONS_VERSION = '2';

        /** @var array<string,array> Per-request cache. */
        private static $cache = array();

        /**
         * Moves settings from the 2.0 option names (some unprefixed, one misspelled) to
         * the drpm_ prefix. Generic names like "threshold_value" might also belong to
         * another plugin, so those are copied but never deleted.
         */
        public static function maybeMigrateOptions()
        {
            if (self::OPTIONS_VERSION === get_option('drpm_options_version')) {
                return;
            }

            $owned = array(
                'customer_data_fields_email'                  => 'drpm_match_email',
                'customer_data_fields_email_mobile'           => 'drpm_match_phone',
                'customer_data_fields_email_shipping_address' => 'drpm_match_shipping_address',
                'customer_data_fields_email_billing_address'  => 'drpm_match_billing_address',
                'customer_data_fields_email_full_name'        => 'drpm_match_full_name',
                'delete_all_data_after_plugin_deletion'       => 'drpm_delete_data_on_uninstall',
            );
            $shared = array(
                'threshold_type'   => 'drpm_threshold_type',
                'threshold_value'  => 'drpm_threshold_value',
                'number_of_months' => 'drpm_lookback_months',
            );
            if (function_exists('WC') && WC()->payment_gateways()) {
                foreach (array_keys(WC()->payment_gateways()->payment_gateways()) as $id) {
                    $owned['dprm_payment_method_' . $id] = self::GATEWAY_OPTION_PREFIX . $id;
                }
            }

            foreach (array_merge($owned, $shared) as $old => $new) {
                $value = get_option($old, null);
                if (null !== $value && null === get_option($new, null)) {
                    update_option($new, $value);
                }
                if (isset($owned[$old])) {
                    delete_option($old);
                }
            }

            update_option('drpm_options_version', self::OPTIONS_VERSION);
        }

        public static function isEnabled()
        {
            return 'no' !== get_option('drpm_enabled', 'yes');
        }

        /**
         * Identifier keys enabled under "Customer Data Fields".
         *
         * @return string[]
         */
        public static function enabledFields()
        {
            $defaults = array('email' => 'yes');
            $fields = array();
            foreach (DRPM_Identity::FIELDS as $key => $option) {
                if ('yes' === get_option($option, $defaults[$key] ?? 'no')) {
                    $fields[] = $key;
                }
            }

            return $fields;
        }

        /**
         * Payment gateway IDs to hide from risky customers.
         *
         * @return string[]
         */
        public static function restrictedGateways()
        {
            $gateways = array();
            foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
                if ('yes' === get_option(self::GATEWAY_OPTION_PREFIX . $id, 'cod' === $id ? 'yes' : 'no')) {
                    $gateways[] = $id;
                }
            }

            return apply_filters('drpm_restricted_gateways', $gateways);
        }

        public static function months()
        {
            return max(0, (int) get_option('drpm_lookback_months', 24));
        }

        public static function settings()
        {
            return array(
                'type'       => 'percentage' === get_option('drpm_threshold_type', 'number') ? 'percentage' : 'number',
                'value'      => (float) get_option('drpm_threshold_value', 2),
                'min_orders' => max(1, (int) get_option('drpm_min_orders', 1)),
                'months'     => self::months(),
            );
        }

        /**
         * Statuses that count as "the delivery happened" for the percentage threshold.
         */
        public static function deliveredStatuses()
        {
            return apply_filters('drpm_delivered_statuses', array('completed'));
        }

        /**
         * Evaluates a customer from their identifier hashes.
         *
         * @param array<string,string|null> $hashes
         * @param int    $customerId Registered user ID (0 for guests).
         * @param string $email      Raw email, used for the trusted/blocked lists.
         * @param string $phone      Raw phone, used for the trusted/blocked lists.
         * @param int    $excludeId  Order to exclude from the history.
         * @return array{
         *   at_risk: bool, reason: string, refused: int, delivered: int, total: int,
         *   rate: float, refused_ids: int[], checked_fields: string[]
         * }
         */
        public static function evaluate(array $hashes, $customerId = 0, $email = '', $phone = '', $excludeId = 0)
        {
            $fields = self::enabledFields();
            $settings = self::settings();

            $cacheKey = md5(wp_json_encode(array($hashes, $customerId, $email, $phone, $excludeId, $fields, $settings)));
            if (isset(self::$cache[$cacheKey])) {
                return self::$cache[$cacheKey];
            }

            $active = array_intersect_key($hashes, array_flip($fields));
            $lookup = DRPM_Index::lookup($active, $customerId, $settings['months'], $excludeId);

            $refused = count($lookup['refused_ids']);
            $delivered = 0;
            foreach (self::deliveredStatuses() as $status) {
                $delivered += $lookup['counts'][$status] ?? 0;
            }
            $outcomes = $refused + $delivered;
            $rate = $outcomes > 0 ? round($refused / $outcomes * 100, 1) : 0.0;

            $result = array(
                'at_risk'        => false,
                'reason'         => 'none',
                'refused'        => $refused,
                'delivered'      => $delivered,
                'total'          => array_sum($lookup['counts']),
                'rate'           => $rate,
                'refused_ids'    => $lookup['refused_ids'],
                'checked_fields' => array_keys(array_filter($active)),
            );

            $override = self::override($customerId, $email, $phone);
            if ('allow' === $override) {
                $result['reason'] = 'trusted';
            } elseif ('restrict' === $override) {
                $result['at_risk'] = true;
                $result['reason'] = 'blocked';
            } elseif ($settings['value'] > 0 && $refused > 0) {
                if ('percentage' === $settings['type']) {
                    $result['at_risk'] = $outcomes >= $settings['min_orders'] && $rate >= $settings['value'];
                } else {
                    $result['at_risk'] = $refused >= $settings['value'];
                }
                $result['reason'] = $result['at_risk'] ? 'threshold' : 'none';
            }

            $result = apply_filters('drpm_risk_result', $result, $hashes, $customerId, $email, $phone);
            self::$cache[$cacheKey] = $result;

            return $result;
        }

        /**
         * @param WC_Order $order
         */
        public static function evaluateOrder($order, $excludeSelf = true)
        {
            return self::evaluate(
                DRPM_Identity::fromOrder($order),
                (int) $order->get_customer_id(),
                $order->get_billing_email(),
                $order->get_billing_phone(),
                $excludeSelf ? $order->get_id() : 0
            );
        }

        /**
         * @param WC_Customer $customer
         */
        public static function evaluateCustomer($customer)
        {
            return self::evaluate(
                DRPM_Identity::fromCustomer($customer),
                (int) $customer->get_id(),
                $customer->get_billing_email(),
                $customer->get_billing_phone()
            );
        }

        /**
         * Manual override from the user profile or the trusted/blocked lists.
         *
         * @return string 'allow', 'restrict' or '' (automatic).
         */
        public static function override($customerId, $email, $phone)
        {
            if ($customerId > 0) {
                $userOverride = get_user_meta($customerId, self::USER_OVERRIDE_META, true);
                if (in_array($userOverride, array('allow', 'restrict'), true)) {
                    return $userOverride;
                }
            }

            $email = DRPM_Identity::normalizeEmail($email);
            $phone = DRPM_Identity::normalizePhone($phone);

            if (self::listContains('drpm_blocked_list', $email, $phone)) {
                return 'restrict';
            }
            if (self::listContains('drpm_trusted_list', $email, $phone)) {
                return 'allow';
            }

            return '';
        }

        private static function listContains($option, $email, $phone)
        {
            $entries = preg_split('/[\r\n,]+/', (string) get_option($option, ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($entries as $entry) {
                $entry = trim($entry);
                if (false !== strpos($entry, '@')) {
                    if ('' !== $email && DRPM_Identity::normalizeEmail($entry) === $email) {
                        return true;
                    }
                } elseif ('' !== $phone && DRPM_Identity::normalizePhone($entry) === $phone) {
                    return true;
                }
            }

            return false;
        }

        public static function flushCache()
        {
            self::$cache = array();
        }

        /**
         * Human readable label for an identifier key.
         */
        public static function fieldLabel($key)
        {
            $labels = array(
                'email'            => __('Email', 'cod-guard'),
                'phone'            => __('Phone number', 'cod-guard'),
                'shipping_address' => __('Shipping address', 'cod-guard'),
                'billing_address'  => __('Billing address', 'cod-guard'),
                'full_name'        => __('Full name', 'cod-guard'),
            );

            return $labels[$key] ?? $key;
        }
    }
}
