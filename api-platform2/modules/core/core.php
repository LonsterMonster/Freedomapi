<?php
if (!defined('ABSPATH')) exit;

foreach ([
    __DIR__ . '/class-core.php',
    __DIR__ . '/class-rest.php',
    __DIR__ . '/class-realtime.php',
    __DIR__ . '/class-analytics.php',
] as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

foreach ([
    'APIPlatform_Core',
    'APIPlatform_REST',
    'APIPlatform_Realtime',
    'APIPlatform_Admin_Analytics',
] as $class) {
    if (function_exists('apiplatform_bootstrap_class_once')) {
        apiplatform_bootstrap_class_once($class);
    } elseif (class_exists($class)) {
        new $class();
    }
}
