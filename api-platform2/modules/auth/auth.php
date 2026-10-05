<?php
if (!defined('ABSPATH')) exit;

if (!defined('APIPLATFORM_AUTH_PATH')) {
    define('APIPLATFORM_AUTH_PATH', plugin_dir_path(__FILE__));
}

if (!defined('APIPLATFORM_AUTH_URL')) {
    define('APIPLATFORM_AUTH_URL', plugin_dir_url(__FILE__));
}

$auth_files = [
    'class-auth-provider-interface.php',
    'class-auth-audit.php',
    'class-auth-state.php',
    'class-external-identity-service.php',
    'class-auth-providers.php',
    'class-google-auth-provider.php',
    'class-auth-buttons.php',
    'class-auth-controller.php',
    'class-armember-auth-integration.php',
    'class-auth-settings.php',
];

foreach ($auth_files as $file) {
    $path = APIPLATFORM_AUTH_PATH . 'classes/' . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

foreach ([
    'APIPlatform_Auth_Controller',
    'APIPlatform_ARMember_Auth_Integration',
    'APIPlatform_Auth_Settings',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
