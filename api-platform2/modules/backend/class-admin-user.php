<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_User {

    public function __construct(){
        add_shortcode('api_admin_user', [$this, 'render']);
    }

    public function render(){

        if (!current_user_can('manage_options')) return 'Access denied';

        $user_id = intval($_GET['uid'] ?? 0);
        if (!$user_id) return 'Invalid user';

        $user = get_userdata($user_id);

        $nonce = isset($_POST['apiplatform_admin_user_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['apiplatform_admin_user_nonce']))
            : '';

        if ((isset($_POST['reset_usage']) || isset($_POST['give_credits'])) && !wp_verify_nonce($nonce, 'apiplatform_admin_user_' . $user_id)) {
            return 'Security check failed';
        }

        // 🔥 ACTIONS
        if (isset($_POST['reset_usage'])) {
            delete_transient('apiplatform_usage_'.$user_id);
        }

        if (isset($_POST['give_credits'])) {
            apiplatform_add_transaction($user_id, 100, 'admin', 'Manual credit');
        }

        ob_start(); ?>

        <div class="apiplatform-card">

            <h2>👤 <?php echo esc_html($user->user_email); ?></h2>

            <form method="post">
                <?php wp_nonce_field('apiplatform_admin_user_' . $user_id, 'apiplatform_admin_user_nonce'); ?>
                <button name="reset_usage">Reset Usage</button>
                <button name="give_credits">+100 Credits</button>
            </form>

        </div>

        <?php
        return ob_get_clean();
    }
}
