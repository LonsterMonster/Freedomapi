<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_API_Tester {

    public function __construct(){
        add_shortcode('api_tester_page', [$this, 'render']);
        add_action('wp_ajax_apiplatform_test_api', [$this, 'handle_test_request']);
    }

    public function render(){
        if (!is_user_logged_in()) {
            return APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'Login required'
            ]);
        }

        $user_id = get_current_user_id();
        $api_id = $this->get_requested_api_id();
        $api = $api_id ? get_post($api_id) : $this->get_first_user_api($user_id);

        if (!$this->user_owns_api($api, $user_id)) {
            return $this->render_page(APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'You cannot test that API.'
            ]));
        }

        $api_id = absint($api->ID);
        $endpoint = $this->endpoint_url($api);
        $methods = $this->supported_methods($api);

        APIPlatform_Frontend_Assets::enqueue_api_tester([
            'apiId' => $api_id,
            'nonce' => wp_create_nonce('apiplatform_test_api_' . $api_id),
            'endpoint' => $endpoint,
            'methods' => $methods,
            'apiName' => get_the_title($api),
            'schema' => class_exists('APIPlatform_Endpoint_Schema_Service') ? APIPlatform_Endpoint_Schema_Service::current_schema($api_id) : [],
        ]);

        $content = APIPlatform_Renderer::partial('api-tester', [
            'api' => [
                'id' => $api_id,
                'name' => get_the_title($api),
                'slug' => $api->post_name,
                'endpoint_url' => $endpoint,
            ],
            'methods' => $methods,
            'nonce' => wp_create_nonce('apiplatform_test_api_' . $api_id),
            'keys_url' => $this->keys_page_url(),
            'edit_url' => $this->edit_api_url($api_id),
            'docs_url' => $this->documentation_url($api_id),
            'history_url' => $this->request_history_url($api_id),
        ]);

        return $this->render_page($content);
    }

    public function handle_test_request(){
        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message' => 'Login required.'
            ], 401);
        }

        $user_id = get_current_user_id();
        $api_id = isset($_POST['api_id']) ? absint($_POST['api_id']) : 0;
        $nonce = isset($_POST['nonce'])
            ? sanitize_text_field(wp_unslash($_POST['nonce']))
            : '';

        if (!$api_id || !wp_verify_nonce($nonce, 'apiplatform_test_api_' . $api_id)) {
            wp_send_json_error([
                'message' => 'Security check failed. Please refresh and try again.'
            ], 403);
        }

        $api = get_post($api_id);

        if (!$this->user_owns_api($api, $user_id)) {
            wp_send_json_error([
                'message' => 'You cannot test that API.'
            ], 403);
        }

        $method = isset($_POST['method'])
            ? strtoupper(sanitize_key(wp_unslash($_POST['method'])))
            : 'GET';

        $methods = $this->supported_methods($api);

        if (!in_array($method, $methods, true)) {
            wp_send_json_error([
                'message' => 'That HTTP method is not supported for this API.'
            ], 400);
        }

        $api_key = isset($_POST['api_key'])
            ? sanitize_text_field(wp_unslash($_POST['api_key']))
            : '';

        $payload = $this->decode_payload();
        $query_params = $this->sanitize_pairs($payload['query_params'] ?? []);
        $headers = $this->sanitize_headers($payload['headers'] ?? []);
        $body = isset($payload['body']) ? (string) $payload['body'] : '';
        $body_data = null;

        if ($this->method_allows_body($method) && trim($body) !== '') {
            $body_data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error([
                    'message' => 'Request body must be valid JSON.'
                ], 400);
            }
        }

        $request = new WP_REST_Request($method, '/platform/v1/api/' . $api->post_name);

        foreach ($query_params as $key => $value) {
            $request->set_param($key, $value);
        }

        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        if ($api_key !== '') {
            $request->set_header('Authorization', 'Bearer ' . $api_key);
        }

        if ($body_data !== null) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($body_data));
            $request->set_body_params($body_data);
        }

        $start = microtime(true);
        $response = rest_do_request($request);
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            wp_send_json_success([
                'status' => absint($response->get_error_data('status') ?: 500),
                'statusText' => 'Error',
                'responseTimeMs' => $elapsed,
                'headers' => [],
                'body' => [
                    'code' => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                ],
            ]);
        }

        $status = absint($response->get_status());

        wp_send_json_success([
            'status' => $status,
            'statusText' => $this->status_text($status),
            'responseTimeMs' => $elapsed,
            'headers' => $this->sanitize_response_headers($response->get_headers()),
            'body' => $response->get_data(),
        ]);
    }

    private function decode_payload(){
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '{}';
        $payload = json_decode((string) $raw, true);

        return is_array($payload) ? $payload : [];
    }

    private function sanitize_pairs($pairs){
        $sanitized = [];

        if (!is_array($pairs)) {
            return $sanitized;
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = $this->sanitize_token($pair['key'] ?? '');
            $value = sanitize_text_field((string) ($pair['value'] ?? ''));

            if ($key === '') {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function sanitize_headers($pairs){
        $headers = [];
        $blocked = [
            'authorization',
            'cookie',
            'host',
            'x-api-key',
            'content-length',
            'connection',
            'transfer-encoding',
            'set-cookie',
        ];

        if (!is_array($pairs)) {
            return $headers;
        }

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = $this->sanitize_token($pair['key'] ?? '');
            $value = sanitize_text_field((string) ($pair['value'] ?? ''));

            if ($key === '' || in_array(strtolower($key), $blocked, true)) {
                continue;
            }

            $headers[$key] = $value;
        }

        return $headers;
    }

    private function sanitize_token($value){
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $value);

        return substr($value, 0, 80);
    }

    private function sanitize_response_headers($headers){
        $safe = [];

        if (!is_array($headers)) {
            return $safe;
        }

        foreach ($headers as $key => $value) {
            $key = $this->sanitize_token($key);

            if ($key === '') {
                continue;
            }

            $safe[$key] = is_array($value)
                ? array_map('sanitize_text_field', $value)
                : sanitize_text_field((string) $value);
        }

        return $safe;
    }

    private function supported_methods($api){
        $methods = ['GET'];

        return array_values(array_unique(array_map('strtoupper', apply_filters('apiplatform_api_tester_supported_methods', $methods, $api))));
    }

    private function method_allows_body($method){
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function get_requested_api_id(){
        if (isset($_GET['api_id'])) {
            return absint($_GET['api_id']);
        }

        return 0;
    }

    private function get_first_user_api($user_id){
        if (class_exists('APIPlatform_Organization_Permissions')) {
            $ids = APIPlatform_Organization_Permissions::user_api_ids($user_id, 'apis.view');
            if ($ids) {
                $apis = get_posts([
                    'post_type' => 'user_api',
                    'post__in' => $ids,
                    'post_status' => ['publish', 'draft', 'pending', 'private'],
                    'numberposts' => 1,
                    'no_found_rows' => true,
                ]);
                return $apis ? $apis[0] : null;
            }
        }

        $apis = get_posts([
            'post_type' => 'user_api',
            'author' => $user_id,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);

        return $apis ? $apis[0] : null;
    }

    private function user_owns_api($api, $user_id){
        return $api &&
            $api->post_type === 'user_api' &&
            (
                class_exists('APIPlatform_Ownership_Service')
                    ? APIPlatform_Ownership_Service::can($user_id, $api->ID, 'apis.edit')
                    : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'))
            );
    }

    private function endpoint_url($api){
        return function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api)
            : (
                class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                    : rest_url('platform/v1/api/' . $api->post_name)
            );
    }

    private function test_api_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'test-api', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/test-api'));
    }

    private function edit_api_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'edit-api', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/edit-api'));
    }

    private function documentation_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'api-documentation', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/api-documentation'));
    }

    private function request_history_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'request-history', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/request-history'));
    }

    private function keys_page_url(){
        return current_user_can('manage_options')
            ? add_query_arg('apipage', 'keys', (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : site_url('/keys');
    }

    private function status_text($status){
        $text = get_status_header_desc($status);

        return $text ?: '';
    }

    private function render_page($content){
        return APIPlatform_Renderer::component('section', [
            'content' => APIPlatform_Renderer::component('stack', [
                'items' => [
                    '<h1>Test API</h1>',
                    $content
                ]
            ])
        ]);
    }
}
