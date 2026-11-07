<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Blocked')) {
    /**
     * Everyone COD Guard currently hides payment methods from: manually (profile exception or
     * the "Always block" list) or automatically (their refusal history crosses the threshold).
     */
    class DRPM_Blocked
    {
        const TYPES = array('all', 'automatic', 'manual');

        /** Most recent customers with a refusal that are checked for the automatic rule. */
        const MAX_CANDIDATES = 2000;

        /**
         * @return array<int,array{
         *   type: string, source: string, label: string, email: string, phone: string,
         *   refused: int, delivered: int, rate: float, last_refusal: string, last_refusal_ts: int,
         *   url: string, guest: bool
         * }>
         */
        public static function all()
        {
            $rows = array();
            $seen = array();

            foreach (self::manualProfiles() as $row) {
                self::add($rows, $seen, $row);
            }
            foreach (self::manualList() as $row) {
                self::add($rows, $seen, $row);
            }
            foreach (self::automatic() as $row) {
                self::add($rows, $seen, $row);
            }

            usort($rows, function ($a, $b) {
                return array($b['last_refusal_ts'], $b['refused']) <=> array($a['last_refusal_ts'], $a['refused']);
            });

            return $rows;
        }

        /**
         * @return array<string,int> Count per type, including 'all'.
         */
        public static function counts(array $rows)
        {
            $counts = array('all' => count($rows), 'automatic' => 0, 'manual' => 0);
            foreach ($rows as $row) {
                $counts[$row['type']]++;
            }

            return $counts;
        }

        /**
         * One row per person: the first source wins (manual sources are added first).
         */
        private static function add(array &$rows, array &$seen, array $row)
        {
            $keys = array_filter(array(
                DRPM_Identity::normalizeEmail($row['email']) ? 'e:' . DRPM_Identity::normalizeEmail($row['email']) : '',
                DRPM_Identity::normalizePhone($row['phone']) ? 'p:' . DRPM_Identity::normalizePhone($row['phone']) : '',
            ));
            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    return;
                }
            }
            foreach ($keys as $key) {
                $seen[$key] = true;
            }
            $rows[] = $row;
        }

        /**
         * Registered customers set to "Always block" on their profile.
         */
        private static function manualProfiles()
        {
            $users = get_users(array(
                'meta_key'   => DRPM_Risk::USER_OVERRIDE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value' => 'restrict', // phpcs:ignore WordPress.DB.SlowDBQuery
                'fields'     => 'ID',
                'number'     => 500,
            ));

            $rows = array();
            foreach ($users as $userId) {
                $customer = new WC_Customer((int) $userId);
                $risk = DRPM_Risk::evaluateCustomer($customer);
                $name = trim($customer->get_first_name() . ' ' . $customer->get_last_name());
                if ('' === $name) {
                    $user = get_userdata((int) $userId);
                    $name = $user ? $user->display_name : '';
                }
                $rows[] = self::row('manual', 'profile', $name, $customer->get_billing_email() ?: $customer->get_email(), $customer->get_billing_phone(), $risk, self::links((int) $userId, ''));
            }

            return $rows;
        }

        /**
         * Entries of the "Always block" list in Settings (emails or phones, guests included).
         */
        private static function manualList()
        {
            $entries = preg_split('/[\r\n,]+/', (string) get_option('drpm_blocked_list', ''), -1, PREG_SPLIT_NO_EMPTY);
            $rows = array();
            foreach ($entries as $entry) {
                $entry = trim($entry);
                if ('' === $entry) {
                    continue;
                }
                $isEmail = false !== strpos($entry, '@');
                $order = self::latestOrderFor($isEmail ? 'email' : 'phone', $entry);

                if ($order) {
                    $risk = DRPM_Risk::evaluateOrder($order, false);
                    $label = trim($order->get_formatted_billing_full_name());
                    $email = $order->get_billing_email();
                    $phone = $order->get_billing_phone();
                } else {
                    $risk = null;
                    $label = '';
                    $email = $isEmail ? $entry : '';
                    $phone = $isEmail ? '' : $entry;
                }
                $customerId = $order ? (int) $order->get_customer_id() : 0;

                $rows[] = self::row('manual', 'list', $label, $email, $phone, $risk, $order
                    ? self::links($customerId, $email ?: $phone)
                    : array('url' => DRPM_Settings::url('drpm_blocked_list'), 'guest' => true));
            }

            return $rows;
        }

        /**
         * Customers whose refusal history crosses the threshold right now.
         */
        private static function automatic()
        {
            global $wpdb;

            $months = DRPM_Risk::months();
            $where = 'status = %s AND email_hash IS NOT NULL';
            $args = array(DRPM_Order_Status::STATUS);
            if ($months > 0) {
                $where .= ' AND date_created_gmt >= %s';
                $args[] = gmdate('Y-m-d H:i:s', strtotime("-{$months} months"));
            }
            $args[] = self::MAX_CANDIDATES;

            // Candidates: anyone with at least one refusal in the look-back window.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $hashes = $wpdb->get_col($wpdb->prepare(
                'SELECT email_hash FROM ' . DRPM_Index::tableName() . " WHERE {$where}
                GROUP BY email_hash ORDER BY MAX(date_created_gmt) DESC LIMIT %d",
                $args
            ));

            $rows = array();
            foreach ($hashes as $hash) {
                $orderId = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT MAX(order_id) FROM ' . DRPM_Index::tableName() . ' WHERE email_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $hash
                ));
                $order = $orderId ? wc_get_order($orderId) : null;
                if (!$order) {
                    continue;
                }
                $risk = DRPM_Risk::evaluateOrder($order, false);
                if ('threshold' !== $risk['reason']) {
                    continue;
                }
                $customerId = (int) $order->get_customer_id();
                $rows[] = self::row('automatic', 'threshold', trim($order->get_formatted_billing_full_name()), $order->get_billing_email(), $order->get_billing_phone(), $risk, self::links($customerId, $order->get_billing_email() ?: $order->get_billing_phone()));
            }

            return $rows;
        }

        /**
         * Where the customer's name links to: their user profile (where exceptions are set) when
         * they have an account, otherwise the orders list searched for their email or phone.
         */
        private static function links($customerId, $search)
        {
            if ($customerId > 0 && get_userdata($customerId)) {
                return array('url' => get_edit_user_link($customerId) . '#drpm-override', 'guest' => false);
            }
            $orders = DeliveryRefusalPaymentManager::isHPOSEnabled()
                ? admin_url('admin.php?page=wc-orders')
                : admin_url('edit.php?post_type=shop_order');

            return array('url' => add_query_arg('s', rawurlencode((string) $search), $orders), 'guest' => true);
        }

        /**
         * @return WC_Order|null
         */
        private static function latestOrderFor($field, $value)
        {
            global $wpdb;

            $hash = DRPM_Identity::hashValue($field, $value);
            if (!$hash) {
                return null;
            }
            $column = 'phone' === $field ? 'phone_hash' : 'email_hash';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orderId = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(order_id) FROM ' . DRPM_Index::tableName() . " WHERE {$column} = %s", $hash));

            return $orderId ? (wc_get_order($orderId) ?: null) : null;
        }

        private static function row($type, $source, $label, $email, $phone, $risk, array $links)
        {
            $lastTs = 0;
            if ($risk && $risk['refused_ids']) {
                $last = wc_get_order(max($risk['refused_ids']));
                $created = $last ? $last->get_date_created() : null;
                $lastTs = $created ? $created->getTimestamp() : 0;
            }

            return array_merge(array(
                'type'            => $type,
                'source'          => $source,
                'label'           => $label,
                'email'           => (string) $email,
                'phone'           => (string) $phone,
                'refused'         => $risk ? (int) $risk['refused'] : 0,
                'delivered'       => $risk ? (int) $risk['delivered'] : 0,
                'rate'            => $risk ? (float) $risk['rate'] : 0.0,
                'last_refusal'    => $lastTs ? wp_date('j M Y', $lastTs) : '',
                'last_refusal_ts' => $lastTs,
                'url'             => '',
                'guest'           => true,
            ), $links);
        }
    }
}
