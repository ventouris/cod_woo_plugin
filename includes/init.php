<?php
// [--] Task: Plugin initialization tasks like registering custom order statuses.
// Function: SDCOD_register_custom_order_status() - Creates a new order status for flagged COD orders.
// Function: SDCOD_add_order_status_to_wc() - Ensures the new status is integrated into WooCommerce's list of order statuses.

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    /**
     * Registers a custom order status for flagged COD orders.
     */
    function SDCOD_register_custom_order_status() {
        register_post_status( 'wc-cod-flagged', array(
            'label'                     => _x( 'COD Flagged', 'Order status', 'safedeliverycod' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'COD Flagged <span class="count">(%s)</span>', 'COD Flagged <span class="count">(%s)</span>', 'safedeliverycod' ),
        ) );
    }
    add_action( 'init', 'SDCOD_register_custom_order_status' );

    /**
     * Ensures the new custom order status is integrated into WooCommerce's list of order statuses.
     */
    function SDCOD_add_order_status_to_wc( $order_statuses ) {
        // Adding new status to the list
        $new_order_status = array( 'wc-cod-flagged' => _x( 'COD Flagged', 'Order status', 'safedeliverycod' ) );

        return array_merge( $order_statuses, $new_order_status );
    }
    add_filter( 'wc_order_statuses', 'SDCOD_add_order_status_to_wc' );

    /**
     * Include other initial setup files or actions here.
     */

}
