<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Index')) {
    /**
     * Maintains a compact lookup table of orders keyed by hashed customer identifiers.
     *
     * Querying this table is a single indexed lookup, so risk checks stay fast at
     * checkout regardless of store size, and work identically with HPOS and legacy storage.
     */
    class DRPM_Index
    {
        const DB_VERSION = '1';
        const BATCH_SIZE = 200;

        public static function tableName()
        {
            global $wpdb;
            return $wpdb->prefix . 'drpm_order_index';
        }

        public static function register()
        {
            add_action('woocommerce_new_order', array(__CLASS__, 'indexOrder'), 20, 1);
            add_action('woocommerce_update_order', array(__CLASS__, 'indexOrder'), 20, 1);
            add_action('woocommerce_delete_order', array(__CLASS__, 'removeOrder'));
            add_action('woocommerce_trash_order', array(__CLASS__, 'removeOrder'));
            add_action('woocommerce_untrash_order', array(__CLASS__, 'indexOrder'));
            // Legacy (posts) storage trash / delete.
            add_action('trashed_post', array(__CLASS__, 'onPostRemoved'));
            add_action('deleted_post', array(__CLASS__, 'onPostRemoved'));
            add_action('untrashed_post', array(__CLASS__, 'onPostRestored'));

            add_action('drpm_backfill_batch', array(__CLASS__, 'runBackfillBatch'));
            add_action('admin_init', array(__CLASS__, 'maybeScheduleBackfill'));
        }

        /**
         * Creates/updates the index table.
         */
        public static function install()
        {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            if (!get_option('drpm_db_version')) {
                self::removeLegacyFlags();
            }

            $table = self::tableName();
            $charset = $wpdb->get_charset_collate();

            dbDelta("CREATE TABLE {$table} (
  order_id bigint(20) unsigned NOT NULL,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(40) NOT NULL DEFAULT '',
  date_created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  email_hash char(32) DEFAULT NULL,
  phone_hash char(32) DEFAULT NULL,
  shipping_address_hash char(32) DEFAULT NULL,
  billing_address_hash char(32) DEFAULT NULL,
  full_name_hash char(32) DEFAULT NULL,
  PRIMARY KEY  (order_id),
  KEY customer_id (customer_id),
  KEY email_hash (email_hash),
  KEY phone_hash (phone_hash),
  KEY shipping_address_hash (shipping_address_hash),
  KEY billing_address_hash (billing_address_hash),
  KEY full_name_hash (full_name_hash)
) {$charset};");

            update_option('drpm_db_version', self::DB_VERSION, false);
        }

        /**
         * Installs the table after plugin updates (activation hooks don't run on update).
         */
        public static function maybeUpgrade()
        {
            self::register();

            if (get_option('drpm_db_version') !== self::DB_VERSION) {
                self::install();
                update_option('drpm_needs_backfill', 'yes', false);
            }
        }

        /**
         * Version 1.x flagged *every* new order with `_drpm_payment_method_hidden = 1`,
         * which made the flag meaningless. Drop those values; 2.x stores 'yes'.
         */
        private static function removeLegacyFlags()
        {
            global $wpdb;

            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", '_drpm_payment_method_hidden'));
            $hposMeta = $wpdb->prefix . 'wc_orders_meta';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hposMeta)) === $hposMeta) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$hposMeta} WHERE meta_key = %s AND meta_value = '1'", '_drpm_payment_method_hidden'));
            }
        }

        /**
         * Inserts or refreshes an order's row.
         *
         * @param int|WC_Order $order
         */
        public static function indexOrder($order)
        {
            global $wpdb;

            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order);
            }
            // Skips refunds and other order types.
            if (!$order || 'shop_order' !== $order->get_type()) {
                return;
            }
            if ('trash' === $order->get_status()) {
                self::removeOrder($order->get_id());
                return;
            }

            $hashes = DRPM_Identity::fromOrder($order);
            $created = $order->get_date_created();

            $wpdb->replace(
                self::tableName(),
                array(
                    'order_id'              => $order->get_id(),
                    'customer_id'           => (int) $order->get_customer_id(),
                    'status'                => $order->get_status(),
                    'date_created_gmt'      => $created ? gmdate('Y-m-d H:i:s', $created->getTimestamp()) : current_time('mysql', true),
                    'email_hash'            => $hashes['email'],
                    'phone_hash'            => $hashes['phone'],
                    'shipping_address_hash' => $hashes['shipping_address'],
                    'billing_address_hash'  => $hashes['billing_address'],
                    'full_name_hash'        => $hashes['full_name'],
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        public static function removeOrder($orderId)
        {
            global $wpdb;
            $wpdb->delete(self::tableName(), array('order_id' => (int) $orderId), array('%d'));
        }

        public static function onPostRemoved($postId)
        {
            if ('shop_order' === get_post_type($postId)) {
                self::removeOrder($postId);
            }
        }

        public static function onPostRestored($postId)
        {
            if ('shop_order' === get_post_type($postId)) {
                self::indexOrder($postId);
            }
        }

        /**
         * Counts orders per status that match any of the given identifiers.
         *
         * @param array<string,string|null> $hashes     Identifier hashes (only enabled ones should be passed).
         * @param int                       $customerId Registered customer ID (0 for guests).
         * @param int                       $months     Look-back window; 0 = all time.
         * @param int                       $excludeId  Order to leave out (e.g. the one being viewed).
         * @return array{counts: array<string,int>, refused_ids: int[]}
         */
        public static function lookup(array $hashes, $customerId = 0, $months = 0, $excludeId = 0)
        {
            global $wpdb;

            $where = array();
            $args = array();
            foreach ($hashes as $key => $hash) {
                if ($hash && isset(DRPM_Identity::FIELDS[$key])) {
                    $where[] = "{$key}_hash = %s";
                    $args[] = $hash;
                }
            }
            if ($customerId > 0) {
                $where[] = 'customer_id = %d';
                $args[] = (int) $customerId;
            }

            $result = array('counts' => array(), 'refused_ids' => array());
            if (!$where) {
                return $result;
            }

            $sql = 'SELECT order_id, status FROM ' . self::tableName() . ' WHERE (' . implode(' OR ', $where) . ')';
            if ($months > 0) {
                $sql .= ' AND date_created_gmt >= %s';
                $args[] = gmdate('Y-m-d H:i:s', strtotime("-{$months} months"));
            }
            if ($excludeId > 0) {
                $sql .= ' AND order_id <> %d';
                $args[] = (int) $excludeId;
            }
            $sql .= ' ORDER BY order_id DESC';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

            foreach ((array) $rows as $row) {
                $result['counts'][$row->status] = ($result['counts'][$row->status] ?? 0) + 1;
                if (DRPM_Order_Status::STATUS === $row->status) {
                    $result['refused_ids'][] = (int) $row->order_id;
                }
            }

            return $result;
        }

        /**
         * Store-wide totals for the settings dashboard.
         */
        public static function storeStats($months = 0)
        {
            global $wpdb;

            $sql = 'SELECT status, COUNT(*) AS total FROM ' . self::tableName();
            $args = array();
            if ($months > 0) {
                $sql .= ' WHERE date_created_gmt >= %s';
                $args[] = gmdate('Y-m-d H:i:s', strtotime("-{$months} months"));
            }
            $sql .= ' GROUP BY status';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql);

            $counts = array();
            foreach ((array) $rows as $row) {
                $counts[$row->status] = (int) $row->total;
            }

            return $counts;
        }

        /* ------------------------------------------------------------------
         * Backfill of orders that existed before the plugin was installed.
         * ------------------------------------------------------------------ */

        public static function maybeScheduleBackfill()
        {
            if ('yes' === get_option('drpm_needs_backfill')) {
                self::startBackfill();
            }
        }

        /**
         * Starts a background (Action Scheduler) rebuild of the whole index.
         */
        public static function startBackfill()
        {
            if (!function_exists('as_enqueue_async_action')) {
                return false;
            }

            as_unschedule_all_actions('drpm_backfill_batch');
            update_option('drpm_backfill_state', array(
                'status'    => 'running',
                'processed' => 0,
                'started'   => time(),
            ), false);
            delete_option('drpm_needs_backfill');
            as_enqueue_async_action('drpm_backfill_batch', array('page' => 1), 'drpm');

            return true;
        }

        /**
         * Indexes one page of orders and queues the next one.
         */
        public static function runBackfillBatch($page = 1)
        {
            $page = max(1, (int) $page);
            $processed = self::indexPage($page);

            $state = get_option('drpm_backfill_state', array());
            $state['processed'] = (int) ($state['processed'] ?? 0) + $processed;

            if ($processed >= self::BATCH_SIZE) {
                $state['status'] = 'running';
                as_enqueue_async_action('drpm_backfill_batch', array('page' => $page + 1), 'drpm');
            } else {
                $state['status'] = 'complete';
                $state['finished'] = time();
            }

            update_option('drpm_backfill_state', $state, false);
        }

        /**
         * Indexes a page of orders synchronously.
         *
         * @return int Number of orders processed.
         */
        public static function indexPage($page)
        {
            $ids = wc_get_orders(array(
                'type'    => 'shop_order',
                'status'  => array_keys(wc_get_order_statuses()),
                'limit'   => self::BATCH_SIZE,
                'paged'   => (int) $page,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'return'  => 'ids',
            ));

            foreach ($ids as $id) {
                self::indexOrder($id);
            }

            return count($ids);
        }
    }
}
