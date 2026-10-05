<?php
if (!defined('ABSPATH')) exit;

foreach (glob(__DIR__ . '/class-*.php') as $file) {
    require_once $file;
}

foreach ([
    'APIPlatform_Admin_Automations',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
