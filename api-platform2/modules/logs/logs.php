<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-admin-logs.php';

if (function_exists('apiplatform_bootstrap_class_once')) {
    apiplatform_bootstrap_class_once('APIPlatform_Admin_Logs');
} elseif (class_exists('APIPlatform_Admin_Logs')) {
    new APIPlatform_Admin_Logs();
}
