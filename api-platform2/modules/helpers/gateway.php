<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Gateway_Request {
    public $method = 'GET';
    public $path = '';
    public $query = [];
    public $headers = [];
    public $cookies = [];
    public $body = '';
    public $json = null;
    public $request_id = '';
    public $timestamp = '';
    public $client_ip = '';
    public $publisher = null;
    public $api = null;
    public $api_id = 0;
    public $owner_id = 0;
    public $version = '';
    public $endpoint = null;
    public $endpoint_slug = '';
    public $api_key = '';
    public $auth_context = ['auth_type' => 'not_attempted'];
    public $source = 'rest';
    public $route_parts = [];
}

class APIPlatform_Gateway_Response {
    public $status = 200;
    public $headers = [];
    public $body = null;
    public $content_type = 'application/json';
    public $warnings = [];
    public $validation = [];
    public $request_id = '';
    public $cache = ['hit' => false, 'key' => ''];
    public $execution_time = 0.0;
    public $response_size = 0;

    public function __construct($body = null, $status = 200, array $headers = []){
        $this->body = $body;
        $this->status = absint($status) ?: 200;
        $this->headers = $headers;
    }
}

class APIPlatform_Gateway_Error extends WP_Error {
    public function __construct($code, $message, $status = 500, array $extra = []){
        parent::__construct(sanitize_key($code), $message, array_merge([
            'status' => absint($status) ?: 500,
            'error' => [
                'code' => sanitize_key($code),
                'message' => $message,
            ],
        ], $extra));
    }
}

class APIPlatform_Gateway {

    const ROUTES_VERSION = '9-0';
    const MAX_PROXY_BYTES = 1048576;

    private static $instance = null;
    private static $callbacks = [];

    public static function instance(){
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        add_action('init', [$this, 'register_routes'], 20);
        add_filter('query_vars', [$this, 'query_vars']);
        // A gateway request is a machine response, not a frontend page.
        // Before page-builder and theme callbacks registered on template_redirect.
        add_action('template_redirect', [$this, 'maybe_dispatch_public_route'], -9999);
    }

    public static function register_callback($type, callable $callback){
        $type = sanitize_key($type);
        if ($type !== '') {
            self::$callbacks[$type] = $callback;
        }
    }

    public static function runtime_callback($type){
        $type = sanitize_key($type);
        return self::$callbacks[$type] ?? null;
    }

    public function register_routes(){
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_prefix() : 'gateway';
        add_rewrite_rule('^' . $prefix . '/?$', 'index.php?apiplatform_gateway=1', 'top');
        add_rewrite_rule('^' . $prefix . '/([^/]+)/?$', 'index.php?apiplatform_gateway=1&apiplatform_gateway_publisher=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/([^/]+)/([^/]+)/?$', 'index.php?apiplatform_gateway=1&apiplatform_gateway_publisher=$matches[1]&apiplatform_gateway_api=$matches[2]', 'top');
        add_rewrite_rule('^' . $prefix . '/([^/]+)/([^/]+)/(.+)?$', 'index.php?apiplatform_gateway=1&apiplatform_gateway_publisher=$matches[1]&apiplatform_gateway_api=$matches[2]&apiplatform_gateway_tail=$matches[3]', 'top');

        if (get_option('apiplatform_gateway_routes_version') !== self::ROUTES_VERSION) {
            flush_rewrite_rules(false);
            update_option('apiplatform_gateway_routes_version', self::ROUTES_VERSION);
        }
    }

    public function query_vars($vars){
        foreach (['apiplatform_gateway', 'apiplatform_gateway_publisher', 'apiplatform_gateway_api', 'apiplatform_gateway_tail'] as $var) {
            $vars[] = $var;
        }
        return $vars;
    }

    public function maybe_dispatch_public_route(){
        $is_gateway = get_query_var('apiplatform_gateway');
        if (!$is_gateway && !$this->path_is_gateway()) {
            return;
        }

        $start = microtime(true);
        $request = $this->request_from_globals($start);
        self::diagnostic('FREEDOMAPI_API_REQUEST_REACHED_WORDPRESS', [
            'path' => $request->path,
            'publisher' => $request->route_parts[0] ?? '',
            'api' => $request->route_parts[1] ?? '',
            'query_gateway' => $is_gateway ? 'yes' : 'no',
        ]);
        $response = $this->handle($request, $start);
        $this->send($response);
        exit;
    }

    public function handle_rest(WP_REST_Request $wp_request){
        $start = microtime(true);
        $request = $this->request_from_rest($wp_request, $start);
        $response = $this->handle($request, $start);

        if (is_wp_error($response)) {
            return $response;
        }

        return $this->to_rest_response($response);
    }

    public function handle(APIPlatform_Gateway_Request $request, $start = null){
        $start = $start ?: microtime(true);

        try {
            if (apiplatform_is_disabled()) {
                self::diagnostic('FREEDOMAPI_GATEWAY_503', [
                    'reason' => 'platform_disabled',
                    'path' => $request->path,
                    'source' => $request->source,
                ]);
                return $this->finish_error($request, 'platform_disabled', 'API platform is temporarily disabled.', 503, $start);
            }

            $resolution = APIPlatform_Route_Resolver::resolve($request);
            if (is_wp_error($resolution)) {
                return $this->finish_error($request, $resolution->get_error_code(), $resolution->get_error_message(), $this->error_status($resolution, 404), $start);
            }

            $auth = $this->authenticate($request);
            if (is_wp_error($auth)) {
                return $this->finish_error($request, $auth->get_error_code(), $auth->get_error_message(), $this->error_status($auth, 403), $start);
            }

            $rate = $this->rate_limit($request);
            if (is_wp_error($rate)) {
                return $this->finish_error($request, $rate->get_error_code(), $rate->get_error_message(), $this->error_status($rate, 429), $start);
            }

            $validation = $this->validate_request($request);
            if (is_wp_error($validation)) {
                return $this->finish_error($request, $validation->get_error_code(), $validation->get_error_message(), $this->error_status($validation, 400), $start, ['validation' => $this->error_data_value($validation, 'validation')]);
            }

            do_action('apiplatform_gateway_before_runtime', $request);
            self::diagnostic('FREEDOMAPI_GATEWAY_FORWARD_STARTED', [
                'path' => $request->path,
                'api_id' => $request->api_id,
                'runtime_type' => sanitize_key(get_post_meta($request->api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal'),
            ]);

            $cache = APIPlatform_Cache_Service::get($request);
            if ($cache) {
                $response = new APIPlatform_Gateway_Response($cache['body'], absint($cache['status'] ?? 200), is_array($cache['headers'] ?? null) ? $cache['headers'] : []);
                $response->cache = ['hit' => true, 'key' => $cache['key'] ?? ''];
            } else {
                $response = APIPlatform_Runtime::execute($request);
                if (is_wp_error($response)) {
                    return $this->finish_error($request, $response->get_error_code(), $response->get_error_message(), $this->error_status($response, 500), $start);
                }
                $response = $response instanceof APIPlatform_Gateway_Response ? $response : new APIPlatform_Gateway_Response($response, 200);
                APIPlatform_Cache_Service::set($request, $response);
            }

            do_action('apiplatform_gateway_after_runtime', $request, $response);

            // Cache stores original bodies. Apply approved transformations exactly once,
            // on hits and misses, without depending on the AI plugin or an AI request.
            if (function_exists('freedomapi_transform_response')) {
                $content_type = $response->content_type;
                foreach ($response->headers as $name => $value) {
                    if (strtolower($name) === 'content-type') $content_type = $value;
                }
                if (freedomapi_has_response_transformation($request->api_id, $request->version) && $response->status === 200 && stripos($content_type, 'json') === false) {
                    return $this->finish_error($request, 'response_transformation_failed', 'An approved transformation requires a JSON response.', 500, $start);
                }
                $transformed = freedomapi_transform_response($request->api_id, $response->body, $request->version, $response->status);
                if (is_wp_error($transformed)) {
                    return $this->finish_error($request, $transformed->get_error_code(), $transformed->get_error_message(), 500, $start);
                }
                if ($transformed !== $response->body) {
                    foreach (array_keys($response->headers) as $header) {
                        if (in_array(strtolower($header), ['content-length', 'etag', 'content-md5'], true)) unset($response->headers[$header]);
                    }
                    $response->body = $transformed;
                }
            }

            $response_validation = $this->validate_response($request, $response);
            if (is_wp_error($response_validation)) {
                return $this->finish_error($request, $response_validation->get_error_code(), $response_validation->get_error_message(), $this->error_status($response_validation, 500), $start);
            }

            $this->track_success($request);
            return $this->finish_success($request, $response, $start);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $endpoint_id = is_array($request->endpoint) ? ($request->endpoint['id'] ?? '') : '';
                error_log('[GATEWAY] Internal error type=' . sanitize_text_field(get_class($e)) . ' message=' . sanitize_text_field($e->getMessage()) . ' source=' . sanitize_text_field(basename($e->getFile())) . ':' . absint($e->getLine()) . ' api_id=' . absint($request->api_id) . ' version=' . sanitize_text_field($request->version) . ' endpoint_id=' . sanitize_text_field($endpoint_id));
            }
            return $this->finish_error($request, 'internal_gateway_error', 'Internal gateway error.', 500, $start);
        }
    }

    private function authenticate(APIPlatform_Gateway_Request $request){
        $credentials = function_exists('apiplatform_extract_presented_api_key')
            ? apiplatform_extract_presented_api_key($request->headers, $request->query)
            : ['valid' => false, 'error' => 'missing_api_key'];
        if (empty($credentials['valid'])) {
            $error_key = sanitize_key($credentials['error'] ?? 'authentication_failed');
            if ($error_key === 'missing_api_key') {
                return new APIPlatform_Gateway_Error('authentication_failed', 'API key is required.', 401);
            }
            $definition = class_exists('APIPlatform_Error_Codes') ? APIPlatform_Error_Codes::get_by_key($error_key) : null;
            return new APIPlatform_Gateway_Error(
                $error_key,
                $definition['message'] ?? 'Authentication credentials are malformed or conflicting.',
                absint($definition['http_status'] ?? 401)
            );
        }
        $api_key = (string) $credentials['key'];
        if ($api_key === '') {
            return new APIPlatform_Gateway_Error('authentication_failed', 'API key is required.', 401);
        }

        $request->api_key = $api_key;
        $request->auth_context['auth_transport'] = sanitize_key($credentials['transport'] ?? '');
        if (function_exists('apiplatform_authenticate_application_key') && strpos((string) $api_key, 'fapi_app_') === 0) {
            $application_result = apiplatform_authenticate_application_key($request->api_id, $api_key);
            if (is_array($application_result)) {
                $request->auth_context = array_merge($request->auth_context, $application_result, ['auth_transport' => sanitize_key($credentials['transport'] ?? '')]);
                return !empty($application_result['valid'])
                    ? true
                    : new APIPlatform_Gateway_Error($application_result['code'] ?? 'application_not_allowed', $application_result['message'] ?? 'Application key is not allowed.', absint($application_result['status'] ?? 403));
            }
        }

        $legacy = function_exists('apiplatform_validate_api_key_detailed')
            ? apiplatform_validate_api_key_detailed($request->api_id, $api_key)
            : ['valid' => function_exists('apiplatform_validate_api_key') && apiplatform_validate_api_key($request->api_id, $api_key), 'code' => 'authentication_failed', 'message' => 'Invalid API key.', 'status' => 403];

        if (empty($legacy['valid'])) {
            return new APIPlatform_Gateway_Error($legacy['code'] ?? 'authentication_failed', $legacy['message'] ?? 'Invalid API key.', absint($legacy['status'] ?? 403));
        }

        
        $request->auth_context = array_merge($legacy, ['auth_transport' => sanitize_key($credentials['transport'] ?? '')]);
        return true;
    }

    private function rate_limit(APIPlatform_Gateway_Request $request){
        $key_rate = function_exists('apiplatform_check_key_limits')
            ? apiplatform_check_key_limits($request->api_id, $request->api_key, $request->owner_id)
            : ['blocked' => false];

        if (!empty($key_rate['blocked'])) {
            return new APIPlatform_Gateway_Error('rate_limit_exceeded', $key_rate['message'] ?? 'Rate limit exceeded.', 429, ['rate_limit' => $key_rate]);
        }

        $rate = function_exists('apiplatform_check_rate_limit')
            ? apiplatform_check_rate_limit($request->owner_id, $request->api_id)
            : ['blocked' => false];

        if (!empty($rate['blocked'])) {
            return new APIPlatform_Gateway_Error('rate_limit_exceeded', $rate['message'] ?? 'Rate limit exceeded.', 429, ['rate_limit' => $rate]);
        }

        $request->auth_context['rate_limit'] = $key_rate + $rate;
        return true;
    }

    private function error_status($error, $fallback){
        if (!is_wp_error($error)) {
            return absint($fallback) ?: 500;
        }
        $data = $error->get_error_data();
        return is_array($data) && isset($data['status']) && absint($data['status']) > 0
            ? absint($data['status'])
            : (absint($fallback) ?: 500);
    }

    private function error_data_value($error, $key){
        if (!is_wp_error($error)) return null;
        $data = $error->get_error_data();
        return is_array($data) && array_key_exists($key, $data) ? $data[$key] : null;
    }
    private function validate_request(APIPlatform_Gateway_Request $request){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return true;
        }

        $validation = APIPlatform_Endpoint_Schema_Service::validate_request($request->api_id, $this->wp_request_adapter($request), $request->version);
        if (empty($validation['ok']) && ($validation['mode'] ?? '') === 'enforce') {
            return new APIPlatform_Gateway_Error('validation_failed', 'Request failed endpoint schema validation.', 400, ['validation' => $validation]);
        }

        if (empty($validation['ok'])) {
            $request->auth_context['validation_result'] = 'warn';
        }

        return true;
    }

    private function validate_response(APIPlatform_Gateway_Request $request, APIPlatform_Gateway_Response $response){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            $request->auth_context['response_validation_status'] = 'not_checked';
            $request->auth_context['response_validation_mode'] = 'off';
            return true;
        }
        $content_type = $response->content_type ?: ($response->headers['Content-Type'] ?? 'application/json');
        $result = APIPlatform_Endpoint_Schema_Service::validate_response($request->api_id, is_array($request->endpoint) ? $request->endpoint : [], $response->status, $content_type, $response->body);
        $request->auth_context['response_validation_status'] = sanitize_key($result['status'] ?? 'not_checked');
        $request->auth_context['response_validation_mode'] = sanitize_key($result['mode'] ?? 'off');
        $request->auth_context['response_validation_errors'] = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $response->validation = $result;
        if (empty($result['valid']) && ($result['mode'] ?? '') === 'enforce' && !empty($result['validation_performed'])) {
            $definition = class_exists('APIPlatform_Error_Codes') ? APIPlatform_Error_Codes::get_by_key('response_schema_validation_failed') : null;
            return new APIPlatform_Gateway_Error('response_schema_validation_failed', $definition['message'] ?? 'Response validation failed.', absint($definition['http_status'] ?? 500));
        }
        if (empty($result['valid']) && !empty($result['validation_performed'])) {
            $response->warnings[] = 'Response contract mismatch.';
        }
        return true;
    }

    private function finish_success(APIPlatform_Gateway_Request $request, APIPlatform_Gateway_Response $response, $start){
        $response->request_id = $request->request_id;
        $response->execution_time = round(microtime(true) - (float) $start, 4);
        $response->response_size = strlen(wp_json_encode($response->body));
        $response->headers = array_merge($this->standard_headers($request), $response->headers);
        if ($response->warnings) {
            $response->headers['X-Gateway-Warning'] = implode('; ', array_map('sanitize_text_field', $response->warnings));
        }

        $this->log($request, $response->status, $start, $response);
        if ((int) $response->status === 503) {
            self::diagnostic('FREEDOMAPI_GATEWAY_503', [
                'reason' => 'runtime_response_503',
                'path' => $request->path,
                'api_id' => $request->api_id,
                'runtime_type' => sanitize_key(get_post_meta($request->api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal'),
            ]);
        }
        return $response;
    }

    private function finish_error(APIPlatform_Gateway_Request $request, $code, $message, $status, $start, array $extra = []){
        if ((int) $status === 503) {
            self::diagnostic('FREEDOMAPI_GATEWAY_503', [
                'reason' => sanitize_key($code),
                'path' => $request->path,
                'api_id' => $request->api_id,
                'source' => $request->source,
            ]);
        }

        $body = array_merge([
            'status' => absint($status),
            'error' => [
                'code' => sanitize_key($code),
                'message' => $message,
            ],
            'request_id' => $request->request_id,
        ], $extra);
        $response = new APIPlatform_Gateway_Response($body, $status, $this->standard_headers($request));
        $this->log($request, $status, $start, $response);
        return $response;
    }

    private function standard_headers(APIPlatform_Gateway_Request $request){
        $headers = [
            'X-FreedomAPI-Gateway' => 'wordpress',
            'X-Request-ID' => $request->request_id,
            'X-API-Version' => $request->version ?: 'default',
            'X-API' => $request->api ? $request->api->post_name : '',
            'X-Publisher' => $request->publisher ? $request->publisher->user_nicename : '',
        ];

        if ($request->api_id && class_exists('APIPlatform_Lifecycle_Policy') && APIPlatform_Lifecycle_Policy::get_state($request->api_id) === 'deprecated') {
            $headers['X-API-Deprecated'] = 'true';
            $sunset = get_post_meta($request->api_id, APIPlatform_Lifecycle_Policy::META_SUNSET_DATE, true);
            if ($sunset) {
                $headers['Sunset'] = $sunset;
            }
        }

        $version_state = sanitize_key(get_post_meta($request->api_id, 'apiplatform_gateway_version_state_' . sanitize_key($request->version), true));
        if ($version_state === 'deprecated') {
            $headers['X-API-Deprecated'] = 'true';
        }

        $limit = $request->auth_context['rate_limit']['limit'] ?? null;
        $remaining = $request->auth_context['rate_limit']['remaining'] ?? null;
        if ($limit !== null) {
            $headers['X-RateLimit-Limit'] = (string) absint($limit);
        }
        if ($remaining !== null) {
            $headers['X-RateLimit-Remaining'] = (string) max(0, absint($remaining));
        }

        return array_filter($headers, function($value){ return $value !== ''; });
    }

    private function track_success(APIPlatform_Gateway_Request $request){
        try {
            if (class_exists('APIPlatform_Core')) {
                APIPlatform_Core::track($request->api_id);
            }
            if (function_exists('apiplatform_track_key_usage')) {
                apiplatform_track_key_usage($request->api_id, $request->api_key);
            }
            if (($request->auth_context['auth_type'] ?? 'legacy') === 'application' && class_exists('APIPlatform_Applications_Service')) {
                APIPlatform_Applications_Service::mark_used($request->auth_context);
            }
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[GATEWAY] Usage tracking failed for API ID ' . absint($request->api_id));
            }
        }
    }

    private function log(APIPlatform_Gateway_Request $request, $status, $start, APIPlatform_Gateway_Response $response){
        if (!function_exists('apiplatform_log_api_request')) {
            return;
        }
        $context = array_merge($request->auth_context, [
            'request_id' => $request->request_id,
            'gateway_version' => $request->version,
            'gateway_endpoint' => $request->endpoint['path'] ?? $request->endpoint_slug,
            'cache_result' => !empty($response->cache['hit']) ? 'hit' : 'miss',
            'response_size' => $response->response_size,
            'request_headers' => $request->headers,
            'query_params' => $request->query,
            'request_body' => $request->body,
            'request_content_type' => $request->headers['content-type'] ?? ($request->headers['Content-Type'] ?? ''),
            'user_agent' => $request->headers['user-agent'] ?? ($request->headers['User-Agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'response_headers' => $response->headers,
            'response_body' => $response->body,
            'response_content_type' => $response->headers['Content-Type'] ?? $response->content_type,
            'error_code' => is_array($response->body) ? ($response->body['error']['code'] ?? '') : '',
            'error_message' => is_array($response->body) ? ($response->body['error']['message'] ?? '') : '',
        ]);
        apiplatform_log_api_request($request->owner_id, $request->api_id, $request->endpoint_slug, $status, $start, $context);
    }


    private function request_from_rest(WP_REST_Request $wp_request, $start){
        $request = $this->base_request($start);
        $request->source = 'rest';
        $request->method = strtoupper($wp_request->get_method());
        $request->endpoint_slug = sanitize_title($wp_request['endpoint'] ?? '');
        $request->path = '/platform/v1/api/' . $request->endpoint_slug;
        $request->query = $wp_request->get_query_params();
        $request->headers = $this->flatten_headers($wp_request->get_headers());
        $request->cookies = $this->request_cookies();
        $request->body = (string) $wp_request->get_body();
        $request->json = $wp_request->get_json_params();
        return $request;
    }

    private function request_from_globals($start){
        $request = $this->base_request($start);
        $request->source = 'gateway';
        $request->method = strtoupper(sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $publisher = sanitize_title(get_query_var('apiplatform_gateway_publisher'));
        $api = sanitize_title(get_query_var('apiplatform_gateway_api'));
        $tail = trim((string) get_query_var('apiplatform_gateway_tail'), '/');
        if (!$publisher || !$api) {
            $parts = $this->path_parts();
            $publisher = sanitize_title($parts[1] ?? '');
            $api = sanitize_title($parts[2] ?? '');
            $tail = implode('/', array_slice($parts, 3));
        }
        $request->route_parts = array_values(array_filter([$publisher, $api, $tail]));
        $request->endpoint_slug = $api;
        $request->path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $request->query = $this->sanitize_input_values(is_array($_GET) ? wp_unslash($_GET) : []);
        $request->headers = $this->server_headers();
        $request->cookies = $this->request_cookies();
        $request->body = (string) file_get_contents('php://input');
        $decoded = json_decode($request->body, true);
        $request->json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        return $request;
    }

    private function base_request($start){
        $request = new APIPlatform_Gateway_Request();
        $request->request_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('req_', true);
        $request->timestamp = current_time('mysql');
        $request->client_ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        return $request;
    }

    private function request_cookies(){
        return $this->sanitize_input_values(is_array($_COOKIE) ? wp_unslash($_COOKIE) : []);
    }

    private function sanitize_input_values($value){
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[sanitize_text_field((string) $key)] = $this->sanitize_input_values($item);
            }
            return $safe;
        }
        return sanitize_text_field((string) $value);
    }
    private function wp_request_adapter(APIPlatform_Gateway_Request $request){
        $wp = new WP_REST_Request($request->method, $request->path);
        foreach ($request->query as $key => $value) {
            $wp->set_param($key, $value);
        }
        foreach ($request->headers as $key => $value) {
            $wp->set_header($key, $value);
        }
        if ($request->body !== '') {
            $wp->set_body($request->body);
        }
        return $wp;
    }

    private function to_rest_response(APIPlatform_Gateway_Response $response){
        $rest = rest_ensure_response($response->body);
        $rest->set_status($response->status);
        foreach ($response->headers as $key => $value) {
            $rest->header($key, $value);
        }
        return $rest;
    }

    private function send($response){
        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $status = absint($data['status'] ?? 500);
            status_header($status);
            foreach ($this->headers_from_error_data($data) as $key => $value) {
                header($key . ': ' . $value);
            }
            wp_send_json($data, $status);
        }

        status_header($response->status);
        foreach ($response->headers as $key => $value) {
            header($key . ': ' . $value);
        }
        wp_send_json($response->body, $response->status);
    }

    private function headers_from_error_data($data){
        $headers = [];
        if (!empty($data['request_id'])) {
            $headers['X-Request-ID'] = sanitize_text_field($data['request_id']);
        }
        return $headers;
    }

    private function flatten_headers(array $headers){
        $out = [];
        foreach ($headers as $key => $value) {
            $out[$key] = is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : sanitize_text_field($value);
        }
        return $out;
    }

    private function server_headers(){
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = sanitize_text_field($value);
        }
        if (empty($headers['authorization']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = sanitize_text_field($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        return $headers;
    }

    private function path_is_gateway(){
        $parts = $this->path_parts();
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_prefix() : 'gateway';
        return ($parts[0] ?? '') === $prefix;
    }

    private function path_parts(){
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        return $path === '' ? [] : explode('/', $path);
    }

    public static function diagnostic($marker, array $context = []){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = ['marker=' . sanitize_text_field($marker)];
        foreach ($context as $key => $value) {
            if (in_array($key, ['api_key', 'authorization', 'cookie', 'cookies', 'nonce', 'password'], true)) {
                continue;
            }
            $parts[] = sanitize_key($key) . '=' . sanitize_text_field((string) $value);
        }

        error_log('[FreedomAPI gateway diagnostic] ' . implode(' ', $parts));
    }
}

class APIPlatform_Route_Resolver {
    public static function resolve(APIPlatform_Gateway_Request $request){
        $api = null;
        $publisher_slug = '';
        $tail = [];

        if ($request->source === 'gateway') {
            $publisher_slug = sanitize_title($request->route_parts[0] ?? '');
            $api_slug = sanitize_title($request->route_parts[1] ?? '');
            $tail = isset($request->route_parts[2]) ? explode('/', trim($request->route_parts[2], '/')) : [];
        } else {
            $api_slug = sanitize_title($request->endpoint_slug);
        }

        self::diagnostic('FREEDOMAPI_API_ROUTER_MATCHED', [
            'source' => $request->source,
            'path' => $request->path,
            'publisher' => $publisher_slug,
            'api' => $api_slug,
        ]);

        if ($api_slug === '') {
            return new APIPlatform_Gateway_Error('invalid_request', 'Invalid API route.', 400);
        }

        $api = self::find_api($api_slug);
        if (!$api) {
            return new APIPlatform_Gateway_Error('api_not_found', 'API not found.', 404);
        }

        $request->api = $api;
        $request->api_id = absint($api->ID);
        $owner = class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($request->api_id) : null;
        $request->owner_id = $owner ? absint($owner['owner_id']) : (function_exists('apiplatform_get_team_owner') ? absint(apiplatform_get_team_owner($api->post_author)) : absint($api->post_author));
        $request->publisher = (!$owner || ($owner['owner_type'] ?? 'personal') === 'personal') ? get_userdata($request->owner_id) : null;

        if ($publisher_slug !== '') {
            if ($owner && ($owner['owner_type'] ?? '') === 'organization') {
                $organization_slug = sanitize_title($owner['owner_slug'] ?? '');
                $allowed_slugs = array_filter([$organization_slug, 'organization-' . absint($owner['owner_id'] ?? 0)]);
                if (!in_array($publisher_slug, $allowed_slugs, true)) {
                    return new APIPlatform_Gateway_Error('publisher_not_found', 'Publisher route does not match this API.', 404);
                }
            } elseif ($request->publisher && !in_array($publisher_slug, self::publisher_slugs($request->publisher), true)) {
                return new APIPlatform_Gateway_Error('publisher_not_found', 'Publisher route does not match this API.', 404);
            }
        }

        if ($api->post_status !== 'publish') {
            return new APIPlatform_Gateway_Error('api_not_active', 'API is not active.', 404);
        }

        $runtime_access = class_exists('APIPlatform_Lifecycle_Policy')
            ? APIPlatform_Lifecycle_Policy::runtime_access($request->api_id)
            : ['allowed' => (get_post_meta($request->api_id, 'apiplatform_status', true) ?: 'active') === 'active', 'code' => 'api_disabled', 'message' => 'API is disabled.', 'status' => 403];
        if (empty($runtime_access['allowed'])) {
            return new APIPlatform_Gateway_Error($runtime_access['code'] ?? 'api_disabled', $runtime_access['message'] ?? 'API is unavailable.', absint($runtime_access['status'] ?? 403));
        }

        $version = self::resolve_version($request->api_id, $tail);
        if (is_wp_error($version)) {
            return $version;
        }
        $request->version = $version['version'];
        $endpoint_tail = $version['tail'];
        $request->endpoint = self::resolve_endpoint($request, $endpoint_tail);
        self::diagnostic('FREEDOMAPI_REQUEST_SCHEMA_RESOLVED', [
            'request_body_enabled' => !empty($request->endpoint['request_body']['enabled']) ? 'true' : 'false',
            'parameter_count' => is_array($request->endpoint['parameters'] ?? null) ? count($request->endpoint['parameters']) : 0,
            'resolved_version' => $request->version,
            'endpoint_id' => $request->endpoint['id'] ?? 'unknown',
        ]);
        if (!$request->endpoint) {
            return new APIPlatform_Gateway_Error('endpoint_not_found', 'Endpoint not found for this API version.', 404);
        }

        return true;
    }

    private static function find_api($slug){
        $apis = get_posts([
            'post_type' => 'user_api',
            'name' => sanitize_title($slug),
            'post_status' => ['publish'],
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        return $apis ? $apis[0] : null;
    }

    private static function publisher_slugs(WP_User $user){
        return array_values(array_unique(array_filter(array_map('sanitize_title', [
            $user->user_nicename,
            $user->user_login,
            $user->display_name,
            get_user_meta($user->ID, 'nickname', true),
        ]))));
    }

    private static function resolve_version($api_id, array $tail){
        $default = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::version_label($api_id)
            : 'v1';
        $version = $default;
        $first = sanitize_text_field($tail[0] ?? '');
        $schema_meta_key = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::META_SCHEMAS
            : 'apiplatform_endpoint_schema_versions';
        $schemas = get_post_meta($api_id, $schema_meta_key, true);
        $schemas = is_array($schemas) ? $schemas : [];
        if (!isset($schemas[$version]) && $schemas) {
            $version = self::latest_published_version($api_id, $schemas, $version);
        }

        if ($first !== '' && (isset($schemas[$first]) || preg_match('/^v?\d+(?:\.\d+){0,2}$/i', $first))) {
            if (!isset($schemas[$first]) && $first !== $default) {
                return new APIPlatform_Gateway_Error('version_not_found', 'API version not found.', 404);
            }
            $version = $first;
            array_shift($tail);
        }

        $state = sanitize_key(get_post_meta($api_id, 'apiplatform_gateway_version_state_' . sanitize_key($version), true) ?: 'published');
        if (in_array($state, ['draft', 'disabled'], true)) {
            return new APIPlatform_Gateway_Error($state === 'draft' ? 'version_not_found' : 'version_disabled', 'API version is not available.', $state === 'draft' ? 404 : 403);
        }

        return ['version' => $version, 'tail' => $tail];
    }

    private static function latest_published_version($api_id, array $schemas, $fallback){
        $versions = array_keys($schemas);
        usort($versions, 'version_compare');
        $versions = array_reverse($versions);

        foreach ($versions as $version) {
            $state = sanitize_key(get_post_meta($api_id, 'apiplatform_gateway_version_state_' . sanitize_key($version), true) ?: 'published');
            if (in_array($state, ['published', 'stable', 'deprecated'], true)) {
                return $version;
            }
        }

        return $fallback;
    }

    private static function resolve_endpoint(APIPlatform_Gateway_Request $request, array $tail){
        $schema = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::schema_for_version($request->api_id, $request->version)
            : null;
        if (!$schema && class_exists('APIPlatform_Endpoint_Schema_Service')) {
            $schema = APIPlatform_Endpoint_Schema_Service::current_schema($request->api_id);
        }
        $endpoints = is_array($schema['endpoints'] ?? null) ? $schema['endpoints'] : [];
        $tail_path = '/' . trim(implode('/', $tail), '/');

        if (!$endpoints) {
            return [
                'id' => 'legacy',
                'method' => $request->method,
                'path' => $tail_path === '/' ? '/' . $request->endpoint_slug : $tail_path,
                'responses' => [],
            ];
        }

        foreach ($endpoints as $endpoint) {
            $method = strtoupper($endpoint['method'] ?? 'GET');
            if ($method !== $request->method) {
                continue;
            }
            $path = '/' . ltrim($endpoint['path'] ?? '', '/');
            if ($request->source === 'rest' || $tail_path === '/' || $tail_path === $path || $tail_path === '/' . basename($path)) {
                return $endpoint;
            }
        }

        return $endpoints[0] ?? null;
    }

    private static function diagnostic($marker, array $context = []){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = ['marker=' . sanitize_text_field($marker)];
        foreach ($context as $key => $value) {
            $parts[] = sanitize_key($key) . '=' . sanitize_text_field((string) $value);
        }

        error_log('[FreedomAPI route diagnostic] ' . implode(' ', $parts));
    }
}

class APIPlatform_Runtime {
    public static function execute(APIPlatform_Gateway_Request $request){
        $type = sanitize_key(get_post_meta($request->api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal');

        $callback = APIPlatform_Gateway::runtime_callback($type);
        if ($callback) {
            return call_user_func($callback, $request);
        }

        if (in_array($type, ['rest_proxy', 'webhook_proxy', 'external_http'], true)) {
            return self::proxy($request);
        }

        return self::internal($request);
    }

    private static function internal(APIPlatform_Gateway_Request $request){
        $json = get_post_meta($request->api_id, 'api_json', true);
        if ($json !== '') {
            $preserve_objects = function_exists('freedomapi_has_response_transformation') && freedomapi_has_response_transformation($request->api_id, $request->version);
            $decoded = json_decode($json, !$preserve_objects);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || ($preserve_objects && is_object($decoded)))) {
                return new APIPlatform_Gateway_Response($decoded, 200);
            }
        }

        if (function_exists('apiplatform_execute_logic')) {
            $logic_response = apiplatform_execute_logic($request->api_id, array_merge($request->query, is_array($request->json) ? $request->json : []));
            if (is_array($logic_response) && !empty($logic_response)) {
                return new APIPlatform_Gateway_Response($logic_response, 200);
            }
        }

        if (class_exists('APIPlatform_Response_Renderer')) {
            $saved_tree = get_post_meta($request->api_id, 'api_builder_components', true);
            $component_tree = function_exists('apiplatform_builder_decode_json')
                ? apiplatform_builder_decode_json($saved_tree, [])
                : json_decode((string) $saved_tree, true);
            if (function_exists('apiplatform_builder_is_component_tree') && apiplatform_builder_is_component_tree($component_tree)) {
                $renderer = new APIPlatform_Response_Renderer();
                $rendered_data = $renderer->render_response($component_tree, array_merge($request->query, is_array($request->json) ? $request->json : []));
                if (is_array($rendered_data) && !empty($rendered_data)) {
                    return new APIPlatform_Gateway_Response($rendered_data, 200);
                }
            }
        }

        if ($json !== '') {
            return new APIPlatform_Gateway_Error('runtime_error', 'API response configuration is malformed.', 500);
        }

        return new APIPlatform_Gateway_Response(['message' => 'Hello'], 200);
    }

    private static function proxy(APIPlatform_Gateway_Request $request){
        $url = esc_url_raw(get_post_meta($request->api_id, 'apiplatform_gateway_proxy_url', true));
        $allowed_host = sanitize_text_field(get_post_meta($request->api_id, 'apiplatform_gateway_proxy_host', true));
        $timeout = max(1, min(15, absint(get_post_meta($request->api_id, 'apiplatform_gateway_proxy_timeout', true) ?: 5)));
        $validated = self::validate_proxy_url($url, $allowed_host);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $headers = self::proxy_headers($request);
        $args = [
            'method' => $request->method,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'body' => in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $request->body : null,
            'limit_response_size' => APIPlatform_Gateway::MAX_PROXY_BYTES,
        ];
        $url = add_query_arg(array_diff_key($request->query, ['api_key' => true]), $url);
        $upstream = wp_remote_request($url, $args);
        if (is_wp_error($upstream)) {
            self::diagnostic('FREEDOMAPI_GATEWAY_FORWARD_FAILED', [
                'reason' => 'upstream_unavailable',
                'target' => self::safe_target($url),
                'timeout' => $timeout,
            ]);
            return new APIPlatform_Gateway_Error('upstream_unavailable', 'Upstream API is unavailable.', 502);
        }

        $status = absint(wp_remote_retrieve_response_code($upstream)) ?: 502;
        $content_type = wp_remote_retrieve_header($upstream, 'content-type') ?: 'application/json';
        $body = wp_remote_retrieve_body($upstream);
        $preserve_objects = function_exists('freedomapi_has_response_transformation') && freedomapi_has_response_transformation($request->api_id, $request->version);
        $decoded = stripos($content_type, 'json') !== false ? json_decode($body, !$preserve_objects) : null;

        if ($status === 503) {
            self::diagnostic('FREEDOMAPI_GATEWAY_503', [
                'reason' => 'upstream_returned_503',
                'target' => self::safe_target($url),
                'timeout' => $timeout,
            ]);
        }

        return new APIPlatform_Gateway_Response(json_last_error() === JSON_ERROR_NONE && $decoded !== null ? $decoded : ['body' => $body], $status, ['Content-Type' => sanitize_text_field($content_type)]);
    }

    private static function safe_target($url){
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        $path = $parts['path'] ?? '/';
        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $path;
    }

    private static function diagnostic($marker, array $context = []){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = ['marker=' . sanitize_text_field($marker)];
        foreach ($context as $key => $value) {
            if (in_array($key, ['api_key', 'authorization', 'cookie', 'cookies', 'nonce', 'password'], true)) {
                continue;
            }
            $parts[] = sanitize_key($key) . '=' . sanitize_text_field((string) $value);
        }

        error_log('[FreedomAPI runtime diagnostic] ' . implode(' ', $parts));
    }

    private static function validate_proxy_url($url, $allowed_host){
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], ['https'], true)) {
            return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target must be a valid HTTPS URL.', 500);
        }
        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || substr($host, -6) === '.local') {
            return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target is not allowed.', 500);
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target credentials are not allowed.', 500);
        }
        if ($allowed_host !== '' && strtolower($allowed_host) !== $host) {
            return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target host is not allowed.', 500);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target is not allowed.', 500);
            }
        } elseif (function_exists('gethostbynamel')) {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return new APIPlatform_Gateway_Error('invalid_proxy_target', 'Proxy target resolves to an address that is not allowed.', 500);
                    }
                }
            }
        }
        return true;
    }

    private static function proxy_headers(APIPlatform_Gateway_Request $request){
        $allowed = ['accept', 'content-type', 'user-agent'];
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $name = strtolower($name);
            if (in_array($name, $allowed, true) && !preg_match('/[\r\n]/', $value)) {
                $headers[$name] = sanitize_text_field($value);
            }
        }
        $headers['x-freedomapi-request-id'] = $request->request_id;
        return $headers;
    }
}

class APIPlatform_Cache_Service {
    public static function get(APIPlatform_Gateway_Request $request){
        if (!self::cache_allowed($request)) {
            return null;
        }
        $settings = self::settings($request);
        if (empty($settings['enabled']) || !in_array($request->method, ['GET'], true)) {
            return null;
        }
        $key = self::key($request);
        $cached = get_transient($key);
        return is_array($cached) ? array_merge($cached, ['key' => $key]) : null;
    }

    public static function set(APIPlatform_Gateway_Request $request, APIPlatform_Gateway_Response $response){
        if (!self::cache_allowed($request)) {
            return;
        }
        $settings = self::settings($request);
        if (empty($settings['enabled']) || !in_array($request->method, ['GET'], true) || $response->status >= 400) {
            return;
        }
        set_transient(self::key($request), [
            'status' => $response->status,
            'headers' => $response->headers,
            'body' => $response->body,
        ], $settings['ttl']);
    }

    private static function cache_allowed(APIPlatform_Gateway_Request $request){
        // Never share authenticated legacy/published-key responses across identities.
        // Application keys have a stable, non-secret key id for cache partitioning.
        return ($request->auth_context['auth_type'] ?? '') === 'application'
            && absint($request->auth_context['application_id'] ?? 0) > 0
            && absint($request->auth_context['application_key_id'] ?? 0) > 0;
    }

    private static function settings(APIPlatform_Gateway_Request $request){
        return [
            'enabled' => (bool) get_post_meta($request->api_id, 'apiplatform_gateway_cache_enabled', true),
            'ttl' => max(1, min(3600, absint(get_post_meta($request->api_id, 'apiplatform_gateway_cache_ttl', true) ?: 60))),
        ];
    }

    private static function key(APIPlatform_Gateway_Request $request){
        $identity = ($request->auth_context['auth_type'] ?? '') === 'application'
            ? 'app:' . absint($request->auth_context['application_id'] ?? 0) . ':key:' . absint($request->auth_context['application_key_id'] ?? 0)
            : 'disabled';
        return 'apiplatform_gateway_' . md5(wp_json_encode([
            $request->owner_id,
            $request->api_id,
            $request->version,
            $request->endpoint['id'] ?? '',
            $request->query,
            $identity,
            function_exists('freedomapi_extension_state') ? freedomapi_extension_state($request->api_id)['revision'] : 0,
        ]));
    }
}

APIPlatform_Gateway::instance();

if (!function_exists('apiplatform_public_gateway_url')) {
    function apiplatform_public_gateway_url($api){
        $post = is_numeric($api) ? get_post(absint($api)) : $api;
        if (!$post || empty($post->post_name)) {
            return class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url() : home_url('/gateway');
        }

        $owner = class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($post->ID) : null;
        if ($owner && ($owner['owner_type'] ?? '') === 'organization') {
            $publisher_slug = sanitize_title($owner['owner_slug'] ?? '');
            if ($publisher_slug === '') {
                $publisher_slug = 'organization-' . absint($owner['owner_id'] ?? 0);
            }
        } else {
            $owner_id = function_exists('apiplatform_get_team_owner')
                ? absint(apiplatform_get_team_owner($post->post_author))
                : absint($post->post_author);
            $publisher = get_userdata($owner_id);
            $publisher_slug = $publisher
                ? sanitize_title($publisher->user_nicename ?: $publisher->user_login)
                : 'publisher';
        }

        return class_exists('APIPlatform_Routes')
            ? APIPlatform_Routes::gateway_url($publisher_slug, $post->post_name)
            : home_url('/gateway/' . $publisher_slug . '/' . $post->post_name);
    }
}










