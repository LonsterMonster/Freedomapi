<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_API_Documentation {

    public function __construct(){
        add_shortcode('api_documentation_detail_page', [$this, 'render']);
    }

    public function render(){
        if (!is_user_logged_in()) {
            return APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'Login required to view API documentation.'
            ]);
        }

        $user_id = get_current_user_id();
        $api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;
        $api = $api_id ? get_post($api_id) : null;

        if (!$this->user_owns_api($api, $user_id)) {
            return $this->render_page(APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'You cannot view documentation for that API.'
            ]));
        }

        APIPlatform_Frontend_Assets::enqueue_api_documentation();

        $data = $this->documentation_data($api, $user_id);

        $content = APIPlatform_Renderer::partial('api-documentation', $data);

        return $this->render_page($content);
    }

    private function documentation_data($api, $user_id){
        return self::documentation_data_for_api($api, $user_id);
    }

    public static function documentation_data_for_api($api, $user_id = 0){
        $endpoint = self::endpoint_url($api);
        $methods = self::supported_methods($api);
        $parameters = self::parameters($api->ID);
        $example_response = self::example_response($api->ID);
        $body_example = self::request_body_example($api->ID, $methods);

        return apply_filters('apiplatform_api_documentation_data', [
            'api' => [
                'id' => absint($api->ID),
                'name' => get_the_title($api),
                'slug' => $api->post_name,
                'description' => $api->post_content,
                'status' => get_post_meta($api->ID, 'apiplatform_status', true) ?: 'active',
                'endpoint_url' => $endpoint,
                'methods' => $methods,
            ],
            'actions' => [
                'tester_url' => self::test_api_url($api->ID),
                'edit_url' => self::edit_api_url($api->ID),
                'history_url' => self::request_history_url($api->ID),
                'keys_url' => self::keys_page_url(),
            ],
            'authentication' => self::authentication_examples($endpoint),
            'parameters' => $parameters,
            'body_example' => $body_example,
            'code_examples' => self::code_examples($endpoint, $methods, $body_example),
            'example_response' => $example_response,
            'errors' => self::error_definitions(),
            'rate_limit' => self::rate_limit_data($user_id),
        ], $api, $user_id);
    }

    private static function authentication_examples($endpoint){
        $auth = [
            'header' => 'X-API-Key: YOUR_API_KEY',
            'bearer' => 'Authorization: Bearer YOUR_API_KEY',
            'legacy_url' => $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'api_key=YOUR_API_KEY',
        ];

        return apply_filters('apiplatform_api_documentation_authentication', $auth, $endpoint);
    }

    private static function parameters($api_id){
        $candidate_keys = [
            'api_parameters',
            'apiplatform_parameters',
            'api_request_parameters',
            'api_docs_parameters',
        ];

        $parameters = [];

        foreach ($candidate_keys as $meta_key) {
            $value = get_post_meta($api_id, $meta_key, true);

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }

            if (is_array($value) && !empty($value)) {
                $parameters = $value;
                break;
            }
        }

        $parameters = apply_filters('apiplatform_api_documentation_parameters', $parameters, $api_id);

        if (!is_array($parameters)) {
            return [];
        }

        $normalized = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = sanitize_text_field((string) ($parameter['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'location' => sanitize_text_field((string) ($parameter['location'] ?? 'Query')),
                'type' => sanitize_text_field((string) ($parameter['type'] ?? 'String')),
                'required' => !empty($parameter['required']),
                'default' => sanitize_text_field((string) ($parameter['default'] ?? '')),
                'description' => sanitize_text_field((string) ($parameter['description'] ?? '')),
                'example' => sanitize_text_field((string) ($parameter['example'] ?? '')),
            ];
        }

        return $normalized;
    }

    private static function request_body_example($api_id, array $methods){
        if (!array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return [
                'enabled' => false,
                'content_type' => 'application/json',
                'example' => '',
                'source' => 'No request body required for the supported methods.'
            ];
        }

        $body = get_post_meta($api_id, 'api_request_body_example', true);

        if (is_string($body) && trim($body) !== '') {
            $decoded = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'enabled' => true,
                    'content_type' => 'application/json',
                    'example' => wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'source' => 'Configured request body example.'
                ];
            }
        }

        return [
            'enabled' => true,
            'content_type' => 'application/json',
            'example' => wp_json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'source' => 'No request body schema is configured.'
        ];
    }

    private static function example_response($api_id){
        $json = get_post_meta($api_id, 'api_json', true);

        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'label' => 'Configured example response',
                    'code' => wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        $logic = get_post_meta($api_id, 'api_logic', true);
        $decoded_logic = is_string($logic) ? json_decode($logic, true) : [];

        if (is_array($decoded_logic) && !empty($decoded_logic['steps'])) {
            foreach ($decoded_logic['steps'] as $step) {
                if (($step['type'] ?? '') === 'response' && isset($step['data'])) {
                    return [
                        'label' => 'Generated from stored response configuration',
                        'code' => wp_json_encode($step['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ];
                }
            }
        }

        $fallback = apply_filters('apiplatform_api_documentation_example_response', [
            'message' => 'Example response placeholder'
        ], $api_id);

        return [
            'label' => 'Generic placeholder response',
            'code' => wp_json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private static function code_examples($endpoint, array $methods, array $body_example){
        $method = $methods[0] ?? 'GET';
        $body = !empty($body_example['enabled']) ? $body_example['example'] : '';
        $has_body = $body !== '' && $body !== '{}';

        $examples = [
            'curl' => [
                'title' => 'curl',
                'language' => 'bash',
                'code' => self::curl_example($endpoint, $method, $body, $has_body),
            ],
            'javascript' => [
                'title' => 'JavaScript fetch',
                'language' => 'javascript',
                'code' => self::javascript_example($endpoint, $method, $body, $has_body),
            ],
            'php' => [
                'title' => 'PHP',
                'language' => 'php',
                'code' => self::php_example($endpoint, $method, $body, $has_body),
            ],
            'python' => [
                'title' => 'Python',
                'language' => 'python',
                'code' => self::python_example($endpoint, $method, $body, $has_body),
            ],
            'browser' => [
                'title' => 'Browser URL (legacy)',
                'language' => 'text',
                'code' => $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'api_key=YOUR_API_KEY',
            ],
        ];

        return apply_filters('apiplatform_api_documentation_code_examples', $examples, $endpoint, $methods, $body_example);
    }

    private static function curl_example($endpoint, $method, $body, $has_body){
        $lines = [
            'curl -X ' . $method . ' "' . $endpoint . '" \\',
            '  -H "X-API-Key: YOUR_API_KEY" \\',
            '  -H "Accept: application/json"'
        ];

        if ($has_body) {
            $lines[] = '  -H "Content-Type: application/json" \\';
            $lines[] = "  --data '" . $body . "'";
        }

        return implode("\n", $lines);
    }

    private static function javascript_example($endpoint, $method, $body, $has_body){
        $body_line = $has_body
            ? ",\n    body: JSON.stringify(" . $body . ")"
            : '';

        return 'const response = await fetch(' . "\n" .
            '  "' . $endpoint . '",' . "\n" .
            '  {' . "\n" .
            '    method: "' . $method . '",' . "\n" .
            '    headers: {' . "\n" .
            '      "X-API-Key": "YOUR_API_KEY",' . "\n" .
            '      "Accept": "application/json"' . ($has_body ? ",\n      \"Content-Type\": \"application/json\"" : '') . "\n" .
            '    }' . $body_line . "\n" .
            '  }' . "\n" .
            ');' . "\n\n" .
            'if (!response.ok) {' . "\n" .
            '  throw new Error(`Request failed: ${response.status}`);' . "\n" .
            '}' . "\n\n" .
            'const data = await response.json();' . "\n" .
            'console.log(data);';
    }

    private static function php_example($endpoint, $method, $body, $has_body){
        $body_setup = $has_body
            ? "\n\$body = '" . str_replace("'", "\\'", $body) . "';\n"
            : '';
        $post_fields = $has_body
            ? "\n    CURLOPT_POSTFIELDS => \$body,"
            : '';
        $content_header = $has_body
            ? "\n        'Content-Type: application/json',"
            : '';

        return "<?php\n\n" .
            "\$endpoint = '" . $endpoint . "';\n" .
            "\$apiKey = 'YOUR_API_KEY';\n" .
            $body_setup .
            "\$ch = curl_init(\$endpoint);\n\n" .
            "curl_setopt_array(\$ch, [\n" .
            "    CURLOPT_RETURNTRANSFER => true,\n" .
            "    CURLOPT_CUSTOMREQUEST => '" . $method . "'," . $post_fields . "\n" .
            "    CURLOPT_HTTPHEADER => [\n" .
            "        'X-API-Key: ' . \$apiKey,\n" .
            "        'Accept: application/json'," . $content_header . "\n" .
            "    ],\n" .
            "]);\n\n" .
            "\$response = curl_exec(\$ch);\n" .
            "\$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n\n" .
            "if (\$response === false) {\n" .
            "    throw new RuntimeException(curl_error(\$ch));\n" .
            "}\n\n" .
            "curl_close(\$ch);\n\n" .
            "\$data = json_decode(\$response, true);\n\n" .
            "var_dump(\$status, \$data);";
    }

    private static function python_example($endpoint, $method, $body, $has_body){
        $json_arg = $has_body
            ? ",\n    json=" . self::python_literal(json_decode($body, true))
            : '';

        return "import requests\n\n" .
            "endpoint = \"" . $endpoint . "\"\n\n" .
            "response = requests.request(\n" .
            "    \"" . $method . "\",\n" .
            "    endpoint,\n" .
            "    headers={\n" .
            "        \"X-API-Key\": \"YOUR_API_KEY\",\n" .
            "        \"Accept\": \"application/json\",\n" .
            "    },\n" .
            "    timeout=30" . $json_arg . ",\n" .
            ")\n\n" .
            "response.raise_for_status()\n" .
            "print(response.json())";
    }

    private static function python_literal($value){
        if (is_array($value)) {
            return str_replace(
                [': true', ': false', ': null'],
                [': True', ': False', ': None'],
                wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        return '{}';
    }

    private static function error_definitions(){
        return apply_filters('apiplatform_api_documentation_errors', [
            ['status' => 400, 'code' => 'invalid_request', 'meaning' => 'The endpoint or request is malformed.', 'resolution' => 'Check the endpoint path and request format.'],
            ['status' => 401, 'code' => 'authentication_required', 'meaning' => 'No API key was provided.', 'resolution' => 'Send X-API-Key with a valid key.'],
            ['status' => 403, 'code' => 'invalid_api_key', 'meaning' => 'The API key is invalid, disabled, or expired.', 'resolution' => 'Use an active key owned by this API.'],
            ['status' => 403, 'code' => 'api_disabled', 'meaning' => 'The API is inactive or disabled.', 'resolution' => 'Enable the API before calling it.'],
            ['status' => 403, 'code' => 'forbidden', 'meaning' => 'The request is blocked or banned.', 'resolution' => 'Review account status and platform access.'],
            ['status' => 404, 'code' => 'api_not_found', 'meaning' => 'No active API exists for that slug.', 'resolution' => 'Check the endpoint slug.'],
            ['status' => 429, 'code' => 'rate_limit_exceeded', 'meaning' => 'The request limit was exceeded.', 'resolution' => 'Wait for the limit window to reset or upgrade the plan.'],
            ['status' => 500, 'code' => 'internal_server_error', 'meaning' => 'The API could not complete the response.', 'resolution' => 'Review the saved API response configuration.'],
        ]);
    }

    private static function rate_limit_data($user_id){
        if (!function_exists('apiplatform_get_plan_limits')) {
            return [
                'summary' => 'No custom rate-limit metadata is configured. Platform rate limits may still apply.',
                'status' => '429 rate_limit_exceeded',
            ];
        }

        $limits = apiplatform_get_plan_limits($user_id);
        $limit = $limits['request_limit'] ?? null;
        $type = $limits['type'] ?? 'plan';
        $window = $limits['window'] ?? $limits['time_window'] ?? $limits['reset_window'] ?? '';

        return [
            'summary' => APIPlatform_Membership_Panel::is_unlimited($limit)
                ? 'This account currently has unlimited plan-level requests.'
                : 'Plan-level request limit: ' . number_format((int) $limit) . ' requests.',
            'scope' => sanitize_text_field((string) $type),
            'window' => $window
                ? sanitize_text_field((string) $window)
                : 'No explicit reset window is exposed by the current plan metadata.',
            'status' => '429 rate_limit_exceeded',
        ];
    }

    private static function supported_methods($api){
        $methods = ['GET'];

        return array_values(array_unique(array_map('strtoupper', apply_filters('apiplatform_api_documentation_supported_methods', $methods, $api))));
    }

    private function user_owns_api($api, $user_id){
        return $api &&
            $api->post_type === 'user_api' &&
            (
                class_exists('APIPlatform_Ownership_Service')
                    ? APIPlatform_Ownership_Service::can($user_id, $api->ID, 'apis.manage_docs')
                    : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'))
            );
    }

    private static function endpoint_url($api){
        return function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api)
            : (
                class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                    : rest_url('platform/v1/api/' . $api->post_name)
            );
    }

    private function documentation_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'api-documentation', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/api-documentation'));
    }

    private static function test_api_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'test-api', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/test-api'));
    }

    private static function edit_api_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'edit-api', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/edit-api'));
    }

    private static function request_history_url($api_id){
        return current_user_can('manage_options')
            ? add_query_arg(['apipage' => 'request-history', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : add_query_arg('api_id', absint($api_id), site_url('/request-history'));
    }

    private static function keys_page_url(){
        return current_user_can('manage_options')
            ? add_query_arg('apipage', 'keys', (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : site_url('/keys');
    }

    private function render_page($content){
        return APIPlatform_Renderer::component('section', [
            'class' => 'apiplatform-api-documentation-section',
            'content' => APIPlatform_Renderer::component('stack', [
                'items' => [
                    '<h1>API Documentation</h1>',
                    $content
                ]
            ])
        ]);
    }
}
