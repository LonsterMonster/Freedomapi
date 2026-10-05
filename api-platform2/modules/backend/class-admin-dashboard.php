<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Dashboard {

    public function __construct(){
        add_shortcode('api_admin_dashboard', [$this, 'render']);
    }

    public function render(){

        if (!current_user_can('manage_options')) {
            return 'Access denied';
        }

        $users = get_users(['number'=>10]);

        ob_start(); ?>

        <div class="apiplatform-card">

            <h2>🛠 Admin Control Panel</h2>

            <h3>👥 Recent Users</h3>

            <?php foreach($users as $u): ?>
                <div class="admin-row">
                    <span><?php echo esc_html($u->user_email); ?></span>

                    <a href="<?php echo esc_url(add_query_arg(['apipage' => 'admin-user', 'uid' => absint($u->ID)], class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))); ?>">
                        Manage →
                    </a>
                </div>
            <?php endforeach; ?>

        </div>

        <?php
        return ob_get_clean();
    }
}
