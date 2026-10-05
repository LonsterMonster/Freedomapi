<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Component_Registry {

    private static $instance = null;

    private $components = [];

    private $loaded_directories = [];

    /*
    |--------------------------------------------------------------------------
    | Singleton
    |--------------------------------------------------------------------------
    */

    public function __construct(){

        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    public static function instance(){

        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function get_instance(){
        return self::instance();
    }

    /*
    |--------------------------------------------------------------------------
    | Component Validation
    |--------------------------------------------------------------------------
    */

    protected function validate_component($component){

        if (!is_array($component)) {
            return false;
        }

        if (
            empty($component['name']) ||
            !is_string($component['name'])
        ){
            return false;
        }

        if (
            empty($component['label']) ||
            !is_string($component['label'])
        ){
            return false;
        }

        if (
            !isset($component['fields']) ||
            !is_array($component['fields'])
        ){
            return false;
        }

        if (
            !isset($component['output']) ||
            !is_array($component['output'])
        ){
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Load Components
    |--------------------------------------------------------------------------
    */

    public function load_components_from_directory($directory){

        $directory = trailingslashit($directory);

        if (
            isset($this->loaded_directories[$directory]) ||
            !is_dir($directory)
        ){
            return;
        }

        $this->loaded_directories[$directory] = true;

        foreach (
            glob($directory . '*/component.json')
            as $definition_file
        ) {

            $component_dir =
                trailingslashit(dirname($definition_file));

            $definition = json_decode(
                file_get_contents($definition_file),
                true
            );

            /*
            |--------------------------------------------------------------------------
            | Load Optional Component Class
            |--------------------------------------------------------------------------
            */

            $component_slug =
                basename(rtrim($component_dir, '/\\'));

            $class_file =
                $component_dir .
                'class-' .
                $component_slug .
                '-component.php';

            if (file_exists($class_file)) {
                require_once $class_file;
            }

            /*
            |--------------------------------------------------------------------------
            | Dynamic Component Definition
            |--------------------------------------------------------------------------
            */

            if (
                !empty($definition['class']) &&
                class_exists($definition['class'])
            ){

                $component =
                    new $definition['class']();

                if (
                    method_exists(
                        $component,
                        'get_definition'
                    )
                ){

                    $definition =
                        $component->get_definition();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Final Definition
            |--------------------------------------------------------------------------
            */

            if (!$this->validate_component($definition)) {

                error_log(
                    '[APIPlatform Builder] Invalid component: ' .
                    $definition_file
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Register Component
            |--------------------------------------------------------------------------
            */

            $this->register_component_definition(
                $definition
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Register Component
    |--------------------------------------------------------------------------
    */

    public function register_component_definition($definition){

        if (!$this->validate_component($definition)) {
            return false;
        }

        $name = sanitize_key($definition['name']);

        $this->components[$name] = [

            'name'        => $name,

            'label'       => sanitize_text_field(
                $definition['label'] ?? $name
            ),

            'description' => sanitize_text_field(
                $definition['description'] ?? ''
            ),

            'fields'      => $this->normalize_fields(
                $definition['fields'] ?? []
            ),

            'output'      => is_array(
                $definition['output'] ?? null
            )
                ? $definition['output']
                : [],
        ];

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Component
    |--------------------------------------------------------------------------
    */

    public function get_component($name){

        $name = sanitize_key($name);

        return $this->components[$name] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Get All Components
    |--------------------------------------------------------------------------
    */

    public function get_all_components(){
        return $this->components;
    }

    /*
    |--------------------------------------------------------------------------
    | Normalize Fields
    |--------------------------------------------------------------------------
    */

    private function normalize_fields($fields){

        if (!is_array($fields)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $field) {

            if (
                !is_array($field) ||
                empty($field['name'])
            ){
                continue;
            }

            $type = sanitize_key(
                $field['type'] ?? 'text'
            );

            if (
                !in_array(
                    $type,
                    [
                        'text',
                        'number',
                        'textarea',
                        'url',
                        'email'
                    ],
                    true
                )
            ){
                $type = 'text';
            }

            $clean[] = [

                'name' => sanitize_key(
                    $field['name']
                ),

                'label' => sanitize_text_field(
                    $field['label'] ??
                    $field['name']
                ),

                'type' => $type,

                'placeholder' => sanitize_text_field(
                    $field['placeholder'] ?? ''
                ),

                'default' => sanitize_text_field(
                    $field['default'] ?? ''
                ),
            ];
        }

        return $clean;
    }
}