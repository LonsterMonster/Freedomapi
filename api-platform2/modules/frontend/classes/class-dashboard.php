<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Dashboard {

    public function __construct(){

        add_shortcode(

            'api_dashboard_page',

            [$this, 'render']
        );
    }

    public function render(){

        /*
        |--------------------------------------------------------------------------
        | Assets
        |--------------------------------------------------------------------------
        */

        APIPlatform_Frontend_Assets::enqueue_common();

        /*
        |--------------------------------------------------------------------------
        | Auth Check
        |--------------------------------------------------------------------------
        */

        if (!is_user_logged_in()) {

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Login required'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | User
        |--------------------------------------------------------------------------
        */

        $user_id = get_current_user_id();

        /*
        |--------------------------------------------------------------------------
        | APIs
        |--------------------------------------------------------------------------
        */

        $api_ids = class_exists('APIPlatform_Organization_Permissions')
            ? APIPlatform_Organization_Permissions::user_api_ids($user_id, 'analytics.view')
            : [];
        $apis = get_posts([
            'post_type' => 'user_api',
            'post__in' => $api_ids ?: [0],
            'numberposts' => -1,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'no_found_rows' => true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Usage
        |--------------------------------------------------------------------------
        */

        $total_requests = $this->get_total_requests(

            $user_id,

            $apis
        );

        $active_apis = $this->count_active_apis(

            $apis
        );

        $activity = $this->prepare_recent_activity(

            $apis
        );

        $recent_requests = $this->prepare_recent_requests(

            $user_id,

            $apis
        );

        $roadmap = $this->prepare_feature_roadmap(

            $user_id
        );

        /*
        |--------------------------------------------------------------------------
        | Debug
        |--------------------------------------------------------------------------
        */

        $this->debug_log(

            'DASHBOARD LOADED API COUNT: ' .

            count($apis)
        );

        $this->debug_log(

            'DASHBOARD USAGE TOTALS: ' .

            $total_requests
        );

        $this->debug_log(

            'DASHBOARD RENDERING FOR USER: ' .

            $user_id
        );

        if (empty($apis)){

            $this->debug_log(

                'DASHBOARD EMPTY STATE FOR USER: ' .

                $user_id
            );
        }

        if (empty($recent_requests)){

            $this->debug_log(

                'DASHBOARD EMPTY RECENT REQUESTS FOR USER: ' .

                $user_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Stats
        |--------------------------------------------------------------------------
        */

        $stats = [

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Total APIs',

                    'value' => count($apis),

                    'icon' => 'API'
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Total Requests',

                    'value' => number_format($total_requests),

                    'icon' => 'REQ'
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Active APIs',

                    'value' => $active_apis,

                    'icon' => 'ON'
                ]
            )
        ];

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = '';

        /*
        |--------------------------------------------------------------------------
        | Stats Grid
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::component(

            'grid',

            [

                'columns' => 3,

                'items' => $stats
            ]
        );

        $content .= APIPlatform_Renderer::component(

            'card',

            [

                'class' => 'apiplatform-dashboard-request-history-card',

                'content' => APIPlatform_Renderer::component(

                    'button',

                    [

                        'type' => 'secondary',

                        'label' => 'Request History',

                        'url' => $this->request_history_url(),

                        'class' => 'apiplatform-request-history-link'
                    ]
                )
            ]
        );

        $content .= APIPlatform_Renderer::partial(

            'dashboard/feature-roadmap',

            [

                'roadmap' => $roadmap
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        if (empty($apis)){

            $content .= APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'info',

                    'content' => 'No APIs yet. Create your first API to start tracking requests.'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Activity
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'dashboard/recent-activity',

            [

                'activity' => $activity
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Recent Requests
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'dashboard/recent-requests',

            [

                'requests' => $recent_requests
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Final Layout
        |--------------------------------------------------------------------------
        */

        return APIPlatform_Renderer::component(

            'section',

            [

                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [

                        'items' => [

                            '<h1>Dashboard</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );
    }

    private function get_total_requests($user_id, $apis){

        $total = 0;

        if (class_exists('APIPlatform_Usage_Service')){

            $total = APIPlatform_Usage_Service::instance()
                ->get_total_usage($user_id);
        }

        if (!$total && function_exists('apiplatform_get_total_usage')){

            $total = apiplatform_get_total_usage(

                $user_id
            );
        }

        if (!$total){

            foreach($apis as $api){

                $total += (int) get_post_meta(

                    $api->ID,

                    'api_usage',

                    true
                );
            }
        }

        return (int) $total;
    }

    private function count_active_apis($apis){

        $active = 0;

        foreach($apis as $api){

            $status = $this->get_api_status(

                $api
            );

            if (strtolower($status) === 'active'){

                $active++;
            }
        }

        return $active;
    }

    private function prepare_recent_activity($apis){

        $activity = [];

        foreach($apis as $api){

            $activity[] = [

                'api_name' => get_the_title($api),

                'status' => $this->get_api_status($api),

                'requests' => (int) get_post_meta(

                    $api->ID,

                    'api_usage',

                    true
                ),

                'last_activity' => $this->get_last_activity(

                    $api->ID
                )
            ];
        }

        usort(

            $activity,

            function($a, $b){

                return (int) $b['requests'] <=> (int) $a['requests'];
            }
        );

        return array_slice(

            $activity,

            0,

            10
        );
    }

    private function prepare_recent_requests($user_id, $apis){

        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)){

            $this->debug_log(

                'DASHBOARD RECENT REQUESTS TABLE MISSING'
            );

            return [];
        }

        $api_names = [];

        foreach($apis as $api){

            $api_names[(int) $api->ID] = get_the_title($api);
        }

        $logs = $wpdb->get_results(

            $wpdb->prepare(

                "SELECT api_id, endpoint, method, status, response_time, created_at FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC LIMIT 10",

                $user_id
            )
        );

        if (empty($logs)){

            return [];
        }

        $requests = [];

        foreach($logs as $log){

            $api_id = (int) ($log->api_id ?? 0);

            $requests[] = [

                'api_name' => $api_names[$api_id] ?? (

                    $log->endpoint

                        ? ucwords(str_replace('-', ' ', $log->endpoint))

                        : 'Unknown API'
                ),

                'endpoint' => $log->endpoint ?? '',

                'method' => $log->method ?? '',

                'status' => $log->status ?? '',

                'response_time' => $log->response_time ?? '',

                'created_at' => $log->created_at ?? ''
            ];
        }

        $this->debug_log(

            'DASHBOARD RECENT REQUESTS LOADED: ' .

            count($requests)
        );

        return $requests;
    }

    private function prepare_feature_roadmap($user_id){

        if (!function_exists('freedom_api_get_features')){

            return [

                'available' => [],

                'coming_soon' => [],

                'released' => []
            ];
        }

        $roadmap = [

            'available' => [],

            'coming_soon' => [],

            'released' => []
        ];

        foreach(freedom_api_get_features() as $feature){

            $available = function_exists('freedom_api_has_feature')

                ? freedom_api_has_feature($user_id, $feature['slug'])

                : false;

            $status = strtolower((string) ($feature['status'] ?? 'coming_soon'));

            if ($available){

                $feature['display_status'] = $status === 'released'

                    ? 'Released'

                    : 'Available';

                $roadmap[$status === 'released' ? 'released' : 'available'][] = $feature;

                continue;
            }

            $feature['display_status'] = ($feature['plan'] ?? 'free') !== 'free'

                ? 'Premium'

                : 'Coming Soon';

            $roadmap['coming_soon'][] = $feature;
        }

        return $roadmap;
    }

    private function get_api_status($api){

        $status = get_post_meta(

            $api->ID,

            'apiplatform_status',

            true
        );

        if (!$status){

            $status = $api->post_status === 'publish'

                ? 'active'

                : $api->post_status;
        }

        return ucwords(

            str_replace(

                ['-', '_'],

                ' ',

                $status
            )
        );
    }

    private function get_last_activity($api_id){

        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)){

            return '';
        }

        return (string) $wpdb->get_var(

            $wpdb->prepare(

                "SELECT created_at FROM `{$table}` WHERE api_id = %d ORDER BY created_at DESC LIMIT 1",

                $api_id
            )
        );
    }

    private function table_exists($table){

        global $wpdb;

        return $wpdb->get_var(

            $wpdb->prepare(

                'SHOW TABLES LIKE %s',

                $table
            )
        ) === $table;
    }

    private function request_history_url(){

        return current_user_can('manage_options')

            ? add_query_arg('apipage', 'request-history', (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))

            : site_url('/request-history');
    }

    private function debug_log($message){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log($message);
        }
    }
}
