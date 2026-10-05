<?php
if (!defined('ABSPATH')) exit;

$frontend_class_dirs = [
    __DIR__ . '/classes',
    __DIR__, // Backward-compatible fallback for any future root-level class files.
];

foreach ($frontend_class_dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    foreach (glob($dir . '/class-*.php') as $file) {
        require_once $file;
    }
}
if (
    !defined(
        'APIPLATFORM_FRONTEND_PATH'
    )
){

    define(

        'APIPLATFORM_FRONTEND_PATH',

        plugin_dir_path(__FILE__)
    );
}

if (
    !defined(
        'APIPLATFORM_FRONTEND_URL'
    )
){

    define(

        'APIPLATFORM_FRONTEND_URL',

        plugin_dir_url(__FILE__)
    );
}
foreach ([
    'APIPlatform_Frontend_Assets',
    'APIPlatform_Frontend_Layout',
    'APIPlatform_Frontend_Publisher_Pages',
    'APIPlatform_Frontend_Applications',
    'APIPlatform_Frontend_Organizations',
    'APIPlatform_Frontend_Dashboard',
    'APIPlatform_Frontend_Documentation',
    'APIPlatform_Frontend_Create',
    'APIPlatform_Frontend_Edit_API',
    'APIPlatform_Frontend_API_Tester',
    'APIPlatform_Frontend_API_Documentation',
    'APIPlatform_Frontend_Request_History',
    'APIPlatform_Frontend_Keys',
    'APIPlatform_Frontend_Usage',
    'APIPlatform_Frontend_Billing',
    'APIPlatform_Frontend_Plans',
    'APIPlatform_Frontend_Profile',
    'APIPlatform_Frontend_Settings',
    'APIPlatform_Frontend_Team',
    'APIPlatform_Frontend_Category_Page',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
if (

    class_exists(
        'APIPlatform_Component_Registry'
    )
){

    /*
    |--------------------------------------------------------------------------
    | Component Registry
    |--------------------------------------------------------------------------
    */

    $registry =
        APIPlatform_Component_Registry::instance();

    /*
    |--------------------------------------------------------------------------
    | Load Frontend Components
    |--------------------------------------------------------------------------
    */

    $registry->load_components_from_directory(

        APIPLATFORM_FRONTEND_PATH .
        'components/'
    );
}
