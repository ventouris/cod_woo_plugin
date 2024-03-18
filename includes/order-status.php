<?php
// [--] Task: Manage the registration and integration of a custom order status for high-risk COD orders.
// Function: register_cod_risk_status() - Registers a new order status in WooCommerce for high-risk COD orders.
// Function: add_cod_risk_status_to_list() - Inserts the custom status into various WooCommerce status filters and lists.
// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    /**
     * Registers a new order status for high-risk COD orders in WooCommerce.
     */
    function register_cod_risk_status() {
        register_post_status( 'wc-cod-high-risk', array(
            'label'                     => _x( 'COD High Risk', 'Order status', 'safedeliverycod' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'COD High Risk <span class="count">(%s)</span>', 'COD High Risk <span class="count">(%s)</span>', 'safedeliverycod' ),
        ) );
    }
    add_action( 'init', 'register_cod_risk_status' );

    /**
     * Inserts the custom COD High Risk status into various WooCommerce status filters and lists.
     */
    function add_cod_risk_status_to_list( $order_statuses ) {
        // Adding new status to existing WooCommerce order statuses
        $new_order_statuses = array();

        // Ensure 'wc-cod-high-risk' is inserted after 'wc-processing'
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;

            if ( 'wc-processing' === $key ) {
                $new_order_statuses['wc-cod-high-risk'] = _x( 'COD High Risk', 'Order status', 'safedeliverycod' );
            }
        }

        return $new_order_statuses;
    }
    add_filter( 'wc_order_statuses', 'add_cod_risk_status_to_list' );

}
