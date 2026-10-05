<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Component_User_Card {

    public function get_definition(){
        return [
            'name' => 'user-card',
            'label' => __('User Card', 'apiplatform'),
            'description' => __('A reusable response block for user profile data.', 'apiplatform'),
            'fields' => [
                ['name' => 'username', 'label' => __('Username', 'apiplatform'), 'type' => 'text', 'placeholder' => 'John'],
                ['name' => 'followers', 'label' => __('Followers', 'apiplatform'), 'type' => 'number', 'placeholder' => '1000'],
                ['name' => 'bio', 'label' => __('Bio', 'apiplatform'), 'type' => 'textarea', 'placeholder' => 'Demo user'],
            ],
            'output' => [
                'type' => 'user',
                'username' => '{{username}}',
                'followers' => '{{followers}}',
                'bio' => '{{bio}}',
            ],
        ];
    }
}
