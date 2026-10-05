<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../freedomapi-ai/includes/class-connection.php';
require __DIR__ . '/../freedomapi-ai/includes/class-plugin.php';
if (($argv[1] ?? '') === 'incompatible') {
    function freedomapi_extension_version() { return '2.0.0'; }
    function freedomapi_can_manage_api() {}
    function freedomapi_get_api_configuration() {}
    function freedomapi_preview_transformation() {}
    function freedomapi_update_api_configuration() {}
    function freedomapi_rollback_api_configuration() {}
    function freedomapi_validate_transformation() {}
}
if (($argv[1] ?? '') === 'no-crypto') {
    core_load();
    check(FreedomAPI_AI_Plugin::compatible() && !FreedomAPI_AI_Connection::available(), 'crypto unavailable with compatible Core');
} else {
    check(!FreedomAPI_AI_Plugin::compatible(), 'dependency rejected');
}
FreedomAPI_AI_Plugin::boot();
check(isset($GLOBALS['actions']['admin_notices']), 'admin notice registered');
check(!isset($GLOBALS['actions']['rest_api_init']), 'routes disabled');
check(!isset($GLOBALS['filters']['freedomapi_api_management_sections']), 'editor disabled');
echo "\nDependency: {$GLOBALS['checks']} checks passed.\n";
