<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Plans {

    public function __construct(){

        add_shortcode(
            'api_plans_page',
            [$this, 'render']
        );
    }

    public function render(){

        $content = '';

        $content .= APIPlatform_Renderer::partial(

            'plans/pricing-grid'
        );

        return APIPlatform_Renderer::component(

            'section',

            [
                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [
                        'items' => [
                            '<h1>Plans</h1>',
                            $content
                        ]
                    ]
                )
            ]
        );
    }
}