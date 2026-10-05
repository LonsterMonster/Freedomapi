<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Team {

    public function __construct(){

        add_shortcode(
            'api_team_page',
            [$this, 'render']
        );
    }

    public function render(){

        $content = '';

        $content .= APIPlatform_Renderer::partial(

            'team/member-list'
        );

        $content .= APIPlatform_Renderer::partial(

            'team/invitations'
        );

        return APIPlatform_Renderer::component(

            'section',

            [
                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [
                        'items' => [
                            '<h1>Team</h1>',
                            $content
                        ]
                    ]
                )
            ]
        );
    }
}