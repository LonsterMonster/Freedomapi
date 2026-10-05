<?php
if (!defined('ABSPATH')) exit;

foreach ([
    __DIR__ . '/pricingplans.php',
    __DIR__ . '/class-membership-panel.php',
] as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

if (function_exists('apiplatform_bootstrap_class_once')) {
    apiplatform_bootstrap_class_once('APIPlatform_Membership_Panel');
} elseif (class_exists('APIPlatform_Membership_Panel')) {
    new APIPlatform_Membership_Panel();
}
