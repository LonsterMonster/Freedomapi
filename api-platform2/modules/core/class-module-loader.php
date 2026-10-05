<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Module_Loader {

    /*
    |--------------------------------------------------------------------------
    | Loaded Modules
    |--------------------------------------------------------------------------
    */

    protected $loaded = [];

    /*
    |--------------------------------------------------------------------------
    | Modules Path
    |--------------------------------------------------------------------------
    */

    protected $modules_path;

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    public function __construct(){

        $this->modules_path =
            plugin_dir_path(dirname(__DIR__));
    }

    /*
    |--------------------------------------------------------------------------
    | Load All Modules
    |--------------------------------------------------------------------------
    */

    public function load_modules(){

        $modules =
            glob(
                $this->modules_path .
                'modules/*/module.json'
            );

        if (!$modules) {
            return;
        }

        foreach ($modules as $module_file){

            $this->load_module(
                $module_file
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Load Single Module
    |--------------------------------------------------------------------------
    */

    protected function load_module(
        $module_file
    ){

        if (!file_exists($module_file)) {
            return;
        }

        $module =
            json_decode(
                file_get_contents($module_file),
                true
            );

        if (
            !$module ||
            !is_array($module)
        ){
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate
        |--------------------------------------------------------------------------
        */

        if (
            empty($module['name']) ||
            empty($module['bootstrap'])
        ){
            return;
        }

        $name =
            sanitize_key(
                $module['name']
            );

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Loads
        |--------------------------------------------------------------------------
        */

        if (
            isset($this->loaded[$name])
        ){
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Enabled Check
        |--------------------------------------------------------------------------
        */

        $saved_modules =
            get_option(
                'apiplatform_modules',
                []
            );

        $enabled =
            isset($saved_modules[$name])
                ? (bool)$saved_modules[$name]
                : ($module['enabled'] ?? true);

        /*
        |--------------------------------------------------------------------------
        | Core Always Loads
        |--------------------------------------------------------------------------
        */

        if ($name === 'core') {
            $enabled = true;
        }

        if (!$enabled) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Bootstrap File
        |--------------------------------------------------------------------------
        */

        $module_dir =
            dirname($module_file);

        $bootstrap =
            trailingslashit(
                $module_dir
            ) .
            $module['bootstrap'];

        if (!file_exists($bootstrap)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Module
        |--------------------------------------------------------------------------
        */

        require_once $bootstrap;

        $this->loaded[$name] = [

            'module' => $module,

            'path' => $module_dir,

            'bootstrap' => $bootstrap
        ];

        /*
        |--------------------------------------------------------------------------
        | Debug Log
        |--------------------------------------------------------------------------
        */

        if (
            defined('WP_DEBUG') &&
            WP_DEBUG
        ){

            error_log(
                'Loaded Module: ' .
                $name
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Loaded Modules
    |--------------------------------------------------------------------------
    */

    public function get_loaded_modules(){

        return $this->loaded;
    }
}