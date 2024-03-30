<?php
if ( ! class_exists( 'SDCOD_UserMeta' ) ) {

	class SDCOD_OrderMeta {
	// [--] Task: Enhance order details and list views with risk score visualization and management.
	// Class: SDCOD_OrderMeta
	//   - Function: add_meta_boxes() - Adds a new meta box to the order edit screen to show the risk score.
	//   - Function: display_order_risk_level() - Renders the risk score and any related controls in the order meta box.
	//   - Function: orders_list_column_content() - Adds the risk score to a new column in the orders list.
	// [--] Add option to manually adjust the risk score from order details.

		/**
		 * Hook into the appropriate actions and filters.
		 */
		public function __construct() {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_list_column' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'orders_list_column_content' ), 20, 2 );
			add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_order_risk_level' ) );
		}

		/**
		 * Adds a new meta box to the order edit screen to display the risk score.
		 */
		public function add_meta_boxes() {
			global $safeDeliveryCOD;
			$screen = $safeDeliveryCOD->isHPOSEnabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
			add_meta_box(
				'sdcod_ordermeta_box',
				__( 'Safe Delivery COD', 'safedeliverycod' ),
				array( $this, 'render_meta_box_content' ),
			   $screen,
			   'side',
			   'core'
			);
		}

		/**
		 * Renders the risk score meta box content.
		 *
		 * @param WP_Post $post The current post object.
		 */
		public function render_meta_box_content( $post ) {
			// Fetch risk score from order meta or calculate if not set.
			echo 'TESTTT';
			$risk_score = get_post_meta( $post->ID, '_delivery_risk_score', true );
			echo '<p>' . sprintf( __( 'Risk Score: %s', 'safedeliverycod' ), $risk_score ) . '</p>';

			// Optionally, add a button or input field to adjust the risk score manually.
			// Ensure to handle saving this new value appropriately (not shown here for brevity).
		}

		/**
		 * Adds a new column to the orders list in the admin to display the risk score.
		 *
		 * @param array $columns Existing columns.
		 * @return array Modified columns.
		 */
		public function add_orders_list_column( $columns ) {
			$columns['delivery_risk_score'] = __( 'Risk Score', 'safedeliverycod' );
			return $columns;
		}

		/**
		 * Handles rendering content for the custom risk score column in the orders list.
		 *
		 * @param string $column Current column name.
		 * @param int $post_id The post ID.
		 */
		public function orders_list_column_content( $column, $post_id ) {
			if ( 'delivery_risk_score' === $column ) {
				$risk_score = get_post_meta( $post_id, '_delivery_risk_score', true );
				echo esc_html( $risk_score );
			}
		}

		/**
		 * Displays the risk score on the order details page.
		 *
		 * @param WC_Order $order The order object.
		 */
		public function display_order_risk_level( $order ) {
			$risk_score = get_post_meta( $order->get_id(), '_delivery_risk_score', true );
			echo '<p class="order-risk-score">' . sprintf( __( 'Delivery Risk Score: %s', 'safedeliverycod' ), $risk_score ) . '</p>';
		}
	}
	new SDCOD_OrderMeta();
}
