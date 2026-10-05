<?php
if (!defined('ABSPATH')) exit;

foreach ([
    __DIR__ . '/helpers-templates.php',
    __DIR__ . '/class-builder.php',
    __DIR__ . '/class-templates.php',
] as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

if (function_exists('apiplatform_bootstrap_class_once')) {
    apiplatform_bootstrap_class_once('APIPlatform_Templates_Module');
} elseif (class_exists('APIPlatform_Templates_Module')) {
    new APIPlatform_Templates_Module();
}
