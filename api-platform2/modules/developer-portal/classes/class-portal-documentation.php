<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal_Documentation {

    public static function normalize($api, $version = ''){
        $card = APIPlatform_Developer_Portal_Query::api_card($api);
        $snapshot = null;
        $snapshot_data = [];

        if (class_exists('APIPlatform_Documentation_Workspace')) {
            $snapshot = $version !== ''
                ? APIPlatform_Documentation_Workspace::snapshot_by_version($api->ID, $version)
                : APIPlatform_Documentation_Workspace::current_snapshot($api->ID);
        }

        $canonical = class_exists('APIPlatform_Frontend_API_Documentation')
            ? APIPlatform_Frontend_API_Documentation::documentation_data_for_api($api, (int) $api->post_author)
            : [];

        if ($snapshot) {
            $snapshot_data = APIPlatform_Documentation_Workspace::snapshot_data($snapshot);
            if (!empty($snapshot_data['documentation']) && is_array($snapshot_data['documentation'])) {
                $canonical = $snapshot_data['documentation'];
            }
            if (!empty($snapshot_data['version_label'])) {
                $card['version_label'] = (string) $snapshot_data['version_label'];
            }
        }

        $methods = array_values(array_unique(array_map('strtoupper', $canonical['api']['methods'] ?? ['GET'])));
        $endpoint = $canonical['api']['endpoint_url'] ?? $card['endpoint_url'];
        $parameters = $canonical['parameters'] ?? [];
        $body_example = $canonical['body_example'] ?? [
            'enabled' => false,
            'content_type' => 'application/json',
            'example' => '',
            'source' => 'Not documented',
        ];
        $example_response = $canonical['example_response'] ?? [
            'label' => 'Example response',
            'code' => self::example_response($api->ID),
        ];
        $errors = $canonical['errors'] ?? [];
        $rate_limit = $canonical['rate_limit'] ?? [
            'summary' => 'Rate limits are enforced by FreedomAPI and may vary by API owner plan.',
            'status' => '429 rate_limit_exceeded',
        ];

        $active_version = $snapshot['version_label'] ?? $card['version_label'];
        $schema = !empty($canonical['schema']) && is_array($canonical['schema'])
            ? $canonical['schema']
            : (class_exists('APIPlatform_Endpoint_Schema_Service')
                ? APIPlatform_Endpoint_Schema_Service::current_schema($api->ID)
                : []);
        $schema_endpoints = !empty($schema['endpoints']) && is_array($schema['endpoints'])
            ? self::schema_endpoints($schema['endpoints'], $endpoint, $card)
            : [];
        $models = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::models($api->ID, $active_version)
            : [];

        return [
            'api' => $card,
            'active_version' => $active_version,
            'snapshot' => $snapshot ?: null,
            'snapshots' => class_exists('APIPlatform_Documentation_Workspace') ? APIPlatform_Documentation_Workspace::snapshots($api->ID) : [],
            'releases' => class_exists('APIPlatform_Documentation_Workspace') ? APIPlatform_Documentation_Workspace::releases($api->ID, $active_version) : [],
            'all_releases' => class_exists('APIPlatform_Documentation_Workspace') ? APIPlatform_Documentation_Workspace::releases($api->ID) : [],
            'workspace' => !empty($snapshot_data['workspace']) && is_array($snapshot_data['workspace']) ? $snapshot_data['workspace'] : [
                'overview_markdown' => get_post_meta($api->ID, 'apiplatform_docs_overview', true),
                'authentication_markdown' => get_post_meta($api->ID, 'apiplatform_docs_authentication', true),
                'sdk_examples_markdown' => get_post_meta($api->ID, 'apiplatform_docs_sdk_examples', true),
                'faq_markdown' => get_post_meta($api->ID, 'apiplatform_docs_faq', true),
                'migration_guide_markdown' => get_post_meta($api->ID, 'apiplatform_docs_migration_guide', true),
            ],
            'authentication' => $canonical['authentication'] ?? [
                    'recommended' => 'Send your API key with the X-API-Key request header.',
                    'header' => 'X-API-Key: YOUR_API_KEY',
                    'legacy_url' => $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'api_key=YOUR_API_KEY',
                ],
            'endpoints' => $schema_endpoints,
            'schema' => $schema,
            'models' => is_array($models) ? $models : [],
            'parameters' => $parameters,
            'body_example' => $body_example,
            'example_response' => $example_response,
            'code_examples' => self::merge_code_examples($canonical['code_examples'] ?? [], self::code_examples($endpoint, $methods[0] ?? 'GET')),
            'rate_limit' => $rate_limit,
            'rate_limits' => $rate_limit['summary'] ?? 'Rate limits are enforced by FreedomAPI and may vary by API owner plan.',
            'common_errors' => $errors,
        ];
    }

    public static function example_response($api_id){
        $json = get_post_meta($api_id, 'api_json', true);

        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return '';
    }

    private static function parameters($api_id){
        $parameters = apply_filters('apiplatform_api_documentation_parameters', [], $api_id);
        return is_array($parameters) ? $parameters : [];
    }

    private static function code_examples($endpoint, $method){
        return [
            'curl' => "curl -X " . $method . " \"" . $endpoint . "\" \\\n  -H \"X-API-Key: YOUR_API_KEY\" \\\n  -H \"Accept: application/json\"",
            'javascript' => "const response = await fetch(\"" . $endpoint . "\", {\n  method: \"" . $method . "\",\n  headers: {\n    \"X-API-Key\": \"YOUR_API_KEY\",\n    \"Accept\": \"application/json\"\n  }\n});\n\nconst data = await response.json();",
            'node' => "import fetch from \"node-fetch\";\n\nconst response = await fetch(\"" . $endpoint . "\", {\n  method: \"" . $method . "\",\n  headers: {\n    \"X-API-Key\": \"YOUR_API_KEY\",\n    \"Accept\": \"application/json\"\n  }\n});\n\nconsole.log(await response.json());",
            'php' => "<?php\n\n\$ch = curl_init('" . $endpoint . "');\ncurl_setopt_array(\$ch, [\n    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_CUSTOMREQUEST => '" . $method . "',\n    CURLOPT_HTTPHEADER => [\n        'X-API-Key: YOUR_API_KEY',\n        'Accept: application/json',\n    ],\n]);\n\n\$response = curl_exec(\$ch);",
            'python' => "import requests\n\nresponse = requests.request(\n    \"" . $method . "\",\n    \"" . $endpoint . "\",\n    headers={\"X-API-Key\": \"YOUR_API_KEY\", \"Accept\": \"application/json\"},\n    timeout=30,\n)\nprint(response.json())",
            'csharp' => "using var client = new HttpClient();\nusing var request = new HttpRequestMessage(HttpMethod." . self::csharp_method($method) . ", \"" . $endpoint . "\");\nrequest.Headers.Add(\"X-API-Key\", \"YOUR_API_KEY\");\nrequest.Headers.Add(\"Accept\", \"application/json\");\n\nusing var response = await client.SendAsync(request);\nvar json = await response.Content.ReadAsStringAsync();",
        ];
    }

    private static function merge_code_examples(array $stored, array $generated){
        foreach ($generated as $key => $code) {
            if (empty($stored[$key])) {
                $stored[$key] = $code;
            }
        }
        return $stored;
    }

    private static function csharp_method($method){
        $method = strtoupper($method);
        return [
            'GET' => 'Get',
            'POST' => 'Post',
            'PUT' => 'Put',
            'PATCH' => 'Patch',
            'DELETE' => 'Delete',
        ][$method] ?? 'Get';
    }

    private static function schema_endpoints(array $endpoints, $base_endpoint, array $card){
        $out = [];
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $path = $endpoint['path'] ?? ('/platform/v1/api/' . $card['runtime_slug']);
            $url = self::endpoint_url($base_endpoint, $path, $card);
            $responses = $endpoint['responses'] ?? [];
            $first_response = is_array($responses) && isset($responses[0]) ? $responses[0] : [];
            $body = $endpoint['request_body'] ?? [];
            $body_example = array_key_exists('example', $body)
                ? wp_json_encode($body['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
            $method = strtoupper($endpoint['method'] ?? 'GET');
            $summary = trim((string) ($endpoint['summary'] ?? ''));
            $out[] = [
                'id' => sanitize_title($endpoint['id'] ?? (($endpoint['method'] ?? 'GET') . '-' . $path)),
                'title' => sanitize_text_field($summary !== '' ? $summary : ($method . ' ' . $path)),
                'method' => $method,
                'methods' => array_values(array_unique(array_map('strtoupper', $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET']))),
                'path' => $path,
                'url' => $url,
                'description' => sanitize_textarea_field($endpoint['description'] ?? ''),
                'parameters' => $endpoint['parameters'] ?? [],
                'body_example' => [
                    'enabled' => !empty($body['enabled']),
                    'content_type' => $body['content_type'] ?? 'application/json',
                    'example' => $body_example,
                    'source' => 'Auto-generated from endpoint schema.',
                    'fields' => $body['fields'] ?? [],
                ],
                'body_schema' => $body['fields'] ?? [],
                'example_response' => isset($first_response['example']) ? wp_json_encode($first_response['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
                'example_response_label' => 'Auto-generated from endpoint schema',
                'response_codes' => $responses,
                'deprecated' => !empty($endpoint['deprecated']),
                'version' => $endpoint['version'] ?? $card['version_label'],
                'models' => $endpoint['models'] ?? [],
            ];
        }
        return $out;
    }

    private static function endpoint_url($base_endpoint, $path, array $card){
        $path = '/' . ltrim((string) $path, '/');
        $legacy_path = '/platform/v1/api/' . $card['runtime_slug'];

        if ($path === $legacy_path || $path === '/' . $card['runtime_slug']) {
            return $base_endpoint;
        }

        return trailingslashit($base_endpoint) . ltrim($path, '/');
    }

}
