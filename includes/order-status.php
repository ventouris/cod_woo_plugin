<?php
// [--] Task: Manage the registration and integration of a custom order status for high-risk COD orders.
// Function: register_cod_risk_status() - Registers a new order status in WooCommerce for high-risk COD orders.
// Function: add_cod_risk_status_to_list() - Inserts the custom status into various WooCommerce status filters and lists.
// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    /**
     * Registers a new order status for Rejected COD orders in WooCommerce.
     */
    function register_rejected_cod_status() {
        register_post_status( 'wc-rejected-cod', array(
            'label'                     => _x( 'Rejected COD', 'Order status', SDCOD_PLUGIN_SHORT_NAME ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Rejected COD <span class="count">(%s)</span>', 'Rejected COD <span class="count">(%s)</span>', SDCOD_PLUGIN_SHORT_NAME ),
        ) );
    }
    add_action( 'init', 'register_rejected_cod_status' );

    /**
     * Inserts the custom Rejected COD status into various WooCommerce status filters and lists.
     */
    function add_rejected_cod_status_to_list( $order_statuses ) {
        // Adding new status to existing WooCommerce order statuses
        $new_order_statuses = array();

        // Ensure 'wc-rejected-cod' is inserted after 'wc-processing'
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;

            if ( 'wc-processing' === $key ) {
                $new_order_statuses['wc-rejected-cod'] = _x( 'Rejected COD', 'Order status', SDCOD_PLUGIN_SHORT_NAME );
            }
        }

        return $new_order_statuses;
    }
    add_filter( 'wc_order_statuses', 'add_rejected_cod_status_to_list' );
	
	
	function register_bulk_action( $bulk_actions ) {
        $bulk_actions['mark_rejected_cod'] = __( 'Change status to Rejected COD', SDCOD_PLUGIN_SHORT_NAME ); 
        return $bulk_actions;
    }
	add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'register_bulk_action'  );


    function bulk_process_custom_status( $redirect, $doaction, $object_ids ) {
        if ( 'mark_rejected_cod' === $doaction ) {
            foreach ( $object_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                $order->update_status( 'wc-rejected-cod' );
            }

            $redirect = add_query_arg(
                array(
                    'bulk_rejected_cod' => 'marked_rejected_cod',
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
            isset( $_REQUEST['bulk_rejected_cod'] ) && 
            'marked_rejected_cod' === $_REQUEST['bulk_rejected_cod'] &&
            isset( $_REQUEST['changed'] ) && 
            $_REQUEST['changed'] 
        ) {
            printf(
                '<div id="message" class="updated notice is-dismissible"><p>' . 
                _n( '%s order status changed to Rejected COD.', '%s order statuses changed to Rejected COD.', intval( $_REQUEST['changed'] ), SDCOD_PLUGIN_SHORT_NAME ) . '</p></div>', 
                intval( $_REQUEST['changed'] )
            );
        }
    }
	add_action( 'admin_notices', 'custom_order_status_notices' );


}
