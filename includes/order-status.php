<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Order_Status')) {
    /**
     * Registers the "Refused delivery" order status and the admin tools to apply it.
     */
    class DRPM_Order_Status
    {
        /** Status slug without the "wc-" prefix. */
        const STATUS = 'refused-delivery';
        const BULK_ACTION = 'mark_refused-delivery';

        public static function register()
        {
            add_filter('woocommerce_register_shop_order_post_statuses', array(__CLASS__, 'registerPostStatus'));
            add_filter('wc_order_statuses', array(__CLASS__, 'addToStatusList'));

            // Bulk action: legacy orders screen and HPOS orders screen.
            add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'registerBulkAction'));
            add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'registerBulkAction'));
            add_filter('handle_bulk_actions-edit-shop_order', array(__CLASS__, 'handleBulkAction'), 10, 3);
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'handleBulkAction'), 10, 3);
            add_action('admin_notices', array(__CLASS__, 'bulkActionNotice'));

            // Quick action button in the orders list.
            add_filter('woocommerce_admin_order_actions', array(__CLASS__, 'addListAction'), 10, 2);

            // Optionally put refused items back in stock, like a cancellation.
            add_action('woocommerce_order_status_' . self::STATUS, array(__CLASS__, 'maybeRestock'), 10, 2);
            add_action('woocommerce_order_status_' . self::STATUS, array(__CLASS__, 'addRefusalNote'), 20, 2);
        }

        /**
         * Registers the post status (used by legacy storage and for status labels/counts).
         */
        public static function registerPostStatus($statuses)
        {
            $statuses['wc-' . self::STATUS] = array(
                'label'                     => _x('Refused delivery', 'Order status', DRPM_TEXT_DOMAIN),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop('Refused delivery <span class="count">(%s)</span>', 'Refused delivery <span class="count">(%s)</span>', DRPM_TEXT_DOMAIN),
            );

            return $statuses;
        }

        /**
         * Inserts the status right after "Completed" in WooCommerce's status lists.
         */
        public static function addToStatusList($orderStatuses)
        {
            $new = array();
            foreach ($orderStatuses as $key => $label) {
                $new[$key] = $label;
                if ('wc-completed' === $key) {
                    $new['wc-' . self::STATUS] = _x('Refused delivery', 'Order status', DRPM_TEXT_DOMAIN);
                }
            }
            if (!isset($new['wc-' . self::STATUS])) {
                $new['wc-' . self::STATUS] = _x('Refused delivery', 'Order status', DRPM_TEXT_DOMAIN);
            }

            return $new;
        }

        public static function registerBulkAction($bulkActions)
        {
            $bulkActions[self::BULK_ACTION] = __('Change status to refused delivery', DRPM_TEXT_DOMAIN);
            return $bulkActions;
        }

        /**
         * Handles the bulk status change. Native WooCommerce "mark_*" handling only kicks in
         * for core statuses, so we do it ourselves to be safe on both storage engines.
         */
        public static function handleBulkAction($redirect, $action, $orderIds)
        {
            if (self::BULK_ACTION !== $action || !current_user_can('edit_shop_orders')) {
                return $redirect;
            }

            $changed = 0;
            foreach ((array) $orderIds as $orderId) {
                $order = wc_get_order($orderId);
                if ($order && !$order->has_status(self::STATUS)) {
                    $order->update_status(self::STATUS, __('Order status changed by bulk edit:', DRPM_TEXT_DOMAIN), true);
                    $changed++;
                }
            }

            return add_query_arg(array(
                'drpm_bulk_refused' => 1,
                'changed'           => $changed,
            ), remove_query_arg(array('drpm_bulk_refused', 'changed'), $redirect));
        }

        public static function bulkActionNotice()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (empty($_REQUEST['drpm_bulk_refused'])) {
                return;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $changed = isset($_REQUEST['changed']) ? absint($_REQUEST['changed']) : 0;

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %d: number of orders */
                    _n('%d order status changed to refused delivery.', '%d order statuses changed to refused delivery.', $changed, DRPM_TEXT_DOMAIN),
                    $changed
                ))
            );
        }

        /**
         * Adds a one-click "Refused" button next to processing/on-hold orders in the list.
         */
        public static function addListAction($actions, $order)
        {
            if ($order->has_status(array('processing', 'on-hold', 'completed'))) {
                $actions['drpm_refused'] = array(
                    'url'    => wp_nonce_url(
                        admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=' . self::STATUS . '&order_id=' . $order->get_id()),
                        'woocommerce-mark-order-status'
                    ),
                    'name'   => __('Refused delivery', DRPM_TEXT_DOMAIN),
                    'action' => 'drpm-refused',
                );
            }

            return $actions;
        }

        public static function maybeRestock($orderId, $order = null)
        {
            if ('no' !== get_option('drpm_restock_on_refusal', 'yes')) {
                wc_maybe_increase_stock_levels($orderId);
            }
        }

        /**
         * Leaves a note with the customer's refusal history to give staff context.
         */
        public static function addRefusalNote($orderId, $order = null)
        {
            $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
            if (!$order) {
                return;
            }

            DRPM_Risk::flushCache();
            $risk = DRPM_Risk::evaluateOrder($order, false);

            $order->add_order_note(sprintf(
                /* translators: 1: number of refused orders, 2: months, 3: yes/no */
                __('Delivery refused. This customer has now refused %1$d order(s) in the last %2$d months. Restricted payment methods will be hidden for their next order: %3$s.', DRPM_TEXT_DOMAIN),
                $risk['refused'],
                DRPM_Risk::months(),
                $risk['at_risk'] ? __('yes', DRPM_TEXT_DOMAIN) : __('no', DRPM_TEXT_DOMAIN)
            ));
        }
    }

    DRPM_Order_Status::register();
}
