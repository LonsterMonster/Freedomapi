<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['meta'] = []; $GLOBALS['options'] = []; $GLOBALS['user_meta'] = []; $GLOBALS['transients'] = [];
$GLOBALS['user'] = 1; $GLOBALS['actions'] = []; $GLOBALS['filters'] = []; $GLOBALS['http_calls'] = [];
class WP_Error {
    public function __construct(public $code = '', public $message = '', public $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function absint($v) { return abs((int) $v); }
function get_current_user_id() { return $GLOBALS['user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function get_post($id) { return $id === 10 || $id === 11 ? (object) ['ID' => $id, 'post_type' => 'user_api', 'post_author' => $id === 10 ? 1 : 2, 'post_name' => 'test'] : null; }
function get_post_field($field, $id) { return get_post($id)->{$field} ?? ''; }
function get_the_author_meta($field, $id) { return 'test-user'; }
function get_userdata($id) { return (object) ['user_nicename'=>'test-user']; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_post_meta($id, $key, $single = true) { return unserialize(serialize($GLOBALS['meta'][$id][$key] ?? '')); }
function update_post_meta($id, $key, $value, $previous = '') {
    if (!empty($GLOBALS['fail_storage'])) return false;
    if ($previous !== '' && serialize(get_post_meta($id, $key)) !== serialize($previous)) return false;
    $GLOBALS['meta'][$id][$key] = wp_unslash($value); return true;
}
function add_post_meta($id, $key, $value, $unique = false) {
    if ($unique && isset($GLOBALS['meta'][$id][$key])) return false;
    return update_post_meta($id, $key, $value);
}
function get_user_meta($id, $key, $single = true) { return $GLOBALS['user_meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { if (!empty($GLOBALS['fail_storage'])) return false; $GLOBALS['user_meta'][$id][$key] = wp_unslash($value); return true; }
function delete_user_meta($id, $key) { unset($GLOBALS['user_meta'][$id][$key]); return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_slash($v) { if (is_string($v)) return addslashes($v); if (is_array($v)) return array_map('wp_slash', $v); return $v; }
function wp_unslash($v) { if (is_string($v)) return stripslashes($v); if (is_array($v)) return array_map('wp_unslash', $v); if (is_object($v)) { $v = clone $v; foreach ($v as &$child) $child = wp_unslash($child); } return $v; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function sanitize_title($v) { return sanitize_key(str_replace('/', '-', $v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return sanitize_text_field($v); }
function current_time($v) { return gmdate('Y-m-d H:i:s'); }
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['filters'][$name][] = $callback; }
function do_action($name, ...$args) { foreach ($GLOBALS['actions'][$name] ?? [] as $cb) $cb(...$args); }
function apply_filters($name, $value, ...$args) { foreach ($GLOBALS['filters'][$name] ?? [] as $cb) $value = $cb($value, ...$args); return $value; }
function rest_url($path = '') { return ($GLOBALS['test_rest_base'] ?? 'https://example.test/wp-json/') . $path; }
function user_can($id, $capability) { return false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function wp_salt($scheme = '') { return $GLOBALS['salt'] ?? 'test-only-installation-salt-with-entropy'; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid-nonce' && $action === 'wp_rest'; }
class Test_Request implements ArrayAccess {
    public function __construct(public $api_id = 10, public $data = [], public $method = 'POST', public $nonce = 'valid-nonce') {}
    public function get_header($name) { return $this->nonce; }
    public function get_json_params() { return $this->data; }
    public function get_method() { return $this->method; }
    public function get_body() { return json_encode($this->data); }
    public function offsetExists(mixed $offset): bool { return $offset === 'api_id'; }
    public function offsetGet(mixed $offset): mixed { return $this->api_id; }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
}
function wp_remote_post($url, $args) { $GLOBALS['http_calls'][] = [$url, $args]; return $GLOBALS['http_response'] ?? new WP_Error('network', 'secret failure'); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
class APIPlatform_Ownership_Service {
    public static function owner($id) { return ['owner_type'=>'personal','owner_id'=>1,'owner_slug'=>'test-user']; }
    public static function can($user, $api, $permission) { return get_post($api) && get_post($api)->post_author === $user && empty($GLOBALS['deny_schema']); }
}
function check($condition, $label) { if (!$condition) throw new RuntimeException('FAIL: ' . $label); $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1; echo '.'; }
function op($kind, $path, $from = [], $value = 'null') { return ['op' => $kind, 'path' => $path, 'from' => $from, 'value_json' => $value]; }
function plan(...$ops) { return ['version' => 1, 'operations' => $ops]; }
function core_load() {
    $root = getenv('FREEDOMAPI_CORE_PATH') ?: dirname(__DIR__, 2) . '/api-platform2';
    require_once $root . '/modules/helpers/endpoint-schema.php';
    require_once $root . '/modules/helpers/extensions.php';
    $GLOBALS['meta'][10]['api_json'] = '{"name":"Ada","profile":{"age":37},"nullable":null,"items":[1,2]}';
}
