<?php

if (!class_exists('DRPM_OrderMeta')) {
    class DRPM_OrderMeta
    {
        /**
         * Initialize the class and hook into the appropriate actions and filters.
         */
        public function __construct()
        {
            add_action('add_meta_boxes', array($this, 'addMetaBoxes'));
            add_filter('manage_edit-shop_order_columns', array($this, 'addOrdersListColumn'), 20);
            add_action('manage_shop_order_posts_custom_column', array($this, 'ordersListColumnContent'), 20, 2);
            add_action('woocommerce_order_details_after_order_table', array($this, 'displayOrderRiskLevel'));
        }

        /**
         * Adds a new meta box to the order edit screen to display the risk score.
         */
        public function addMetaBoxes()
        {
            global $deliveryRefusalPaymentManager;
            $screen = $deliveryRefusalPaymentManager->isHPOSEnabled() ? wc_get_page_screen_id('shop-order') : 'shop_order';
            add_meta_box(
                'drpm_ordermeta_box',
                __('Refused delivery status', 'delivery_refusal_payment_manager'),
                array($this, 'renderMetaBoxContent'),
                $screen,
                'side',
                'core'
            );
        }

        /**
         * Renders the risk score meta box content.
         */
        public function renderMetaBoxContent($post)
        {
            $order = wc_get_order($post->ID);
            $riskScore = $order->get_meta('_drpm_payment_method_hidden', true);
            echo '<p>' . sprintf(__('Refused Delivery: %s', 'delivery_refusal_payment_manager'), $riskScore) . '</p>';
        }

//         /**
//          * Adds a new column to the orders list in the admin to display the risk score.
//          */
//         public function addOrdersListColumn($columns)
//         {
//             $columns['delivery_risk_score'] = __('Risk Score', 'delivery_refusal_payment_manager');
//             return $columns;
//         }

//         /**
//          * Handles rendering content for the custom risk score column in the orders list.
//          */
//         public function ordersListColumnContent($column, $post_id)
//         {
//             if ('delivery_risk_score' === $column) {
//                 $riskScore = get_post_meta($post_id, '_delivery_risk_score', true);
//                 echo esc_html($riskScore);
//             }
//         }

//         /**
//          * Displays the risk score on the order details page.
//          */
//         public function displayOrderRiskLevel($order)
//         {
//             $riskScore = $order->get_meta('_drpm_payment_method_hidden', true);
//             echo '<p class="order-risk-score">' . sprintf(__('Delivery Risk Score: %s', 'delivery_refusal_payment_manager'), $riskScore) . '</p>';
//         }
    }

    new DRPM_OrderMeta();
}
