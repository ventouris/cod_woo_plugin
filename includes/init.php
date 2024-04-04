<?php
// [--] Task: Plugin initialization tasks like registering custom order statuses.
// Function: SDCOD_register_custom_order_status() - Creates a new order status for flagged COD orders.
// Function: SDCOD_add_order_status_to_wc() - Ensures the new status is integrated into WooCommerce's list of order statuses.

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {


    /**
     * Include other initial setup files or actions here.
     */

}
