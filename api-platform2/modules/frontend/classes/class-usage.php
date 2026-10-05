<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Usage {

    public function __construct(){

        add_shortcode(

            'api_usage_page',

            [$this, 'render']
        );
    }

    public function render(){

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

        global $wpdb;

        $table =

            $wpdb->prefix .
            'apiplatform_logs';

        /*
        |--------------------------------------------------------------------------
        | Logs
        |--------------------------------------------------------------------------
        */

        $db_logs = $wpdb->get_results(

            $wpdb->prepare("

                SELECT *

                FROM $table

                WHERE user_id = %d

                ORDER BY created_at DESC

                LIMIT 50

            ", $user_id)
        );

        /*
        |--------------------------------------------------------------------------
        | APIs
        |--------------------------------------------------------------------------
        */

        $apis = get_posts([

            'post_type' => 'user_api',

            'author' => $user_id,

            'numberposts' => -1,

            'no_found_rows' => true
        ]);

        /*
        |--------------------------------------------------------------------------
        | Limits
        |--------------------------------------------------------------------------
        */

        $limits =

            apiplatform_get_plan_limits(

                $user_id
            );

        $limit =

            $limits['request_limit'];

        /*
        |--------------------------------------------------------------------------
        | Usage Service
        |--------------------------------------------------------------------------
        */

        $usage_service =

            APIPlatform_Usage_Service::instance();

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                'Usage Service Loaded'
            );
        }

        $used =

            $usage_service->get_total_usage(

                $user_id
            );

        $format_count = static function($value){
            return is_numeric($value)
                ? number_format((float) $value)
                : '0';
        };

        $limit_is_unlimited = class_exists('APIPlatform_Membership_Panel')
            ? APIPlatform_Membership_Panel::is_unlimited($limit)
            : ($limit == INF);

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                'Usage Total: ' . $used
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Usage Percent
        |--------------------------------------------------------------------------
        */

        $percent =

            function_exists(

                'apiplatform_get_usage_percent'
            )

                ? apiplatform_get_usage_percent(

                    $user_id
                )

                : (

                    $limit == INF

                    ? 0

                    : ($used / max($limit,1)) * 100
                );

        /*
        |--------------------------------------------------------------------------
        | Metric Cards
        |--------------------------------------------------------------------------
        */

        $metric_cards = [

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Requests Used',

                    'value' => $format_count($used),

                    'icon'  => '📡'
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Request Limit',

                    'value' => (

                        $limit_is_unlimited
                        ? '∞'
                        : $format_count($limit)
                    ),

                    'icon' => '⚡'
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'APIs',

                    'value' => count($apis),

                    'icon' => '🔌'
                ]
            )
        ];

        /*
        |--------------------------------------------------------------------------
        | Build Page Content
        |--------------------------------------------------------------------------
        */

        $page_content = '';

        /*
        |--------------------------------------------------------------------------
        | Usage Warning
        |--------------------------------------------------------------------------
        */

        $page_content .= APIPlatform_Renderer::partial(

            'usage/usage-warning',

            [

                'percent' => $percent
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Metrics Grid
        |--------------------------------------------------------------------------
        */

        $page_content .= APIPlatform_Renderer::component(

            'grid',

            [

                'columns' => 3,

                'items' => $metric_cards
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | API Usage Cards
        |--------------------------------------------------------------------------
        */

        foreach($apis as $api){

            $page_content .= APIPlatform_Renderer::partial(

                'usage/api-usage-card',

                [

                    'api' => $api
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Request Logs
        |--------------------------------------------------------------------------
        */

        $page_content .= APIPlatform_Renderer::partial(

            'usage/request-logs',

            [

                'db_logs' => $db_logs
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

                'content' =>

                    APIPlatform_Renderer::component(

                        'stack',

                        [

                            'items' => [

                                '<h1>Usage</h1>',

                                $page_content
                            ]
                        ]
                    )
            ]
        );
    }
}
