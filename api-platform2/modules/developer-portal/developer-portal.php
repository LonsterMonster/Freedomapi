<?php
if (!defined('ABSPATH')) exit;

if (!defined('APIPLATFORM_DEVELOPER_PORTAL_PATH')) {
    define('APIPLATFORM_DEVELOPER_PORTAL_PATH', plugin_dir_path(__FILE__));
}

if (!defined('APIPLATFORM_DEVELOPER_PORTAL_URL')) {
    define('APIPLATFORM_DEVELOPER_PORTAL_URL', plugin_dir_url(__FILE__));
}

foreach (glob(APIPLATFORM_DEVELOPER_PORTAL_PATH . 'classes/class-*.php') as $file) {
    require_once $file;
}

foreach ([
    'APIPlatform_Developer_Portal',
    'APIPlatform_Developer_Portal_SEO',
    'APIPlatform_Developer_Portal_Tester',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
