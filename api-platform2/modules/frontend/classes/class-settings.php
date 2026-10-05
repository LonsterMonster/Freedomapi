<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Settings {

    public function __construct(){

        add_shortcode(

            'api_settings_page',

            [$this, 'render']
        );

        add_action(

            'wp_enqueue_scripts',

            [$this, 'enqueue_assets']
        );
    }

    public function enqueue_assets(){

        /*
        |--------------------------------------------------------------------------
        | Settings Page Detection
        |--------------------------------------------------------------------------
        */

        $query_page = isset($_GET['apipage'])

            ? sanitize_key(

                wp_unslash($_GET['apipage'])
            )

            : '';

        $path_page = isset($_SERVER['REQUEST_URI'])

            ? basename(

                parse_url(

                    sanitize_text_field(

                        wp_unslash($_SERVER['REQUEST_URI'])
                    ),

                    PHP_URL_PATH
                )
            )

            : '';

        $path_page = sanitize_key($path_page);

        if (

            $query_page === 'settings' ||

            $path_page === 'settings' ||

            $this->post_has_settings_shortcode()
        ){

            APIPlatform_Frontend_Assets::enqueue_common();

            if (

                defined('WP_DEBUG') &&

                WP_DEBUG
            ){

                error_log(

                    'SETTINGS FRONTEND CSS ENQUEUED'
                );
            }
        }
    }

    private function post_has_settings_shortcode(){

        global $post;

        return (

            $post instanceof WP_Post &&

            (

                has_shortcode(

                    $post->post_content,

                    'api_settings_page'
                ) ||

                has_shortcode(

                    $post->post_content,

                    'api_fulllayout_page'
                )
            )
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
        | Current User
        |--------------------------------------------------------------------------
        */

        $user_id = get_current_user_id();

        $user = get_userdata($user_id);

        $success_notice = '';

        /*
        |--------------------------------------------------------------------------
        | Save Settings
        |--------------------------------------------------------------------------
        */

        if (

            isset($_POST['apiplatform_save_settings'])
        ){

            if (

                !isset(

                    $_POST['apiplatform_settings_nonce']
                )

                ||

                !wp_verify_nonce(

                    sanitize_text_field(

                        wp_unslash(

                            $_POST['apiplatform_settings_nonce']
                        )
                    ),

                    'apiplatform_save_settings'
                )
            ){

                return APIPlatform_Renderer::component(

                    'alert',

                    [

                        'type' => 'error',

                        'content' => 'Security check failed.'
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Theme
            |--------------------------------------------------------------------------
            */

            $theme = (

                isset($_POST['theme']) &&

                sanitize_text_field(

                    wp_unslash($_POST['theme'])
                ) === 'dark'
            )

                ? 'dark'

                : 'light';

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            $notifications = (

                isset($_POST['notifications']) &&

                sanitize_text_field(

                    wp_unslash($_POST['notifications'])
                ) === 'enabled'
            )

                ? 'enabled'

                : 'disabled';

            /*
            |--------------------------------------------------------------------------
            | Save User Meta
            |--------------------------------------------------------------------------
            */

            update_user_meta(

                $user_id,

                'apiplatform_theme',

                $theme
            );

            update_user_meta(

                $user_id,

                'apiplatform_notifications',

                $notifications
            );

            $success_notice = APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'success',

                    'content' => 'Settings saved successfully.'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Debug
            |--------------------------------------------------------------------------
            */

            if (

                defined('WP_DEBUG') &&

                WP_DEBUG
            ){

                error_log(

                    'SETTINGS SAVED'
                );

                error_log(

                    'Theme: ' . $theme
                );

                error_log(

                    'Notifications: ' . $notifications
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Load Settings
        |--------------------------------------------------------------------------
        */

        $theme = get_user_meta(

            $user_id,

            'apiplatform_theme',

            true
        );

        $notifications = get_user_meta(

            $user_id,

            'apiplatform_notifications',

            true
        );

        /*
        |--------------------------------------------------------------------------
        | Defaults
        |--------------------------------------------------------------------------
        */

        if (!$theme){

            $theme = 'dark';
        }

        if (!$notifications){

            $notifications = 'enabled';
        }

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = $success_notice ?? '';

        /*
        |--------------------------------------------------------------------------
        | General Settings
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'settings/general',

            [

                'user' => $user,

                'theme' => $theme,

                'notifications' => $notifications
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Preferences
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'settings/api-preferences',

            [

                'user_id' => $user_id
            ]
        );

        if (shortcode_exists('apiplatform_connected_accounts')) {
            $content .= do_shortcode('[apiplatform_connected_accounts]');
        }

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

                            '<h1>Settings</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );
    }
}
