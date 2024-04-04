<?php
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {


   /**
	 * Registers a new order status for orders refused by customers in WooCommerce.
	 */
	function registerRefusedByCustomerStatus()
	{
		register_post_status('wc-refused-delivery', [
			'label' => _x('Order refused by customer', 'Order status', DRPM_PLUGIN_SHORT_NAME),
			'public' => true,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Order refused by customer <span class="count">(%s)</span>', 'Order refused by customer <span class="count">(%s)</span>', 'delivery_refusal_payment_manager'),
		]);
	}
	add_action('init', 'registerRefusedByCustomerStatus');

	/**
	 * Inserts the custom "Order refused by customer" status into various WooCommerce status filters and lists.
	 */
	function addRefusedByCustomerStatusToList($order_statuses)
	{
		$new_order_statuses = [];

		foreach ($order_statuses as $key => $status) {
			$new_order_statuses[$key] = $status;

			if ('wc-processing' === $key) {
				$new_order_statuses['wc-refused-delivery'] = _x('Order refused by customer', 'Order status', 'delivery_refusal_payment_manager');
			}
		}

		return $new_order_statuses;
	}
	add_filter('wc_order_statuses', 'addRefusedByCustomerStatusToList');

	/**
	 * Registers a bulk action for changing order status to "Order refused by customer".
	 */
	function registerBulkAction($bulk_actions)
	{
		$bulk_actions['mark_refused_by_customer'] = __('Change status to Order refused by customer', 'delivery_refusal_payment_manager');
		return $bulk_actions;
	}
	add_filter('bulk_actions-woocommerce_page_wc-orders', 'registerBulkAction');

	/**
	 * Handles the bulk action for changing order statuses to "Order refused by customer".
	 */
	function bulkProcessCustomStatus($redirect, $doaction, $object_ids)
	{
		if ('mark_refused_by_customer' === $doaction) {
			foreach ($object_ids as $order_id) {
				$order = wc_get_order($order_id);
				$order->update_status('wc-refused-delivery');
			}

			$redirect = add_query_arg([
				'bulk_refused_by_customer' => 'marked_refused_by_customer',
				'changed' => count($object_ids),
			], $redirect);
		}

		return $redirect;
	}
	add_action('handle_bulk_actions-woocommerce_page_wc-orders', 'bulkProcessCustomStatus', 20, 3);

	/**
	 * Displays admin notices after bulk changing order statuses to "Order refused by customer".
	 */
	function customOrderStatusNotices()
	{
		if (
			isset($_REQUEST['bulk_refused_delivery']) &&
			'marked_refused_delivery' === $_REQUEST['bulk_refused_delivery'] &&
			isset($_REQUEST['changed']) && 
			$_REQUEST['changed']
		) {
			printf(
				'<div id="message" class="updated notice is-dismissible"><p>' .
				_n('%s order status changed to Order refused by customer.', '%s order statuses changed to Order refused by customer.', intval($_REQUEST['changed']), 'delivery_refusal_payment_manager') . '</p></div>',
				intval($_REQUEST['changed'])
			);
		}
	}
	add_action('admin_notices', 'customOrderStatusNotices');

	
	/**
	 * Add metadata to the order if a payment method has been removed from the checkout
	 */
	function addCustomOrderMetaData($order_id)
	{
		$order = wc_get_order( $order_id );
		if ($order) {
			$order->add_meta_data( '_drpm_payment_method_hidden', true );
			$order->save();
		}

	}
	add_action("woocommerce_new_order","addCustomOrderMetaData");
	
	
	function addCssClassToOrderListRow($classes, $class, $post_id)
	{
		if ('shop_order' === OrderUtil::get_order_type( $post_id ) && is_admin()) {
			$order = wc_get_order($post_id);

			$meta_value = $order->get_meta('_drpm_payment_method_hidden', true);

			if (!empty($meta_value)) {
				// Add a custom class
				$classes[] = 'drpm_payment_method_hidden_class';
			}
		}

		return $classes;
		
	}
	add_filter('post_class', 'addCssClassToOrderListRow', 10, 3);

}
