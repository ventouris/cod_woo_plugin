<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Privacy')) {
    /**
     * GDPR tooling: suggested privacy policy text, and integration with the WordPress
     * personal data exporter and eraser (Tools → Export/Erase Personal Data).
     */
    class DRPM_Privacy
    {
        public static function register()
        {
            add_action('admin_init', array(__CLASS__, 'addPolicyContent'));
            add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'registerExporter'));
            add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'registerEraser'));
        }

        /**
         * Suggested text for Settings → Privacy → Policy Guide.
         */
        public static function addPolicyContent()
        {
            if (!function_exists('wp_add_privacy_policy_content')) {
                return;
            }

            $months = DRPM_Risk::months();
            $window = $months > 0
                /* translators: %d: months */
                ? sprintf(__('the last %d months', 'cod-guard'), $months)
                : __('the full order history', 'cod-guard');

            $content = '<p class="privacy-policy-tutorial">' . esc_html__('This sample text explains the delivery refusal checks. Adjust it to your store and legal basis; it is not legal advice.', 'cod-guard') . '</p>'
                . '<p><strong class="privacy-policy-tutorial">' . esc_html__('Suggested text:', 'cod-guard') . '</strong> '
                . esc_html(sprintf(
                    /* translators: %s: retention window */
                    __('To prevent losses from parcels that are refused on delivery, we check whether previous orders placed with the same contact or delivery details were refused, looking at %s. If the number or share of refused deliveries exceeds a limit, some payment methods (such as cash on delivery) may not be offered; you can still order using the other payment methods. We process this information on the basis of our legitimate interest in preventing fraud and non-payment (Article 6(1)(f) GDPR). Your details are stored only as irreversible keyed fingerprints (hashes) and are not shared with third parties. You can object to this processing, or ask us to review a restriction, by contacting us.', 'cod-guard'),
                    $window
                ))
                . '</p>';

            wp_add_privacy_policy_content(__('COD Guard', 'cod-guard'), wp_kses_post($content));
        }

        public static function registerExporter($exporters)
        {
            $exporters['delivery-refusal-payment-manager'] = array(
                'exporter_friendly_name' => __('Delivery refusal history', 'cod-guard'),
                'callback'               => array(__CLASS__, 'export'),
            );

            return $exporters;
        }

        public static function registerEraser($erasers)
        {
            $erasers['delivery-refusal-payment-manager'] = array(
                'eraser_friendly_name' => __('Delivery refusal history', 'cod-guard'),
                'callback'             => array(__CLASS__, 'erase'),
            );

            return $erasers;
        }

        /**
         * Exports the order history used for risk checks, the current verdict and any manual overrides.
         */
        public static function export($email, $page = 1)
        {
            $user = get_user_by('email', $email);
            $customerId = $user ? (int) $user->ID : 0;
            $items = array();

            foreach (self::rows($email, $customerId) as $row) {
                $items[] = array(
                    'group_id'    => 'drpm-history',
                    'group_label' => __('Delivery refusal history', 'cod-guard'),
                    'item_id'     => 'drpm-order-' . $row->order_id,
                    'data'        => array(
                        array('name' => __('Order', 'cod-guard'), 'value' => '#' . $row->order_id),
                        array('name' => __('Status', 'cod-guard'), 'value' => wc_get_order_status_name($row->status)),
                        array('name' => __('Date', 'cod-guard'), 'value' => $row->date_created_gmt . ' UTC'),
                    ),
                );
            }

            $risk = DRPM_Risk::evaluate(array('email' => DRPM_Identity::hashValue('email', $email)), $customerId, $email);
            $override = $customerId ? get_user_meta($customerId, DRPM_Risk::USER_OVERRIDE_META, true) : '';
            $items[] = array(
                'group_id'    => 'drpm-status',
                'group_label' => __('Payment method restriction', 'cod-guard'),
                'item_id'     => 'drpm-status',
                'data'        => array(
                    array('name' => __('Refused deliveries counted', 'cod-guard'), 'value' => (string) $risk['refused']),
                    array('name' => __('Completed deliveries counted', 'cod-guard'), 'value' => (string) $risk['delivered']),
                    array('name' => __('Payment methods currently restricted', 'cod-guard'), 'value' => $risk['at_risk'] ? __('Yes', 'cod-guard') : __('No', 'cod-guard')),
                    array('name' => __('Exception on customer profile', 'cod-guard'), 'value' => array('allow' => __('Always trust', 'cod-guard'), 'restrict' => __('Always block', 'cod-guard'))[$override] ?? __('None', 'cod-guard')),
                    array('name' => __('On the "Always block" list', 'cod-guard'), 'value' => self::listHas('drpm_blocked_list', $email) ? __('Yes', 'cod-guard') : __('No', 'cod-guard')),
                    array('name' => __('On the "Always trust" list', 'cod-guard'), 'value' => self::listHas('drpm_trusted_list', $email) ? __('Yes', 'cod-guard') : __('No', 'cod-guard')),
                ),
            );

            return array('data' => $items, 'done' => true);
        }

        /**
         * Erases the person's refusal data — but only if the store erases order data on request
         * (WooCommerce → Settings → Accounts & Privacy). Otherwise the data is retained, like the
         * orders themselves, for fraud prevention, and the request reports it as retained.
         */
        public static function erase($email, $page = 1)
        {
            $user = get_user_by('email', $email);
            $customerId = $user ? (int) $user->ID : 0;
            $rows = self::rows($email, $customerId);
            $override = $customerId ? get_user_meta($customerId, DRPM_Risk::USER_OVERRIDE_META, true) : '';
            $listed = self::listHas('drpm_blocked_list', $email) || self::listHas('drpm_trusted_list', $email);
            $hasData = $rows || $override || $listed;

            $removeOrderData = 'yes' === get_option('woocommerce_erasure_request_removes_order_data', 'no');
            if (!apply_filters('drpm_privacy_erase_data', $removeOrderData, $email)) {
                return array(
                    'items_removed'  => false,
                    'items_retained' => (bool) $hasData,
                    'messages'       => $hasData ? array(__('Delivery refusal history was retained for fraud prevention, in line with the store\'s order data retention settings.', 'cod-guard')) : array(),
                    'done'           => true,
                );
            }

            foreach ($rows as $row) {
                DRPM_Index::removeOrder($row->order_id);
            }
            if ($override) {
                delete_user_meta($customerId, DRPM_Risk::USER_OVERRIDE_META);
            }
            self::removeFromList('drpm_blocked_list', $email);
            self::removeFromList('drpm_trusted_list', $email);

            return array(
                'items_removed'  => (bool) $hasData,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        /**
         * Index rows matching the email, or the account with that email.
         */
        private static function rows($email, $customerId)
        {
            global $wpdb;

            $hash = DRPM_Identity::hashValue('email', $email);
            if (!$hash && !$customerId) {
                return array();
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (array) $wpdb->get_results($wpdb->prepare(
                'SELECT order_id, status, date_created_gmt FROM ' . DRPM_Index::tableName() . ' WHERE email_hash = %s OR (customer_id > 0 AND customer_id = %d) ORDER BY order_id',
                (string) $hash,
                $customerId
            ));
        }

        private static function listHas($option, $email)
        {
            $email = DRPM_Identity::normalizeEmail($email);
            foreach (self::listEntries($option) as $entry) {
                if ('' !== $email && DRPM_Identity::normalizeEmail($entry) === $email) {
                    return true;
                }
            }

            return false;
        }

        private static function removeFromList($option, $email)
        {
            $email = DRPM_Identity::normalizeEmail($email);
            $entries = self::listEntries($option);
            $kept = array_filter($entries, function ($entry) use ($email) {
                return false === strpos($entry, '@') || DRPM_Identity::normalizeEmail($entry) !== $email;
            });
            if (count($kept) !== count($entries)) {
                update_option($option, implode("\n", $kept));
            }
        }

        private static function listEntries($option)
        {
            return array_map('trim', preg_split('/[\r\n,]+/', (string) get_option($option, ''), -1, PREG_SPLIT_NO_EMPTY));
        }
    }

    DRPM_Privacy::register();
}
