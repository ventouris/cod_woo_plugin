<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_OrderMeta')) {
    /**
     * Order screens: risk meta box, orders list column and row highlighting.
     * Supports both HPOS and legacy (posts) order storage.
     */
    class DRPM_OrderMeta
    {
        /**
         * Initialize the class and hook into the appropriate actions and filters.
         */
        public function __construct()
        {
            add_action('add_meta_boxes', array($this, 'addMetaBoxes'));

            // Orders list column: legacy screen.
            add_filter('manage_edit-shop_order_columns', array($this, 'addOrdersListColumn'), 20);
            add_action('manage_shop_order_posts_custom_column', array($this, 'renderLegacyColumn'), 20, 2);
            // Orders list column: HPOS screen.
            add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'addOrdersListColumn'), 20);
            add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'renderHposColumn'), 20, 2);

            // Highlight rows of orders placed with restricted methods.
            add_filter('post_class', array($this, 'addLegacyRowClass'), 10, 3);
            add_filter('woocommerce_shop_order_list_table_order_css_classes', array($this, 'addHposRowClass'), 10, 2);
        }

        /**
         * Adds the meta box to the order edit screen (HPOS or legacy).
         */
        public function addMetaBoxes()
        {
            $screen = DeliveryRefusalPaymentManager::isHPOSEnabled() && function_exists('wc_get_page_screen_id')
                ? wc_get_page_screen_id('shop-order')
                : 'shop_order';

            add_meta_box(
                'drpm_ordermeta_box',
                __('COD Guard', 'cod-guard'),
                array($this, 'renderMetaBoxContent'),
                $screen,
                'side',
                'high'
            );
        }

        /**
         * Renders the meta box: risk verdict, customer history and links to refused orders.
         *
         * @param WP_Post|WC_Order $postOrOrder
         */
        public function renderMetaBoxContent($postOrOrder)
        {
            $order = $postOrOrder instanceof WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
            if (!$order) {
                return;
            }

            $risk = DRPM_Risk::evaluateOrder($order);
            $flagged = $this->isFlagged($order);
            $months = DRPM_Risk::months();

            echo '<div class="drpm-metabox">';

            printf(
                '<p><span class="drpm-badge drpm-badge--%1$s">%2$s</span></p>',
                esc_attr($risk['at_risk'] ? 'risk' : 'ok'),
                esc_html($this->verdictLabel($risk))
            );

            echo '<ul class="drpm-metabox__stats">';
            printf(
                '<li>%s <strong>%d</strong></li>',
                esc_html__('Refused deliveries:', 'cod-guard'),
                (int) $risk['refused']
            );
            printf(
                '<li>%s <strong>%d</strong></li>',
                esc_html__('Completed orders:', 'cod-guard'),
                (int) $risk['delivered']
            );
            printf(
                '<li>%s <strong>%s%%</strong></li>',
                esc_html__('Refusal rate:', 'cod-guard'),
                esc_html(number_format_i18n($risk['rate'], 1))
            );
            echo '</ul>';

            echo '<p class="description">' . esc_html(sprintf(
                /* translators: 1: months, 2: list of fields */
                $months > 0
                    ? __('Other orders in the last %1$d months matching: %2$s.', 'cod-guard')
                    : __('Other orders (all time) matching: %2$s.', 'cod-guard'),
                $months,
                implode(', ', array_map(array('DRPM_Risk', 'fieldLabel'), $risk['checked_fields'])) ?: '—'
            )) . '</p>';

            if ($risk['refused_ids']) {
                $links = array();
                foreach (array_slice($risk['refused_ids'], 0, 10) as $refusedId) {
                    $refusedOrder = wc_get_order($refusedId);
                    if ($refusedOrder) {
                        $links[] = sprintf('<a href="%s">#%s</a>', esc_url($refusedOrder->get_edit_order_url()), esc_html($refusedOrder->get_order_number()));
                    }
                }
                echo '<p>' . esc_html__('Refused orders:', 'cod-guard') . ' ' . implode(', ', $links) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
            }

            if ($flagged) {
                echo '<p class="drpm-metabox__flag">' . esc_html__('Payment methods were hidden when this order was placed.', 'cod-guard') . '</p>';
            }

            $customerId = (int) $order->get_customer_id();
            if ($customerId && current_user_can('edit_user', $customerId)) {
                printf(
                    '<p><a href="%s">%s</a></p>',
                    esc_url(get_edit_user_link($customerId) . '#drpm-override'),
                    esc_html__('Set an exception for this customer', 'cod-guard')
                );
            }

            echo '</div>';
        }

        /**
         * Adds the "Delivery risk" column after the order status.
         */
        public function addOrdersListColumn($columns)
        {
            $new = array();
            foreach ($columns as $key => $label) {
                $new[$key] = $label;
                if ('order_status' === $key) {
                    $new['drpm_risk'] = __('Delivery risk', 'cod-guard');
                }
            }
            if (!isset($new['drpm_risk'])) {
                $new['drpm_risk'] = __('Delivery risk', 'cod-guard');
            }

            return $new;
        }

        public function renderLegacyColumn($column, $postId)
        {
            if ('drpm_risk' === $column) {
                $this->renderColumn(wc_get_order($postId));
            }
        }

        public function renderHposColumn($column, $order)
        {
            if ('drpm_risk' === $column) {
                $this->renderColumn($order instanceof WC_Order ? $order : wc_get_order($order));
            }
        }

        private function renderColumn($order)
        {
            if (!$order) {
                return;
            }

            $risk = DRPM_Risk::evaluateOrder($order);
            if (!$risk['refused'] && 'blocked' !== $risk['reason']) {
                echo '<span class="drpm-muted">—</span>';
                return;
            }

            printf(
                '<span class="drpm-badge drpm-badge--%1$s" title="%2$s">%3$s</span>',
                esc_attr($risk['at_risk'] ? 'risk' : 'warn'),
                esc_attr($this->verdictLabel($risk)),
                esc_html(sprintf(
                    /* translators: 1: refused orders, 2: refused + completed orders */
                    __('%1$d / %2$d refused', 'cod-guard'),
                    $risk['refused'],
                    $risk['refused'] + $risk['delivered']
                ))
            );
        }

        private function verdictLabel(array $risk)
        {
            switch ($risk['reason']) {
                case 'blocked':
                    return __('Always blocked (exception)', 'cod-guard');
                case 'trusted':
                    return __('Always trusted (exception)', 'cod-guard');
                case 'threshold':
                    return __('High risk · methods hidden', 'cod-guard');
                default:
                    return $risk['refused'] > 0
                        ? sprintf(
                            /* translators: %d: number of refused orders */
                            _n('Watch · %d refusal', 'Watch · %d refusals', $risk['refused'], 'cod-guard'),
                            $risk['refused']
                        )
                        : __('No refusal history', 'cod-guard');
            }
        }

        private function isFlagged($order)
        {
            return 'yes' === $order->get_meta(DRPM_Checkout::FLAG_META, true);
        }

        public function addLegacyRowClass($classes, $class, $postId)
        {
            if (is_admin() && 'shop_order' === get_post_type($postId)) {
                $order = wc_get_order($postId);
                if ($order && $this->isFlagged($order)) {
                    $classes[] = 'drpm_payment_method_hidden_class';
                }
            }

            return $classes;
        }

        public function addHposRowClass($classes, $order)
        {
            if ($order instanceof WC_Order && $this->isFlagged($order)) {
                $classes[] = 'drpm_payment_method_hidden_class';
            }

            return $classes;
        }
    }

    new DRPM_OrderMeta();
}
