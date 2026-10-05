<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Component_Template_Profile {

    public function get_definition(){
        return [
            'name' => 'template-profile',
            'label' => __('Template: User Profile API', 'apiplatform'),
            'description' => __('Builder component converted from the legacy Social Media profile template.', 'apiplatform'),
            'fields' => [
                ['name' => 'username', 'label' => __('Username', 'apiplatform'), 'type' => 'text', 'placeholder' => 'demo_user', 'default' => 'demo_user'],
                ['name' => 'followers', 'label' => __('Followers', 'apiplatform'), 'type' => 'number', 'placeholder' => '1000', 'default' => '1000'],
                ['name' => 'bio', 'label' => __('Bio', 'apiplatform'), 'type' => 'textarea', 'placeholder' => 'Demo user', 'default' => 'Demo user'],
            ],
            'output' => [
                'username' => '{{username}}',
                'followers' => '{{followers}}',
                'bio' => '{{bio}}',
            ],
        ];
    }
}
