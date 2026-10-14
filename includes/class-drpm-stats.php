<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Stats')) {
    /**
     * Aggregate queries for the refusal dashboard. Everything reads the order index,
     * so it's fast and identical for HPOS and legacy storage.
     */
    class DRPM_Stats
    {
        const RANGES = array('30d', '90d', '12m', 'all');

        /**
         * Date window for a range, as GMT strings. 'all' has no start.
         *
         * @return array{from: ?string, to: string, prev_from: ?string, granularity: string}
         */
        public static function window($range)
        {
            $now = time();
            switch ($range) {
                case '30d':
                    $from = $now - 30 * DAY_IN_SECONDS;
                    $prev = $from - 30 * DAY_IN_SECONDS;
                    $granularity = 'day';
                    break;
                case '90d':
                    $from = $now - 90 * DAY_IN_SECONDS;
                    $prev = $from - 90 * DAY_IN_SECONDS;
                    $granularity = 'week';
                    break;
                case 'all':
                    $from = null;
                    $prev = null;
                    $granularity = 'month';
                    break;
                default:
                    $from = strtotime('-12 months', $now);
                    $prev = strtotime('-12 months', $from);
                    $granularity = 'month';
            }

            return array(
                'from'        => $from ? gmdate('Y-m-d H:i:s', $from) : null,
                'to'          => gmdate('Y-m-d H:i:s', $now),
                'prev_from'   => $prev ? gmdate('Y-m-d H:i:s', $prev) : null,
                'granularity' => $granularity,
            );
        }

        /**
         * Headline totals between two GMT dates.
         */
        public static function totals($from, $to)
        {
            global $wpdb;

            list($where, $args) = self::dateWhere($from, $to);
            $refused = DRPM_Order_Status::STATUS;
            $delivered = self::deliveredIn();

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    SUM(status = %s) AS refused,
                    SUM(status IN ({$delivered})) AS delivered,
                    SUM(CASE WHEN status = %s THEN order_total ELSE 0 END) AS refused_value,
                    SUM(restricted) AS restricted,
                    SUM(restricted = 1 AND status IN ({$delivered})) AS restricted_delivered
                FROM " . DRPM_Index::tableName() . " WHERE {$where}",
                array_merge(array($refused, $refused), $args)
            ));

            $refusedCount = (int) ($row->refused ?? 0);
            $deliveredCount = (int) ($row->delivered ?? 0);

            return array(
                'refused'              => $refusedCount,
                'delivered'            => $deliveredCount,
                'rate'                 => self::rate($refusedCount, $deliveredCount),
                'refused_value'        => (float) ($row->refused_value ?? 0),
                'cost'                 => $refusedCount * self::costPerRefusal(),
                'restricted'           => (int) ($row->restricted ?? 0),
                'restricted_delivered' => (int) ($row->restricted_delivered ?? 0),
            );
        }

        /**
         * Refused / delivered counts per day, week or month, with empty buckets filled in.
         */
        public static function timeline($from, $to, $granularity)
        {
            global $wpdb;

            list($where, $args) = self::dateWhere($from, $to);
            $offset = (int) round((float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
            $local = "DATE_ADD(date_created_gmt, INTERVAL {$offset} SECOND)";
            switch ($granularity) {
                case 'day':
                    $bucket = "DATE({$local})";
                    break;
                case 'week':
                    $bucket = "DATE_SUB(DATE({$local}), INTERVAL WEEKDAY({$local}) DAY)";
                    break;
                default:
                    $bucket = "DATE_FORMAT({$local}, '%%Y-%%m-01')";
            }
            $delivered = self::deliveredIn();

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$bucket} AS bucket, SUM(status = %s) AS refused, SUM(status IN ({$delivered})) AS delivered
                FROM " . DRPM_Index::tableName() . " WHERE {$where} GROUP BY bucket ORDER BY bucket",
                array_merge(array(DRPM_Order_Status::STATUS), $args)
            ));

            $byBucket = array();
            foreach ((array) $rows as $row) {
                $byBucket[$row->bucket] = $row;
            }
            if (!$byBucket && !$from) {
                return array();
            }

            // Walk every bucket from start to now so gaps show as zero.
            $tz = wp_timezone();
            $start = $from
                ? (new DateTimeImmutable($from, new DateTimeZone('UTC')))->setTimezone($tz)
                : new DateTimeImmutable(array_key_first($byBucket), $tz);
            $end = new DateTimeImmutable('now', $tz);
            if ('week' === $granularity) {
                $start = $start->modify('monday this week');
            } elseif ('month' === $granularity) {
                $start = $start->modify('first day of this month');
            }
            $step = array('day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month')[$granularity] ?? '+1 month';
            $labelFormat = 'month' === $granularity ? 'M Y' : 'j M';

            $series = array();
            for ($cursor = $start->setTime(0, 0); $cursor <= $end; $cursor = $cursor->modify($step)) {
                $key = $cursor->format('Y-m-d');
                $refused = (int) ($byBucket[$key]->refused ?? 0);
                $deliveredCount = (int) ($byBucket[$key]->delivered ?? 0);
                $series[] = array(
                    'key'       => $key,
                    'label'     => wp_date($labelFormat, $cursor->getTimestamp()),
                    'refused'   => $refused,
                    'delivered' => $deliveredCount,
                    // No delivery outcomes = no rate (a gap in the chart), not 0%.
                    'rate'      => ($refused + $deliveredCount) > 0 ? self::rate($refused, $deliveredCount) : null,
                );
            }

            return $series;
        }

        /**
         * Refusal rate per payment method.
         */
        public static function byPaymentMethod($from, $to)
        {
            global $wpdb;

            list($where, $args) = self::dateWhere($from, $to);
            $delivered = self::deliveredIn();

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT payment_method, SUM(status = %s) AS refused, SUM(status IN ({$delivered})) AS delivered
                FROM " . DRPM_Index::tableName() . " WHERE {$where} GROUP BY payment_method HAVING refused + delivered > 0
                ORDER BY refused DESC, delivered DESC LIMIT 8",
                array_merge(array(DRPM_Order_Status::STATUS), $args)
            ));

            $gateways = WC()->payment_gateways()->payment_gateways();
            $result = array();
            foreach ((array) $rows as $row) {
                $id = (string) $row->payment_method;
                $label = isset($gateways[$id]) ? ($gateways[$id]->get_method_title() ?: $gateways[$id]->get_title()) : ($id ?: __('None / manual', 'cod-guard'));
                $result[] = self::rateRow(wp_strip_all_tags($label), (int) $row->refused, (int) $row->delivered);
            }

            return $result;
        }

        /**
         * Regions (shipping state, or country) with the most refusals.
         */
        public static function byRegion($from, $to, $limit = 8)
        {
            global $wpdb;

            list($where, $args) = self::dateWhere($from, $to);
            $delivered = self::deliveredIn();

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT shipping_country, shipping_state, SUM(status = %s) AS refused, SUM(status IN ({$delivered})) AS delivered
                FROM " . DRPM_Index::tableName() . " WHERE {$where} AND shipping_country <> ''
                GROUP BY shipping_country, shipping_state HAVING refused > 0 ORDER BY refused DESC LIMIT %d",
                array_merge(array(DRPM_Order_Status::STATUS), $args, array((int) $limit))
            ));

            $countries = WC()->countries;
            $result = array();
            foreach ((array) $rows as $row) {
                $states = $countries->get_states($row->shipping_country);
                $label = $row->shipping_state && isset($states[$row->shipping_state])
                    ? $states[$row->shipping_state]
                    : ($row->shipping_state ?: ($countries->countries[$row->shipping_country] ?? $row->shipping_country));
                $result[] = self::rateRow(html_entity_decode(wp_strip_all_tags($label)), (int) $row->refused, (int) $row->delivered);
            }

            return $result;
        }

        /**
         * Products that appear most in refused orders. Uses WooCommerce Analytics' product lookup table.
         *
         * @return array|null Null when the lookup table isn't available.
         */
        public static function topProducts($from, $to, $limit = 10)
        {
            global $wpdb;

            $lookup = $wpdb->prefix . 'wc_order_product_lookup';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
                return null;
            }

            list($where, $args) = self::dateWhere($from, $to, 'i.');
            $delivered = self::deliveredIn('i.');

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT l.product_id,
                    COUNT(DISTINCT CASE WHEN i.status = %s THEN i.order_id END) AS refused,
                    COUNT(DISTINCT CASE WHEN i.status IN ({$delivered}) THEN i.order_id END) AS delivered
                FROM " . DRPM_Index::tableName() . " i INNER JOIN {$lookup} l ON l.order_id = i.order_id
                WHERE {$where} GROUP BY l.product_id HAVING refused > 0 ORDER BY refused DESC LIMIT %d",
                array_merge(array(DRPM_Order_Status::STATUS), $args, array((int) $limit))
            ));

            $result = array();
            foreach ((array) $rows as $row) {
                $product = wc_get_product($row->product_id);
                $item = self::rateRow(
                    $product ? $product->get_name() : sprintf(__('Product #%d', 'cod-guard'), $row->product_id),
                    (int) $row->refused,
                    (int) $row->delivered
                );
                $item['url'] = $product ? get_edit_post_link($product->get_parent_id() ?: $product->get_id(), 'raw') : '';
                $result[] = $item;
            }

            return $result;
        }

        /**
         * Customers (by email) with two or more refusals in the window.
         */
        public static function repeatRefusers($from, $to, $limit = 10)
        {
            global $wpdb;

            list($where, $args) = self::dateWhere($from, $to);
            $delivered = self::deliveredIn();
            $refused = DRPM_Order_Status::STATUS;

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT email_hash, SUM(status = %s) AS refused, SUM(status IN ({$delivered})) AS delivered,
                    MAX(order_id) AS latest_order, MAX(CASE WHEN status = %s THEN date_created_gmt END) AS last_refusal
                FROM " . DRPM_Index::tableName() . " WHERE {$where} AND email_hash IS NOT NULL
                GROUP BY email_hash HAVING refused >= 2 ORDER BY refused DESC, last_refusal DESC LIMIT %d",
                array_merge(array($refused, $refused), $args, array((int) $limit))
            ));

            $result = array();
            foreach ((array) $rows as $row) {
                $order = wc_get_order($row->latest_order);
                if (!$order) {
                    continue;
                }
                $risk = DRPM_Risk::evaluateOrder($order, false);
                $result[] = array_merge(self::rateRow(
                    trim($order->get_formatted_billing_full_name()) ?: __('Guest', 'cod-guard'),
                    (int) $row->refused,
                    (int) $row->delivered
                ), array(
                    'email'        => $order->get_billing_email(),
                    'url'          => $order->get_edit_order_url(),
                    'order_number' => $order->get_order_number(),
                    'last_refusal' => $row->last_refusal ? wp_date(get_option('date_format'), strtotime($row->last_refusal . ' UTC')) : '',
                    'restricted'   => (bool) $risk['at_risk'],
                ));
            }

            return $result;
        }

        public static function costPerRefusal()
        {
            return max(0, (float) get_option('drpm_refusal_cost', 0));
        }

        private static function rateRow($label, $refused, $delivered)
        {
            return array(
                'label'     => $label,
                'refused'   => $refused,
                'delivered' => $delivered,
                'rate'      => self::rate($refused, $delivered),
            );
        }

        private static function rate($refused, $delivered)
        {
            $total = $refused + $delivered;
            return $total > 0 ? round($refused / $total * 100, 1) : 0.0;
        }

        /**
         * @return array{0: string, 1: array}
         */
        private static function dateWhere($from, $to, $alias = '')
        {
            $where = "{$alias}date_created_gmt <= %s";
            $args = array($to);
            if ($from) {
                $where .= " AND {$alias}date_created_gmt >= %s";
                $args[] = $from;
            }

            return array($where, $args);
        }

        /**
         * Delivered statuses as a quoted SQL list (values come from a filter, so escape them).
         */
        private static function deliveredIn($alias = '')
        {
            $quoted = array_map(function ($status) {
                return "'" . esc_sql(sanitize_key($status)) . "'";
            }, DRPM_Risk::deliveredStatuses());

            return $quoted ? implode(',', $quoted) : "''";
        }
    }
}
