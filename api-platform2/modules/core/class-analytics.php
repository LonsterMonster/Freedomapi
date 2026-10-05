<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Analytics {

    public function __construct(){
        add_action('admin_menu', [$this,'menu']);
    }

    public function menu(){
        add_submenu_page(
            'apiplatform',
            'Analytics',
            'Analytics',
            'manage_options',
            'apiplatform-analytics',
            [$this,'page']
        );
    }

    public function page(){

        global $wpdb;

        $logs_table = $wpdb->prefix . 'apiplatform_logs';

        wp_enqueue_script(
            'apiplatform-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            null,
            true
        );

        echo '<div class="wrap"><h1>API Analytics</h1>';

        $top = $wpdb->get_results("
            SELECT api_id, COUNT(*) as total
            FROM $logs_table
            GROUP BY api_id
            ORDER BY total DESC
            LIMIT 5
        ");

        $api_names = [];
        $api_ids = array_values(array_filter(array_map(function($row){
            return absint($row->api_id);
        }, $top)));

        if (!empty($api_ids)) {
            $api_posts = get_posts([
                'post_type'      => 'user_api',
                'post_status'    => 'any',
                'post__in'       => $api_ids,
                'numberposts'    => count($api_ids),
                'no_found_rows'  => true,
            ]);

            foreach ($api_posts as $api_post) {
                $api_names[$api_post->ID] = get_the_title($api_post);
            }
        }

        $results = $wpdb->get_results("
            SELECT DATE(created_at) as day, COUNT(*) as total
            FROM $logs_table
            GROUP BY day
            ORDER BY day ASC
            LIMIT 30
        ");

        $labels = [];
        $data   = [];

        foreach($results as $r){
            $labels[] = $r->day;
            $data[]   = (int)$r->total;
        }

        wp_add_inline_script(
            'apiplatform-chartjs',
            'window.addEventListener("DOMContentLoaded", function(){var canvas=document.getElementById("usageChart");if(!canvas||typeof Chart==="undefined"){return;}new Chart(canvas,{type:"line",data:{labels:' . wp_json_encode($labels) . ',datasets:[{label:"Requests per Day",data:' . wp_json_encode($data) . '}]}});});',
            'after'
        );
        ?>

        <canvas id="usageChart" height="100"></canvas>

        <h2>Top APIs</h2>

        <?php if (empty($top)): ?>
            <p>No API usage has been recorded yet.</p>
        <?php else: ?>
            <?php foreach($top as $row): ?>
                <?php
                $api_id = absint($row->api_id);
                $api_name = $api_names[$api_id] ?? ('API #' . $api_id);
                ?>
                <p><?php echo esc_html($api_name); ?> &rarr; <?php echo esc_html((int) $row->total); ?> calls</p>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php
        echo '</div>';
    }
}
