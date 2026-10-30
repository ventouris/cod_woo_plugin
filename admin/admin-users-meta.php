<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_UserMeta')) {
    /**
     * User profile section: refusal history and a manual override (always allow / always restrict).
     */
    class DRPM_UserMeta
    {
        public function __construct()
        {
            add_action('show_user_profile', array($this, 'add_user_profile_fields'));
            add_action('edit_user_profile', array($this, 'add_user_profile_fields'));

            add_action('personal_options_update', array($this, 'save_user_profile_fields'));
            add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));
        }

        /**
         * Adds the delivery refusal section to the user profile page in the admin.
         *
         * @param WP_User $user The user being edited.
         */
        public function add_user_profile_fields($user)
        {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $override = get_user_meta($user->ID, DRPM_Risk::USER_OVERRIDE_META, true);
            $risk = $this->evaluateUser($user);
            ?>
            <h2 id="drpm-override"><?php esc_html_e('COD Guard', 'cod-guard'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Refusal history', 'cod-guard'); ?></th>
                    <td>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: 1: refused orders, 2: completed orders, 3: rate */
                            __('%1$d refused, %2$d completed (%3$s%% refusal rate)', 'cod-guard'),
                            $risk['refused'],
                            $risk['delivered'],
                            number_format_i18n($risk['rate'], 1)
                        ));
                        if ($risk['at_risk']) {
                            echo ' <span class="drpm-badge drpm-badge--risk">' . esc_html__('Methods hidden', 'cod-guard') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="drpm-override-select"><?php esc_html_e('Exception', 'cod-guard'); ?></label></th>
                    <td>
                        <?php wp_nonce_field('drpm_save_override', 'drpm_override_nonce'); ?>
                        <select name="drpm_override" id="drpm-override-select">
                            <option value="" <?php selected($override, ''); ?>><?php esc_html_e('Automatic (based on history)', 'cod-guard'); ?></option>
                            <option value="allow" <?php selected($override, 'allow'); ?>><?php esc_html_e('Always trust (never hide payment methods)', 'cod-guard'); ?></option>
                            <option value="restrict" <?php selected($override, 'restrict'); ?>><?php esc_html_e('Always block (always hide the selected payment methods)', 'cod-guard'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php
        }

        /**
         * Saves the override.
         *
         * @param int $user_id ID of the user being saved.
         */
        public function save_user_profile_fields($user_id)
        {
            if (!current_user_can('manage_woocommerce') || !current_user_can('edit_user', $user_id)) {
                return;
            }
            if (!isset($_POST['drpm_override_nonce']) || !wp_verify_nonce(sanitize_key($_POST['drpm_override_nonce']), 'drpm_save_override')) {
                return;
            }

            $value = isset($_POST['drpm_override']) ? sanitize_key($_POST['drpm_override']) : '';
            if (in_array($value, array('allow', 'restrict'), true)) {
                update_user_meta($user_id, DRPM_Risk::USER_OVERRIDE_META, $value);
            } else {
                delete_user_meta($user_id, DRPM_Risk::USER_OVERRIDE_META);
            }
        }

        private function evaluateUser($user)
        {
            $customer = new WC_Customer($user->ID);

            return DRPM_Risk::evaluateCustomer($customer);
        }
    }

    new DRPM_UserMeta();
}
