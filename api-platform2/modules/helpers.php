<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared helper compatibility loader.
 *
 * The historical API Platform helper layer lived in this single file. The
 * implementation has been split into focused files under modules/helpers/, but
 * this loader remains in place so every existing require_once of modules/helpers.php
 * continues to expose the same global helper functions and hooks.
 */

if (!defined('APIPLATFORM_HELPERS_DIR')) {
    define('APIPLATFORM_HELPERS_DIR', __DIR__ . '/helpers/');
}

$apiplatform_helper_files = [
    'assets.php',
    'error-codes.php',
    'plans.php',
    'plan-simulation.php',
    'usage.php',
    'keys.php',
    'credits.php',
    'teams.php',
    'storage.php',
    'log-sanitizer.php',
    'request-history-retention.php',
    'request-history-retention-test.php',
    'billing.php',
    'security.php',
    'database.php',
    'routes.php',
    'organizations.php',
    'organization-plans.php',
    'api-status.php',
    'lifecycle.php',
    'applications.php',
    'documentation-workspace.php',
    'platform-documentation.php',
    'endpoint-schema.php',
    'openapi.php',
    'sdk-generator.php',
    'gateway.php',
    'notifications.php',
    'automations.php',
    'logic.php',
    'extensions.php',
    'features.php',
    'documentation.php',
    'abuse.php',
];

foreach ($apiplatform_helper_files as $apiplatform_helper_file) {
    $apiplatform_helper_path = APIPLATFORM_HELPERS_DIR . $apiplatform_helper_file;

    if (file_exists($apiplatform_helper_path)) {
        require_once $apiplatform_helper_path;
    }
}

unset($apiplatform_helper_file, $apiplatform_helper_files, $apiplatform_helper_path);

