<?php
if (!defined('ABSPATH')) exit;

foreach ([
    dirname(__DIR__) . '/data/services/class-billing-service.php',
    dirname(__DIR__) . '/data/services/class-key-service.php',
] as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

foreach (glob(__DIR__ . '/class-*.php') as $file) {
    require_once $file;
}

foreach ([
    'APIPlatform_Admin_Menu',
    'APIPlatform_Admin_Dashboard',
    'APIPlatform_Admin_Settings',
    'APIPlatform_Admin_Alerts',
    'APIPlatform_Admin_Bans',
    'APIPlatform_Admin_Debug',
    'APIPlatform_Admin_Keys',
    'APIPlatform_Admin_Panel',
    'APIPlatform_Admin_Transactions',
    'APIPlatform_Admin_User',
    'APIPlatform_Admin_Users',
    'APIPlatform_Automations',
    'APIPlatform_Intelligence',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
