<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Assets {

    public function __construct(){
        add_action('wp_enqueue_scripts', [$this, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles'], 20);
    }

    public function register_assets(){
        self::register_asset_handles();
    }

    public function enqueue_frontend_styles(){
        self::enqueue_common();
    }

    private static function register_asset_handles(){
        $base_url = self::asset_url();
		wp_register_script(
			'apiplatform-realtime-chart',
			$base_url . 'js/realtime-chart.js',
			['apiplatform-chartjs'],
			self::asset_version('js/realtime-chart.js'),
			true
		);
		wp_register_style(
			'apiplatform-plans',
			$base_url . 'css/plans.css',
			[],
			self::asset_version('css/plans.css')
		);
		wp_register_script(
			'apiplatform-profile-chart',
			$base_url . 'js/profile-chart.js',
			['apiplatform-chartjs'],
			self::asset_version('js/profile-chart.js'),
			true
		);
        wp_register_style(
            'apiplatform-frontend',
            $base_url . 'css/frontend.css',
            [],
            self::asset_version('css/frontend.css')
        );

        wp_register_style(
            'apiplatform-dashboard-style',
            $base_url . 'css/dashboard.css',
            [],
            self::asset_version('css/dashboard.css')
        );

        wp_register_style(
            'apiplatform-sidebar',
            APIPLATFORM_URL . 'modules/assets/css/sidebar.css',
            [],
            apiplatform_asset_version('modules/assets/css/sidebar.css')
        );

        wp_register_script(
            'apiplatform-sidebar',
            APIPLATFORM_URL . 'modules/assets/js/sidebar.js',
            [],
            apiplatform_asset_version('modules/assets/js/sidebar.js'),
            true
        );

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG &&

            !file_exists(self::asset_path() . 'css/frontend.css')
        ){

            error_log(

                'APIPLATFORM FRONTEND CSS FILE MISSING'
            );
        }

        wp_register_script(
            'apiplatform-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            null,
            true
        );

        wp_register_script(
            'apiplatform-dashboard',
            $base_url . 'js/dashboard.js',
            ['apiplatform-chartjs'],
            self::asset_version('js/dashboard.js'),
            true
        );

        wp_register_script(
            'apiplatform-api-test',
            $base_url . 'js/api-test.js',
            [],
            self::asset_version('js/api-test.js'),
            true
        );

        wp_register_script(
            'apiplatform-create-api',
            $base_url . 'js/create-api.js',
            [],
            self::asset_version('js/create-api.js'),
            true
        );

        wp_register_script(
            'apiplatform-keys',
            $base_url . 'js/keys.js',
            [],
            self::asset_version('js/keys.js'),
            true
        );

        wp_register_script(
            'apiplatform-documentation',
            $base_url . 'js/documentation.js',
            [],
            self::asset_version('js/documentation.js'),
            true
        );

        wp_register_style(
            'apiplatform-api-tester',
            $base_url . 'css/api-tester.css',
            ['apiplatform-frontend'],
            self::asset_version('css/api-tester.css')
        );

        wp_register_script(
            'apiplatform-api-tester',
            $base_url . 'js/api-tester.js',
            [],
            self::asset_version('js/api-tester.js'),
            true
        );

        wp_register_style(
            'apiplatform-api-documentation',
            $base_url . 'css/api-documentation.css',
            ['apiplatform-frontend'],
            self::asset_version('css/api-documentation.css')
        );

        wp_register_script(
            'apiplatform-api-documentation',
            $base_url . 'js/api-documentation.js',
            [],
            self::asset_version('js/api-documentation.js'),
            true
        );

        wp_register_style(
            'apiplatform-request-history',
            $base_url . 'css/request-history.css',
            ['apiplatform-frontend'],
            self::asset_version('css/request-history.css')
        );

        wp_register_script(
            'apiplatform-request-history',
            $base_url . 'js/request-history.js',
            [],
            self::asset_version('js/request-history.js'),
            true
        );
    }

    public static function enqueue_common(){
        if (!wp_style_is('apiplatform-frontend', 'registered')) {
            self::register_asset_handles();
        }

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG &&

            !wp_style_is('apiplatform-frontend', 'registered')
        ){

            error_log(

                'APIPLATFORM FRONTEND CSS HANDLE NOT REGISTERED'
            );
        }

        wp_enqueue_style('apiplatform-frontend');
        wp_enqueue_style('apiplatform-sidebar');
        wp_enqueue_style('dashicons');
        wp_enqueue_script('apiplatform-sidebar');

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG &&

            !wp_style_is('apiplatform-frontend', 'enqueued')
        ){

            error_log(

                'APIPLATFORM FRONTEND CSS HANDLE NOT ENQUEUED'
            );
        }
    }

    public static function enqueue_dashboard(array $chart_data){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-dashboard', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_script('apiplatform-chartjs');
        wp_enqueue_style('apiplatform-dashboard-style');
        wp_enqueue_script('apiplatform-dashboard');
        wp_enqueue_script('apiplatform-api-test');

        wp_localize_script('apiplatform-dashboard', 'APIPlatformDashboardData', [
            'labels' => array_values($chart_data['labels'] ?? []),
            'values' => array_values($chart_data['values'] ?? []),
        ]);
    }

    public static function enqueue_create(){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-create-api', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_style('apiplatform-dashboard-style');
        wp_enqueue_script('apiplatform-create-api');
    }

    public static function enqueue_keys(){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-keys', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_script('apiplatform-keys');

        wp_add_inline_script(

            'apiplatform-keys',

            'window.APIPlatformKeysDebug = ' .

                (

                    defined('WP_DEBUG') &&

                    WP_DEBUG

                        ? 'true'

                        : 'false'
                ) .

                ';',

            'before'
        );

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                '[COPY ACTION] Copy initialized'
            );
        }
    }

    public static function enqueue_documentation(){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-keys', 'registered') || !wp_script_is('apiplatform-documentation', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_script('apiplatform-keys');
        wp_enqueue_script('apiplatform-documentation');

        wp_add_inline_script(
            'apiplatform-keys',
            'window.APIPlatformKeysDebug = ' .
                (
                    defined('WP_DEBUG') &&
                    WP_DEBUG
                        ? 'true'
                        : 'false'
                ) .
                ';',
            'before'
        );

        if (
            defined('WP_DEBUG') &&
            WP_DEBUG
        ){
            error_log('[DOCS PAGE] Documentation assets initialized');
            error_log('[COPY ACTION] Copy initialized');
        }
    }

    public static function enqueue_api_tester(array $config = []){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-api-tester', 'registered') || !wp_style_is('apiplatform-api-tester', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_style('apiplatform-api-tester');
        wp_enqueue_script('apiplatform-keys');
        wp_enqueue_script('apiplatform-api-tester');

        wp_localize_script('apiplatform-api-tester', 'APIPlatformTester', array_merge([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'apiId' => 0,
            'nonce' => '',
            'endpoint' => '',
            'methods' => ['GET'],
            'apiName' => '',
        ], $config));
    }

    public static function enqueue_api_documentation(){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-api-documentation', 'registered') || !wp_style_is('apiplatform-api-documentation', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_style('apiplatform-api-documentation');
        wp_enqueue_script('apiplatform-keys');
        wp_enqueue_script('apiplatform-api-documentation');
    }

    public static function enqueue_request_history(){
        self::enqueue_common();

        if (!wp_script_is('apiplatform-request-history', 'registered') || !wp_style_is('apiplatform-request-history', 'registered')) {
            self::register_asset_handles();
        }

        wp_enqueue_style('apiplatform-request-history');
        wp_enqueue_script('apiplatform-keys');
        wp_enqueue_script('apiplatform-request-history');
    }

	private static function asset_url(){
		return APIPLATFORM_URL . 'modules/frontend/assets/';
	}

    private static function asset_path(){
        return APIPLATFORM_PATH . 'modules/frontend/assets/';
    }

    private static function asset_version($relative_path){
        return apiplatform_asset_version('modules/frontend/assets/' . ltrim((string) $relative_path, '/\\'));
    }
}
