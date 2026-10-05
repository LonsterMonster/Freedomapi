<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Alerts {

    public function __construct(){
        add_shortcode('api_admin_alerts', [$this,'render']);
    }

    public function render(){

        if (!current_user_can('manage_options')) return 'Access denied';

        $users = get_users();

        ob_start(); ?>

        <div class="apiplatform-card">
            <h3>🚨 Alerts</h3>

            <?php foreach($users as $u):

                $alerts = get_user_meta($u->ID, 'apiplatform_alert');

                if (!$alerts) continue;

                foreach($alerts as $a): ?>

                    <p>
                        <strong><?php echo esc_html($u->user_email); ?></strong> →
                        <?php echo esc_html($a['message']); ?>
                    </p>

                <?php endforeach;

            endforeach; ?>

        </div>

        <?php return ob_get_clean();
    }
}