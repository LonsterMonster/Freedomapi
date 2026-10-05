<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('APIPlatform_Admin_Debug')) {

class APIPlatform_Admin_Debug {

    public function __construct(){
        add_shortcode('api_debug_page', [$this, 'render']);
    }

    public function render(){

        if (!current_user_can('manage_options')) {
            return 'Access denied';
        }

        global $wpdb;

        $logs_table = $wpdb->prefix.'apiplatform_logs';

        // 🔥 HANDLE HEAL (SAFE)
        if (
            isset($_POST['apiplatform_heal']) &&
            isset($_POST['_wpnonce']) &&
            wp_verify_nonce($_POST['_wpnonce'],'apiplatform_debug')
        ){
            if (function_exists('apiplatform_run_heal')) {
                apiplatform_run_heal();
                echo '<div style="color:lime;margin-bottom:10px;">✔ System healed</div>';
            }
        }

        // 🔥 TABLE CHECK
        $tables = [
            'Logs'         => $logs_table,
            'Transactions' => $wpdb->prefix.'apiplatform_transactions'
        ];

        // 🔥 FUNCTION CHECK
        $functions = [
            'apiplatform_get_plan_limits',
            'apiplatform_get_total_usage',
            'apiplatform_check_rate_limit',
            'apiplatform_check_key_scope',
            'apiplatform_track_key_usage',
            'apiplatform_track_billing_usage',
            'apiplatform_is_banned',
            'apiplatform_add_transaction',
        ];

        // 🔥 LOG DATA (SAFE)
        $logs_exist = ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table);

        $recent_logs = [];
        $errors = [];
        $slow = [];
        $top_endpoints = [];

        if ($logs_exist){

            $recent_logs = $wpdb->get_results("
                SELECT * FROM $logs_table
                ORDER BY id DESC
                LIMIT 20
            ");

            $errors = $wpdb->get_results("
                SELECT * FROM $logs_table
                WHERE status >= 400
                ORDER BY id DESC
                LIMIT 20
            ");

            $slow = $wpdb->get_results("
                SELECT * FROM $logs_table
                WHERE response_time > 1
                ORDER BY response_time DESC
                LIMIT 10
            ");

            $top_endpoints = $wpdb->get_results("
                SELECT endpoint, COUNT(*) as total
                FROM $logs_table
                GROUP BY endpoint
                ORDER BY total DESC
                LIMIT 5
            ");
        }

        // 🔥 STATS
        $user_count = count_users();
        $api_count  = wp_count_posts('user_api')->publish ?? 0;

        ob_start(); ?>

        <!-- 🔧 SYSTEM -->
        <div class="apiplatform-card">
            <h3>🛠 System</h3>

            <form method="post">
                <?php wp_nonce_field('apiplatform_debug'); ?>
                <button name="apiplatform_heal" class="button button-primary">
                    🔧 Heal System
                </button>
            </form>

            <p>DB Ready →
                <strong style="color:<?php echo get_option('apiplatform_db_ready') ? 'lime':'orange'; ?>">
                    <?php echo get_option('apiplatform_db_ready') ? 'YES' : 'NO'; ?>
                </strong>
            </p>

            <p>PHP → <?php echo phpversion(); ?></p>
            <p>WP → <?php echo get_bloginfo('version'); ?></p>
        </div>

        <!-- 📊 TABLES -->
        <div class="apiplatform-card">
            <h3>📊 Tables</h3>

            <?php foreach($tables as $label => $table):
                $exists = ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table);
            ?>
                <p>
                    <?php echo esc_html($label); ?> →
                    <strong style="color:<?php echo $exists?'lime':'red'; ?>">
                        <?php echo $exists ? 'OK' : 'MISSING'; ?>
                    </strong>
                </p>
            <?php endforeach; ?>
        </div>

        <!-- ⚙️ FUNCTIONS -->
        <div class="apiplatform-card">
            <h3>⚙️ Functions</h3>

            <?php foreach($functions as $fn): ?>
                <p>
                    <?php echo esc_html($fn); ?> →
                    <strong style="color:<?php echo function_exists($fn)?'lime':'red'; ?>">
                        <?php echo function_exists($fn) ? 'OK' : 'MISSING'; ?>
                    </strong>
                </p>
            <?php endforeach; ?>
        </div>

        <!-- 📈 STATS -->
        <div class="apiplatform-card">
            <h3>📈 Stats</h3>

            <p>Users → <?php echo esc_html($user_count['total_users']); ?></p>
            <p>APIs → <?php echo esc_html($api_count); ?></p>
        </div>

        <!-- 🧠 ADMIN INFO -->
        <div class="apiplatform-card">
            <h3>🧠 Admin</h3>

            <p>Status →
                <strong>
                    <?php echo get_option('apiplatform_disabled') ? 'Disabled' : 'Active'; ?>
                </strong>
            </p>

            <p>Wallet →
                <strong>
                    <?php echo get_option('apiplatform_use_gamipress') ? 'GamiPress' : 'Internal'; ?>
                </strong>
            </p>
        </div>

        <?php if ($logs_exist): ?>

        <!-- 🔥 LIVE REQUESTS -->
        <div class="apiplatform-card">
            <h3>📡 Recent Requests</h3>

            <?php foreach($recent_logs as $log): ?>
                <p>
                    [<?php echo esc_html($log->status); ?>]
                    <?php echo esc_html($log->endpoint); ?>
                    (<?php echo esc_html($log->response_time); ?>s)
                </p>
            <?php endforeach; ?>
        </div>

        <!-- ❌ ERRORS -->
        <div class="apiplatform-card">
            <h3>❌ Errors</h3>

            <?php if ($errors): foreach($errors as $log): ?>
                <p style="color:red;">
                    [<?php echo esc_html($log->status); ?>]
                    <?php echo esc_html($log->endpoint); ?>
                </p>
            <?php endforeach; else: ?>
                <p>No errors</p>
            <?php endif; ?>
        </div>

        <!-- 🐢 SLOW REQUESTS -->
        <div class="apiplatform-card">
            <h3>🐢 Slow Requests</h3>

            <?php if ($slow): foreach($slow as $log): ?>
                <p style="color:orange;">
                    <?php echo esc_html($log->endpoint); ?> →
                    <?php echo esc_html($log->response_time); ?>s
                </p>
            <?php endforeach; else: ?>
                <p>No slow requests</p>
            <?php endif; ?>
        </div>

        <!-- 🔝 TOP ENDPOINTS -->
        <div class="apiplatform-card">
            <h3>🔥 Top Endpoints</h3>

            <?php foreach($top_endpoints as $row): ?>
                <p>
                    <?php echo esc_html($row->endpoint); ?> →
                    <?php echo esc_html($row->total); ?>
                </p>
            <?php endforeach; ?>
        </div>

        <?php else: ?>

        <div class="apiplatform-card">
            <p>No logs table found</p>
        </div>

        <?php endif; ?>

        <?php
        return ob_get_clean();
    }
}

}