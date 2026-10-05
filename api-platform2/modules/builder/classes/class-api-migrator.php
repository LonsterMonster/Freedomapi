<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_API_Migrator {

    public function __construct(){
        // Intentionally empty. Migration can be triggered later from an admin tool or WP-CLI command.
    }

    public function migrate_old_api($api_id){
        $api_id = absint($api_id);
        if (!$api_id) {
            return null;
        }

        $old_logic_json = get_post_meta($api_id, 'api_logic', true);
        $old_logic = apiplatform_builder_decode_json($old_logic_json, []);

        if (apiplatform_builder_is_component_tree($old_logic)) {
            return $old_logic;
        }

        if (empty($old_logic['steps']) || !is_array($old_logic['steps'])) {
            return null;
        }

        $component_tree = [
            'version' => 1,
            'components' => [],
        ];

        foreach ($old_logic['steps'] as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'response' || !isset($step['data']) || !is_array($step['data'])) {
                continue;
            }

            $component_tree['components'][] = $this->response_data_to_component($step['data']);
        }

        return empty($component_tree['components']) ? null : $component_tree;
    }

    private function response_data_to_component($data){
        if (isset($data['username']) || isset($data['followers']) || isset($data['bio'])) {
            return [
                'type' => 'user-card',
                'props' => [
                    'username'  => (string)($data['username'] ?? ''),
                    'followers' => (string)($data['followers'] ?? ''),
                    'bio'       => (string)($data['bio'] ?? ''),
                ],
            ];
        }

        if (isset($data['product']) || isset($data['price'])) {
            return [
                'type' => 'product-card',
                'props' => [
                    'product' => (string)($data['product'] ?? ''),
                    'price'   => (string)($data['price'] ?? ''),
                ],
            ];
        }

        return [
            'type' => 'message',
            'props' => [
                'content' => wp_json_encode($data),
            ],
        ];
    }
}
