<?php
if ( ! class_exists( 'SDCOD_UserMeta' ) ) {
// [--] Task: Add delivery risk score section to user profiles in the WordPress admin.
// Class: SDCOD_UserMeta
//   - Function: add_user_profile_fields() - Adds fields to the user profile for displaying and managing the user's risk score.
//   - Function: save_user_profile_fields() - Handles saving any changes made to the risk score from the user profile.
    class SDCOD_UserMeta {

        public function __construct() {
            add_action( 'show_user_profile', array( $this, 'add_user_profile_fields' ) );
            add_action( 'edit_user_profile', array( $this, 'add_user_profile_fields' ) );

            add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
            add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
        }

        /**
         * Adds custom fields to the user profile page in the admin.
         *
         * @param WP_User $user The current WP_User object.
         */
        public function add_user_profile_fields( $user ) {
            // Check if the current user has permission to edit this data
            if ( ! current_user_can( 'manage_woocommerce', $user->ID ) ) {
                return;
            }

            ?>
            <h3><?php _e("SafeDeliveryCOD Delivery Risk Score", "safedeliverycod"); ?></h3>

            <table class="form-table">
                <tr>
                    <th><label for="safedeliverycod-risk-score"><?php _e("Delivery Risk Score", "safedeliverycod"); ?></label></th>
                    <td>
                        <input type="text" name="safedeliverycod_risk_score" id="safedeliverycod-risk-score" value="<?php echo esc_attr( get_user_meta( $user->ID, 'safedeliverycod_risk_score', true ) ); ?>" class="regular-text" /><br />
                        <span class="description"><?php _e("Edit the user's delivery risk score.", "safedeliverycod"); ?></span>
                    </td>
                </tr>
            </table>
            <?php
        }

        /**
         * Saves the custom fields added to the user profile page in the admin.
         *
         * @param int $user_id ID of the current user.
         */
        public function save_user_profile_fields( $user_id ) {
            // Verify the request to prevent CSRF attacks
            if ( ! current_user_can( 'edit_user', $user_id ) ) {
                return false;
            }

            // Update the risk score in the user meta
            if ( isset( $_POST['safedeliverycod_risk_score'] ) ) {
                update_user_meta( $user_id, 'safedeliverycod_risk_score', sanitize_text_field( $_POST['safedeliverycod_risk_score'] ) );
            }
        }
    }

    new SDCOD_UserMeta();
}
