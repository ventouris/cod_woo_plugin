<?php
if ( ! class_exists( 'SDCOD_HideCOD' ) ) {
// [--] Task: Hide the COD payment option for customers with high risk scores.
// Class: SDCOD_HideCOD
//   - Function: check_customer_risk() - Checks the customer's risk score against the threshold.
//   - Function: remove_cod_payment_option() - Removes COD from available payment methods if the risk is too high.
    class SDCOD_HideCOD {

        public function __construct() {
            add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_cod_payment_option' ) );
        }

        /**
         * Removes the COD payment option based on the customer's risk score.
         *
         * @param array $available_gateways The available payment gateways.
         * @return array The filtered payment gateways.
         */
        public function remove_cod_payment_option( $available_gateways ) {
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                return $available_gateways;
            }

            $customer_id = get_current_user_id();
            if ( $customer_id && $this->check_customer_risk( $customer_id ) ) {
                unset( $available_gateways['cod'] );
            }

            return $available_gateways;
        }

        /**
         * Checks the customer's risk score against the predefined threshold.
         *
         * @param int $customer_id The customer's user ID.
         * @return bool True if the risk is too high, false otherwise.
         */
        private function check_customer_risk( $customer_id ) {
            $risk_score = get_user_meta( $customer_id, 'safedeliverycod_risk_score', true );
            $risk_threshold = get_option( 'safedeliverycod_risk_threshold', 0 );

            return is_numeric( $risk_score ) && is_numeric( $risk_threshold ) && (int) $risk_score >= (int) $risk_threshold;
        }
    }

    new SDCOD_HideCOD();
}
