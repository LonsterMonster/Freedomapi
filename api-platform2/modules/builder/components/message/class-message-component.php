<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Component_Message {

    public function get_definition(){
        return [
            'name' => 'message',
            'label' => __('Message', 'apiplatform'),
            'description' => __('A simple response block for status, notification, or freeform text responses.', 'apiplatform'),
            'fields' => [
                ['name' => 'content', 'label' => __('Content', 'apiplatform'), 'type' => 'textarea', 'placeholder' => 'Request completed successfully.'],
                ['name' => 'status', 'label' => __('Status', 'apiplatform'), 'type' => 'text', 'placeholder' => 'success'],
            ],
            'output' => [
                'type' => 'message',
                'status' => '{{status}}',
                'message' => '{{content}}',
            ],
        ];
    }
}
