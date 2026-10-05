<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Profile {

    public function __construct(){

        add_shortcode(

            'api_profile_page',

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
        | Current User
        |--------------------------------------------------------------------------
        */

        $user_id = get_current_user_id();

        $user = get_userdata($user_id);

        $notice = '';

        /*
        |--------------------------------------------------------------------------
        | Save Profile
        |--------------------------------------------------------------------------
        */

        if (

            isset($_POST['apiplatform_save_profile'])
        ){

            if (

                !isset($_POST['apiplatform_profile_nonce']) ||

                !wp_verify_nonce(

                    sanitize_text_field(

                        wp_unslash($_POST['apiplatform_profile_nonce'])
                    ),

                    'apiplatform_save_profile'
                )
            ){

                $notice = APIPlatform_Renderer::component(

                    'alert',

                    [

                        'type' => 'error',

                        'content' => 'Security check failed.'
                    ]
                );
            } else {

                $display_name = isset($_POST['display_name'])

                    ? sanitize_text_field(

                        wp_unslash($_POST['display_name'])
                    )

                    : '';

                $email = isset($_POST['user_email'])

                    ? sanitize_email(

                        wp_unslash($_POST['user_email'])
                    )

                    : '';

                if (!is_email($email)){

                    $result = new WP_Error(

                        'invalid_email',

                        'Please enter a valid email address.'
                    );
                } else {

                    $result = wp_update_user([

                        'ID' => $user_id,

                        'display_name' => $display_name,

                        'user_email' => $email
                    ]);
                }

                if (is_wp_error($result)){

                    $notice = APIPlatform_Renderer::component(

                        'alert',

                        [

                            'type' => 'error',

                            'content' => $result->get_error_message()
                        ]
                    );
                } else {

                    $notice = APIPlatform_Renderer::component(

                        'alert',

                        [

                            'type' => 'success',

                            'content' => 'Profile updated successfully.'
                        ]
                    );

                    $user = get_userdata($user_id);
                }

                if (

                    defined('WP_DEBUG') &&

                    WP_DEBUG
                ){

                    error_log(

                        'PROFILE UPDATED USER: ' .

                        $user_id
                    );
                }
            }
        }

        $profile = [

            'display_name' => $user->display_name ?? '',

            'user_email' => $user->user_email ?? ''
        ];

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

                'PROFILE PAGE USER: ' .

                ($user->user_login ?? 'missing')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = '';

        $content .= $notice;

        /*
        |--------------------------------------------------------------------------
        | User Card
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'profile/user-card',

            [

                'user' => $user,

                'profile' => $profile
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Account Security
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'profile/account-security',

            [

                'user' => $user,

                'profile' => $profile
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Final Render
        |--------------------------------------------------------------------------
        */

        return APIPlatform_Renderer::component(

            'section',

            [

                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [

                        'items' => [

                            '<h1>Profile</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );
    }
}
