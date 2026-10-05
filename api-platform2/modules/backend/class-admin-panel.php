<?PHP
class APIPlatform_Admin_Panel {

    public function __construct(){
        add_shortcode('api_admin_panel', [$this,'render']);
    }

    public function render(){

        if (!current_user_can('manage_options')) return 'Access denied';

        ob_start(); ?>

        <div class="apiplatform-card">
            <h2>🧠 Admin Panel</h2>

            <p>Users → <?php echo count_users()['total_users']; ?></p>
            <p>APIs → <?php echo wp_count_posts('user_api')->publish ?? 0; ?></p>

            <a href="<?php echo esc_url(add_query_arg('apipage', 'logs', class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))); ?>">View Logs</a><br>
            <a href="<?php echo esc_url(add_query_arg('apipage', 'alerts', class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))); ?>">View Alerts</a>
        </div>

        <?php return ob_get_clean();
    }
}
