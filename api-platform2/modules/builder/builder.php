<?php
if (!defined('ABSPATH')) exit;

if (!defined('APIPLATFORM_BUILDER_PATH')) {
    define(
        'APIPLATFORM_BUILDER_PATH',
        plugin_dir_path(__FILE__)
    );
}

if (!defined('APIPLATFORM_BUILDER_URL')) {
    define(
        'APIPLATFORM_BUILDER_URL',
        plugin_dir_url(__FILE__)
    );
}

if (!defined('APIPLATFORM_BUILDER_VERSION')) {
    define(
        'APIPLATFORM_BUILDER_VERSION',
        '1.0.0'
    );
}

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/

foreach ([

    APIPLATFORM_BUILDER_PATH . 'helpers/helper-functions.php',

    APIPLATFORM_BUILDER_PATH .
    'classes/class-component-registry.php',

    APIPLATFORM_BUILDER_PATH .
    'classes/class-response-renderer.php',

    APIPLATFORM_BUILDER_PATH .
    'classes/class-api-migrator.php',

    APIPLATFORM_BUILDER_PATH .
    'classes/class-api-save.php',

    APIPLATFORM_BUILDER_PATH .
    'classes/class-builder-module.php',

] as $file) {

    if (file_exists($file)) {
        require_once $file;
    }
}

/*
|--------------------------------------------------------------------------
| Load Components
|--------------------------------------------------------------------------
*/

if (class_exists('APIPlatform_Component_Registry')) {

    try {

        APIPlatform_Component_Registry::instance()
            ->load_components_from_directory(
                APIPLATFORM_BUILDER_PATH . 'components/'
            );

    } catch (Exception $e) {

        error_log(
            '[APIPlatform Builder] Component load failed: ' .
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Bootstrap Classes
|--------------------------------------------------------------------------
*/

foreach ([

    'APIPlatform_Builder_API_Save',
    'APIPlatform_Builder_Module',

] as $class) {

    if (!class_exists($class)) {
        continue;
    }

    if (function_exists('apiplatform_bootstrap_class_once')) {

        apiplatform_bootstrap_class_once($class);

    } else {

        new $class();
    }
}