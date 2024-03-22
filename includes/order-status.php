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
	
	
	function register_bulk_action( $bulk_actions ) {
        $bulk_actions['mark_cod_high_risk'] = __( 'Change status to COD High Risk', 'safedeliverycod' ); 
        return $bulk_actions;
    }
	add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'register_bulk_action'  );


    function bulk_process_custom_status( $redirect, $doaction, $object_ids ) {
        if ( 'mark_cod_high_risk' === $doaction ) {
            foreach ( $object_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                $order->update_status( 'wc-cod-high-risk' );
            }

            $redirect = add_query_arg(
                array(
                    'bulk_cod_high_risk' => 'marked_cod_high_risk',
                    'changed' => count( $object_ids ),
                ),
                $redirect
            );
        }

        return $redirect;
    }
	add_action('handle_bulk_actions-woocommerce_page_wc-orders', 'bulk_process_custom_status', 20, 3);

    function custom_order_status_notices() {
        if ( 
            isset( $_REQUEST['bulk_cod_high_risk'] ) && 
            'marked_cod_high_risk' === $_REQUEST['bulk_cod_high_risk'] &&
            isset( $_REQUEST['changed'] ) && 
            $_REQUEST['changed'] 
        ) {
            printf(
                '<div id="message" class="updated notice is-dismissible"><p>' . 
                _n( '%s order status changed to COD High Risk.', '%s order statuses changed to COD High Risk.', intval( $_REQUEST['changed'] ), 'safedeliverycod' ) . '</p></div>', 
                intval( $_REQUEST['changed'] )
            );
        }
    }
	add_action( 'admin_notices', 'custom_order_status_notices' );
}
