<?php
// Local browser fixture only; never include this file in a plugin package.
if (PHP_SAPI !== 'cli-server') exit;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/editor.js', '/editor.css'], true)) {
    header('Content-Type: ' . ($path === '/editor.js' ? 'text/javascript' : 'text/css'));
    readfile(__DIR__ . '/../freedomapi-ai/assets' . $path); exit;
}
require __DIR__ . '/bootstrap.php';
core_load();
require __DIR__ . '/../freedomapi-ai/includes/class-connection.php';
require __DIR__ . '/../freedomapi-ai/includes/class-openai.php';
require __DIR__ . '/../freedomapi-ai/includes/class-plugin.php';
session_name('freedomapi_ai_fixture');
session_start();
foreach (['meta','user_meta','options','transients'] as $name) if (isset($_SESSION[$name])) $GLOBALS[$name] = $_SESSION[$name];
register_shutdown_function(function () { foreach (['meta','user_meta','options','transients'] as $name) $_SESSION[$name] = $GLOBALS[$name]; });
if (!FreedomAPI_AI_Connection::status()['connected']) FreedomAPI_AI_Connection::save('sk-browser-fixture-not-a-real-key', 'gpt-4.1-mini');
$GLOBALS['test_rest_base'] = '/wp-json/';
$fixture_plan = plan(op('move', ['full_name'], ['name']), op('set', ['active'], [], 'true'));
$GLOBALS['http_response'] = ['response'=>['code'=>200], 'body'=>json_encode(['status'=>'completed', 'output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($fixture_plan)]]]]])];
if (preg_match('#^/wp-json/freedomapi-ai/v1/apis/(\d+)/(configuration|connection|proposals|approve|rollback)$#', $path, $matches)) {
    $request = new Test_Request((int)$matches[1], json_decode(file_get_contents('php://input'), true) ?: [], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_WP_NONCE'] ?? '');
    $result = FreedomAPI_AI_Plugin::dispatch($matches[2], $request);
    header('Content-Type: application/json');
    if (is_wp_error($result)) { http_response_code($result->data['status'] ?? 400); echo json_encode(['message'=>$result->message]); }
    else echo json_encode($result);
    exit;
}
function esc_url($v) { return htmlspecialchars($v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars($v, ENT_QUOTES); }
function wp_create_nonce($v) { return 'valid-nonce'; }
$api_id = 10;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FreedomAPI AI — local test fixture</title><link rel="stylesheet" href="/editor.css"><style>body{font:16px/1.5 system-ui;margin:2rem auto;max-width:1100px;padding:0 1rem;background:#f5f6f8}</style></head><body><h1>Edit API</h1><p>Local test fixture — synthetic data and mocked OpenAI. No external AI requests.</p><?php include __DIR__ . '/../freedomapi-ai/views/editor.php'; ?><script src="/editor.js"></script></body></html>
