<?php
class SDCOD_ScoreCalculator {
// [--] Task: Calculate and manage the delivery risk score based on customer history.
// Class: SDCOD_ScoreCalculator
//   - Function: calculate_score() - Determines the risk score based on the customer's email, mobile number, address, and past order history.
//   - Function: update_customer_score() - Updates the customer's risk score in the database after any new order or return.

    /**
     * Determines the risk score based on the customer's email, mobile number, address, and past order history.
     * 
     * @param int $customer_id The customer's user ID.
     * @return int The calculated risk score.
     */
    public static function calculate_score( $customer_id ) {
        // Initialize risk score
        $risk_score = 0;

        // Example logic to calculate risk score
        // This should be replaced with actual logic based on email, mobile number, address, and order history analysis.
        // For instance, fetching user's orders and checking for 'wc-cod-flagged' status orders.
        // Adjust the risk score based on findings.

        // Placeholder for demonstration
        $risk_score = apply_filters( 'safedeliverycod_calculate_risk_score', $risk_score, $customer_id );

        return $risk_score;
    }

    /**
     * Updates the customer's risk score in the database after any new order or return.
     * 
     * @param int $customer_id The customer's user ID.
     * @param int $new_score The new risk score to be saved.
     */
    public static function update_customer_score( $customer_id, $new_score ) {
        // Check for valid customer ID and new score
        if ( ! $customer_id || ! is_numeric( $new_score ) ) {
            return;
        }

        // Update risk score in user meta
        update_user_meta( $customer_id, 'safedeliverycod_risk_score', $new_score );
    }
}
