<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal_Tester {

    private const MAX_QUERY_PARAMS = 20;
    private const MAX_HEADERS = 10;
    private const MAX_BODY_BYTES = 20000;
    private const MAX_VALUE_LENGTH = 1000;

    public function __construct(){
        add_action('wp_ajax_apiplatform_portal_test', [$this, 'handle']);
        add_action('wp_ajax_nopriv_apiplatform_portal_test', [$this, 'handle']);
    }

    public function handle(){
        $slug = sanitize_title(wp_unslash($_POST['portal_slug'] ?? ''));
        $api = APIPlatform_Developer_Portal_Query::resolve_api($slug, true);

        if (!$api) {
            wp_send_json_error(['message' => 'API unavailable.'], 404);
        }

        $card = APIPlatform_Developer_Portal_Query::api_card($api);

        if (class_exists('APIPlatform_Lifecycle_Policy') && !APIPlatform_Lifecycle_Policy::public_route_allowed($card, 'test')) {
            wp_send_json_error(['message' => 'Testing unavailable.'], 404);
        }

        if (empty($card['allow_public_tester'])) {
            wp_send_json_error(['message' => 'Testing unavailable.'], 404);
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));

        if (!wp_verify_nonce($nonce, 'apiplatform_portal_test_' . $card['slug'])) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
        }

        $docs = APIPlatform_Developer_Portal_Documentation::normalize($api);
        $endpoint = $this->resolve_endpoint($docs, sanitize_key(wp_unslash($_POST['endpoint_id'] ?? 'default-endpoint')));

        if (!$endpoint) {
            wp_send_json_error(['message' => 'Endpoint unavailable.'], 400);
        }

        $method = strtoupper(sanitize_key(wp_unslash($_POST['method'] ?? 'GET')));
        $allowed_methods = $this->endpoint_methods($endpoint);

        if (!in_array($method, $allowed_methods, true)) {
            wp_send_json_error(['message' => 'That HTTP method is not supported for this endpoint.'], 400);
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        if ($api_key === '') {
            wp_send_json_error(['message' => 'Enter an API key to test this endpoint.'], 400);
        }

        $payload = $this->decode_payload();
        $query_params = $this->sanitize_pairs($payload['query_params'] ?? [], $this->allowed_parameter_names($endpoint, 'query'), self::MAX_QUERY_PARAMS);
        $headers = $this->sanitize_headers($payload['headers'] ?? [], $this->allowed_parameter_names($endpoint, 'header'));
        $body = isset($payload['body']) ? (string) $payload['body'] : '';
        $body_data = null;

        if (strlen($body) > self::MAX_BODY_BYTES) {
            wp_send_json_error(['message' => 'Request body is too large for the public tester.'], 400);
        }

        if ($this->method_allows_body($method) && trim($body) !== '') {
            $body_data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(['message' => 'Request body must be valid JSON.'], 400);
            }
        }

        $path = '/platform/v1/api/' . $api->post_name;
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $api_key);
        $request->set_header('Accept', 'application/json');

        foreach ($query_params as $key => $value) {
            $request->set_param($key, $value);
        }

        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        if ($body_data !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body_data));
            $request->set_body_params($body_data);
        }

        $start = microtime(true);
        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            $error_body = [
                'code' => $response->get_error_code(),
                'message' => $response->get_error_message(),
            ];
            wp_send_json_success([
                'status' => absint($response->get_error_data('status') ?: 500),
                'statusText' => 'Error',
                'responseTimeMs' => (int) round((microtime(true) - $start) * 1000),
                'requestId' => sanitize_text_field($response->get_error_data('request_id') ?: ''),
                'responseSize' => strlen(wp_json_encode($error_body)),
                'headers' => [],
                'body' => $error_body,
                'curl' => $this->curl_example($method, $endpoint['url'] ?? $card['endpoint_url'], $query_params, $headers, $body),
            ]);
        }

        $status = absint($response->get_status());
        $response_headers = $this->sanitize_response_headers($response->get_headers());
        $response_body = $response->get_data();

        $payload = [
            'status' => $status,
            'statusText' => get_status_header_desc($status) ?: '',
            'responseTimeMs' => (int) round((microtime(true) - $start) * 1000),
            'requestId' => sanitize_text_field($response_headers['X-Request-ID'] ?? $response_headers['x-request-id'] ?? ''),
            'responseSize' => strlen(wp_json_encode($response_body)),
            'headers' => $response_headers,
            'body' => $response_body,
            'curl' => $this->curl_example($method, $endpoint['url'] ?? $card['endpoint_url'], $query_params, $headers, $body),
        ];

        wp_send_json_success($payload, 200);
    }

    private function decode_payload(){
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '{}';
        $payload = json_decode((string) $raw, true);

        return is_array($payload) ? $payload : [];
    }

    private function resolve_endpoint(array $docs, $endpoint_id){
        foreach ($docs['endpoints'] ?? [] as $endpoint) {
            if (($endpoint['id'] ?? '') === $endpoint_id) {
                return $endpoint;
            }
        }

        return ($docs['endpoints'][0] ?? null) ?: null;
    }

    private function endpoint_methods(array $endpoint){
        $methods = $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET'];
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $normalized = [];

        foreach ($methods as $method) {
            $method = strtoupper(sanitize_key($method));

            if (in_array($method, $allowed, true)) {
                $normalized[] = $method;
            }
        }

        return array_values(array_unique($normalized ?: ['GET']));
    }

    private function allowed_parameter_names(array $endpoint, $location){
        $names = [];

        foreach ($endpoint['parameters'] ?? [] as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $parameter_location = sanitize_key($parameter['location'] ?? $parameter['in'] ?? '');

            if ($parameter_location !== $location) {
                continue;
            }

            $name = sanitize_key($this->sanitize_token($parameter['name'] ?? ''));

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    private function sanitize_pairs($pairs, array $allowed_names, $limit){
        $sanitized = [];

        if (!is_array($pairs)) {
            return $sanitized;
        }

        if (count($pairs) > $limit) {
            wp_send_json_error(['message' => 'Too many request fields for the public tester.'], 400);
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = sanitize_key($this->sanitize_token($pair['key'] ?? ''));
            $value = sanitize_text_field(substr((string) ($pair['value'] ?? ''), 0, self::MAX_VALUE_LENGTH));

            if ($key === '' || !in_array($key, $allowed_names, true)) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function sanitize_headers($pairs, array $allowed_names){
        $blocked = [
            'authorization',
            'cookie',
            'host',
            'x-api-key',
            'x-forwarded-for',
            'content-length',
            'connection',
            'transfer-encoding',
            'set-cookie',
        ];
        $headers = [];

        if (!is_array($pairs)) {
            return $headers;
        }

        if (count($pairs) > self::MAX_HEADERS) {
            wp_send_json_error(['message' => 'Too many request headers for the public tester.'], 400);
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = sanitize_key($this->sanitize_token($pair['key'] ?? ''));
            $value = sanitize_text_field(substr((string) ($pair['value'] ?? ''), 0, self::MAX_VALUE_LENGTH));
            $lower = strtolower($key);

            if ($key === '' || in_array($lower, $blocked, true) || !in_array($key, $allowed_names, true)) {
                continue;
            }

            $headers[$key] = $value;
        }

        return $headers;
    }

    private function sanitize_response_headers($headers){
        $safe = [];
        $blocked = ['authorization', 'cookie', 'set-cookie', 'x-api-key'];

        if (!is_array($headers)) {
            return $safe;
        }

        foreach ($headers as $key => $value) {
            $key = $this->sanitize_token($key);

            if ($key === '' || in_array(strtolower($key), $blocked, true)) {
                continue;
            }

            $safe[$key] = is_array($value)
                ? array_map('sanitize_text_field', $value)
                : sanitize_text_field((string) $value);
        }

        return $safe;
    }

    private function sanitize_token($value){
        return substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $value), 0, 80);
    }

    private function method_allows_body($method){
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function curl_example($method, $url, array $query_params, array $headers, $body){
        if (!empty($query_params)) {
            $url = add_query_arg($query_params, $url);
        }

        $parts = [
            'curl',
            '-X',
            $this->shell_quote($method),
            $this->shell_quote($url),
            '-H',
            $this->shell_quote('Authorization: Bearer YOUR_API_KEY'),
            '-H',
            $this->shell_quote('Accept: application/json'),
        ];

        foreach ($headers as $key => $value) {
            $parts[] = '-H';
            $parts[] = $this->shell_quote($key . ': ' . $value);
        }

        if ($this->method_allows_body($method) && trim($body) !== '') {
            $parts[] = '-H';
            $parts[] = $this->shell_quote('Content-Type: application/json');
            $parts[] = '--data';
            $parts[] = $this->shell_quote(trim($body));
        }

        return implode(' ', $parts);
    }

    private function shell_quote($value){
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
    }
}
