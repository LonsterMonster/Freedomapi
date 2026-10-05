<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Bans {

    public function __construct(){
        if (!class_exists('APIPlatform_Admin_Menu')) {
            add_action('admin_menu', [$this,'menu']);
        }
    }

    public function menu(){
        add_submenu_page(
            'apiplatform',
            'Bans',
            'Bans',
            'manage_options',
            'apiplatform-bans',
            [$this,'page']
        );
    }

    public function page(){

        if (!current_user_can('manage_options')) {
            echo '<div class="wrap"><p>Access denied.</p></div>';
            return;
        }

        echo '<div class="wrap"><h1>Ban Management</h1>';

        // UNBAN USER
        if (isset($_GET['unban_user'])){
            check_admin_referer('apiplatform_unban_user');

            $user_id = absint($_GET['unban_user']);
            if ($user_id) {
                delete_user_meta($user_id, 'apiplatform_banned');
                echo '<div class="notice notice-success"><p>User unbanned.</p></div>';
            }
        }

        // UNBAN IP
        if (isset($_GET['unban_ip'])){
            check_admin_referer('apiplatform_unban_ip');

            $ip = sanitize_text_field(wp_unslash($_GET['unban_ip']));
            if ($ip !== '') {
                delete_transient('apiplatform_ban_ip_' . $ip);
                echo '<div class="notice notice-success"><p>IP address unbanned.</p></div>';
            }
        }

        // USERS
        $users = get_users([
            'meta_key'   => 'apiplatform_banned',
            'meta_value' => 1,
        ]);

        echo '<h2>Banned Users</h2>';

        if (empty($users)) {
            echo '<p>No banned users.</p>';
        } else {
            foreach($users as $user){

                $unban_url = wp_nonce_url(
                    add_query_arg([
                        'page' => 'apiplatform-bans',
                        'unban_user' => $user->ID,
                    ], admin_url('admin.php')),
                    'apiplatform_unban_user'
                );

                echo '<p>';
                echo esc_html($user->user_login);
                echo ' <a href="' . esc_url($unban_url) . '">Unban</a>';
                echo '</p>';
            }
        }

        echo '<h2>Banned IPs</h2>';

        global $wpdb;

        $ips = [];
        $logs_table = $wpdb->prefix . 'apiplatform_logs';
        $logs_exist = ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") === $logs_table);

        if ($logs_exist) {
            $logged_ips = $wpdb->get_col("
                SELECT DISTINCT ip
                FROM $logs_table
                WHERE ip <> ''
                ORDER BY id DESC
                LIMIT 500
            ");

            foreach ($logged_ips as $ip) {
                $ips[$ip] = $ip;
            }
        }

        $option_like = $wpdb->esc_like('_transient_apiplatform_ban_ip_') . '%';
        $transient_options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $option_like
            )
        );

        foreach ($transient_options as $option_name) {
            $ip = substr($option_name, strlen('_transient_apiplatform_ban_ip_'));
            if ($ip !== '') {
                $ips[$ip] = $ip;
            }
        }

        $banned_ips = [];

        foreach($ips as $ip){

            $ip = sanitize_text_field($ip);

            if ($ip !== '' && get_transient('apiplatform_ban_ip_' . $ip)){
                $banned_ips[] = $ip;
            }
        }

        if (empty($banned_ips)) {
            echo '<p>No banned IP addresses.</p>';
        } else {
            foreach ($banned_ips as $ip) {
                $unban_url = wp_nonce_url(
                    add_query_arg([
                        'page' => 'apiplatform-bans',
                        'unban_ip' => $ip,
                    ], admin_url('admin.php')),
                    'apiplatform_unban_ip'
                );

                echo '<p>';
                echo esc_html($ip);
                echo ' <a href="' . esc_url($unban_url) . '">Unban</a>';
                echo '</p>';
            }
        }

        echo '</div>';
    }
}
