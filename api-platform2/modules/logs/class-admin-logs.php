<?php
class APIPlatform_Admin_Logs {

    public function __construct(){
        if (!class_exists('APIPlatform_Admin_Menu')) {
            add_action('admin_menu', [$this,'menu']);
        }
    }

    public function menu(){
        add_submenu_page(
            'apiplatform',
            'API Logs',
            'API Logs',
            'manage_options',
            'apiplatform-logs',
            [$this,'page']
        );
    }

    public function page(){

        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_logs';

        $user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

        $query = "SELECT * FROM $table";

        if ($user_filter){
            $query .= $wpdb->prepare(" WHERE user_id = %d", $user_filter);
        }

        $query .= " ORDER BY created_at DESC LIMIT 100";

        $logs = $wpdb->get_results($query);

        echo "<h1>API Logs</h1>";

        echo '<form method="get">
            <input type="hidden" name="page" value="apiplatform-logs">
            <input type="number" name="user_id" placeholder="User ID">
            <button>Filter</button>
        </form>';

        echo "<table class='widefat'>";
        echo "<tr><th>User</th><th>API</th><th>Status</th><th>Time</th></tr>";

        foreach($logs as $log){
            echo "<tr>
                <td>{$log->user_id}</td>
                <td>{$log->endpoint}</td>
                <td>{$log->status}</td>
                <td>{$log->created_at}</td>
            </tr>";
        }

        echo "</table>";
    }
}
