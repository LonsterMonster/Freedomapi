<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Keys {

    public function __construct(){
        if (!class_exists('APIPlatform_Admin_Menu')) {
            add_action('admin_menu', [$this,'menu']);
        }
    }

    public function menu(){
        add_submenu_page(
            'apiplatform',
            'Key Analytics',
            'Key Analytics',
            'manage_options',
            'apiplatform-keys',
            [$this,'page']
        );
    }

    public function page(){

		echo '<div class="wrap"><h1>Key Usage Analytics</h1>';

		if (!class_exists('APIPlatform_Key_Service')) {
			echo '<p>Key service is not loaded.</p></div>';
			return;
		}

		wp_enqueue_script(
			'apiplatform-chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js',
			[],
			null,
			true
		);

		$stats = APIPlatform_Key_Service::instance()->get_usage_stats(100);

		if (empty($stats)) {
			echo '<p>No API key usage has been recorded yet.</p></div>';
			return;
		}

		$labels = array_map(function($row){
			return $row['api_name'] . ' - ' . $row['key_label'];
		}, $stats);

		$data = array_map(function($row){
			return (int) $row['usage'];
		}, $stats);

		wp_add_inline_script(
			'apiplatform-chartjs',
			'window.addEventListener("DOMContentLoaded", function(){var canvas=document.getElementById("apiChart");if(!canvas||typeof Chart==="undefined"){return;}new Chart(canvas,{type:"bar",data:{labels:' . wp_json_encode($labels) . ',datasets:[{label:"API Key Usage",data:' . wp_json_encode($data) . '}]}});});',
			'after'
		);

		?>

		<canvas id="apiChart" height="100"></canvas>

		<table class="widefat striped" style="margin-top:15px;">
			<thead>
				<tr>
					<th>API</th>
					<th>Key</th>
					<th>Usage</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($stats as $row): ?>
					<tr>
						<td><?php echo esc_html($row['api_name']); ?></td>
						<td><?php echo esc_html($row['key_label']); ?></td>
						<td><?php echo esc_html($row['usage']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php

		echo '</div>';
	}
}
