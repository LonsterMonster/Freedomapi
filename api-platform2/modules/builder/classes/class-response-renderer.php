<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Response_Renderer {

    public function __construct(){
        // Intentionally empty. This class is safe for the existing module auto-instantiator.
    }

    public function render_response($component_tree, $request_data = []){
        if (is_string($component_tree)) {
            $component_tree = apiplatform_builder_decode_json($component_tree, []);
        }

        if (!apiplatform_builder_is_component_tree($component_tree)) {
            return null;
        }

        $registry = APIPlatform_Component_Registry::instance();
        $rendered = [];

        foreach ($component_tree['components'] as $component) {
            if (empty($component['type'])) {
                continue;
            }

            $definition = $registry->get_component($component['type']);
            if (!$definition) {
                continue;
            }

            $props = is_array($component['props'] ?? null) ? $component['props'] : [];
            $rendered[] = $this->render_template($definition['output'], $props, $request_data);
        }

        if (count($rendered) === 1) {
            return $rendered[0];
        }

        return ['components' => $rendered];
    }

    private function render_template($template, $props, $request_data){
        if (is_array($template)) {
            $output = [];
            foreach ($template as $key => $value) {
                $output[$key] = $this->render_template($value, $props, $request_data);
            }
            return $output;
        }

        if (!is_string($template)) {
            return $template;
        }

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\-\.]+)\s*}}/', function($matches) use ($props, $request_data){
            $key = $matches[1];

            if (array_key_exists($key, $props)) {
                return $props[$key];
            }

            if (array_key_exists($key, $request_data)) {
                return $request_data[$key];
            }

            return '';
        }, $template);
    }
}
