<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_OpenAPI_Service {

    public static function parse($raw, $source = 'json'){
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['ok' => false, 'message' => 'OpenAPI source is empty.'];
        }

        if (strlen($raw) > 1048576) {
            return ['ok' => false, 'message' => 'OpenAPI source is too large. Limit imports to 1 MB.'];
        }

        $source = sanitize_key($source);
        $decoded = null;

        if ($source === 'json' || strpos($raw, '{') === 0) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['ok' => false, 'message' => 'Malformed JSON: ' . json_last_error_msg()];
            }
        } else {
            $decoded = self::parse_yaml($raw);
            if (!is_array($decoded)) {
                return ['ok' => false, 'message' => 'Malformed YAML or unsupported YAML structure.'];
            }
        }

        return self::validate_spec($decoded);
    }

    public static function preview($api_id, array $spec, $version){
        $mapped = self::map_to_freedom_schema($api_id, $spec, $version);
        if (empty($mapped['ok'])) {
            return $mapped;
        }

        $current = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::schema_for_version($api_id, $version)
            : ['endpoints' => []];
        $current = is_array($current) ? $current : ['endpoints' => []];
        $current_endpoints = [];
        foreach (($current['endpoints'] ?? []) as $endpoint) {
            $current_endpoints[strtoupper($endpoint['method'] ?? 'GET') . ' ' . ($endpoint['path'] ?? '')] = true;
        }

        $endpoints_create = [];
        $endpoints_update = [];
        foreach ($mapped['schema']['endpoints'] as $endpoint) {
            $key = strtoupper($endpoint['method'] ?? 'GET') . ' ' . ($endpoint['path'] ?? '');
            if (isset($current_endpoints[$key])) {
                $endpoints_update[] = $key;
            } else {
                $endpoints_create[] = $key;
            }
        }

        $current_models = [];
        foreach (APIPlatform_Endpoint_Schema_Service::models($api_id, $version) as $model) {
            $current_models[$model['name'] ?? ''] = true;
        }
        $models_create = [];
        $models_update = [];
        foreach ($mapped['models'] as $model) {
            $name = $model['name'] ?? '';
            if (isset($current_models[$name])) {
                $models_update[] = $name;
            } else {
                $models_create[] = $name;
            }
        }

        return array_merge($mapped, [
            'preview' => [
                'endpoints_create' => $endpoints_create,
                'endpoints_update' => $endpoints_update,
                'models_create' => $models_create,
                'models_update' => $models_update,
                'warnings' => $mapped['warnings'],
                'unsupported' => $mapped['unsupported'],
            ],
        ]);
    }

    public static function import($api_id, $user_id, array $mapped, array $options){
        $version = sanitize_text_field($options['version'] ?? ($mapped['schema']['version'] ?? APIPlatform_Endpoint_Schema_Service::version_label($api_id)));
        $mode = sanitize_key($options['mode'] ?? 'replace');
        $schema = $mapped['schema'];
        $schema['version'] = $version;

        if ($mode === 'merge-missing') {
            $current = APIPlatform_Endpoint_Schema_Service::schema_for_version($api_id, $version);
            if (!$current) {
                $current = APIPlatform_Endpoint_Schema_Service::current_schema($api_id);
            }
            $existing = [];
            foreach (($current['endpoints'] ?? []) as $endpoint) {
                $existing[strtoupper($endpoint['method'] ?? 'GET') . ' ' . ($endpoint['path'] ?? '')] = $endpoint;
            }
            foreach ($schema['endpoints'] as $endpoint) {
                $key = strtoupper($endpoint['method'] ?? 'GET') . ' ' . ($endpoint['path'] ?? '');
                if (!isset($existing[$key])) {
                    $existing[$key] = $endpoint;
                }
            }
            $schema['endpoints'] = array_values($existing);
        } elseif ($mode === 'skip-existing') {
            $current = APIPlatform_Endpoint_Schema_Service::schema_for_version($api_id, $version);
            if ($current) {
                return ['ok' => false, 'message' => 'Import skipped because this version already has a schema.'];
            }
        }

        $models = $mapped['models'] ?? [];
        if ($mode === 'merge-missing') {
            $existing_models = [];
            foreach (APIPlatform_Endpoint_Schema_Service::models($api_id, $version) as $model) {
                $name = $model['name'] ?? '';
                if ($name !== '') {
                    $existing_models[$name] = $model;
                }
            }
            foreach ($models as $model) {
                $name = $model['name'] ?? '';
                if ($name !== '' && !isset($existing_models[$name])) {
                    $existing_models[$name] = $model;
                }
            }
            $models = array_values($existing_models);
        }

        $result = APIPlatform_Endpoint_Schema_Service::save_schema($api_id, $user_id, $schema);
        if (empty($result['ok'])) {
            APIPlatform_Endpoint_Schema_Service::record_event($api_id, $user_id, 'import_failed', 'OpenAPI import failed.');
            return ['ok' => false, 'message' => $result['message'] ?? 'OpenAPI import failed.'];
        }

        APIPlatform_Endpoint_Schema_Service::save_models($api_id, $user_id, $version, $models);
        APIPlatform_Endpoint_Schema_Service::record_event($api_id, $user_id, 'openapi_imported', 'OpenAPI imported into schema version ' . $version . '.');

        return [
            'ok' => true,
            'message' => 'OpenAPI imported: ' . count($schema['endpoints']) . ' endpoints and ' . count($models) . ' models saved for ' . $version . '.',
        ];
    }

    public static function export($api_id, $format = 'json'){
        $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api_id);
        $version = $schema['version'] ?? APIPlatform_Endpoint_Schema_Service::version_label($api_id);
        $api = get_post($api_id);
        $models = APIPlatform_Endpoint_Schema_Service::models($api_id, $version);
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => get_the_title($api),
                'version' => $version,
                'description' => $api ? wp_strip_all_tags((string) $api->post_content) : '',
            ],
            'servers' => [
                [
                    'url' => function_exists('apiplatform_public_gateway_url')
                        ? apiplatform_public_gateway_url($api_id)
                        : (
                            class_exists('APIPlatform_Routes') && $api
                                ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                                : rest_url('platform/v1/api')
                        ),
                ],
            ],
            'paths' => self::export_paths($schema, $api),
            'components' => [
                'securitySchemes' => [
                        'BearerAuth' => [
                            'type' => 'http',
                            'scheme' => 'bearer',
                            'bearerFormat' => 'API key',
                        ],
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                ],
                'schemas' => self::export_models($models),
            ],
            'security' => [['BearerAuth' => []]],
        ];

        $validation = self::validate_export($spec);
        if (!$validation['ok']) {
            return $validation;
        }

        $format = sanitize_key($format);
        return [
            'ok' => true,
            'format' => $format === 'yaml' ? 'yaml' : 'json',
            'spec' => $spec,
            'content' => $format === 'yaml'
                ? self::to_yaml($spec)
                : wp_json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function map_to_freedom_schema($api_id, array $spec, $version){
        $warnings = [];
        $unsupported = [];
        $endpoints = [];
        $security = self::security_label($spec, $unsupported);

        foreach (($spec['paths'] ?? []) as $path => $path_item) {
            if (!is_array($path_item)) {
                continue;
            }
            $path_parameters = self::map_parameters($path_item['parameters'] ?? []);
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (empty($path_item[$method]) || !is_array($path_item[$method])) {
                    continue;
                }
                $operation = $path_item[$method];
                $parameters = array_merge($path_parameters, self::map_parameters($operation['parameters'] ?? []));
                $request_body = self::map_request_body($operation['requestBody'] ?? []);
                $responses = self::map_responses($operation['responses'] ?? [], $warnings, strtoupper($method) . ' ' . $path);
                if (!$responses) {
                    $warnings[] = strtoupper($method) . ' ' . $path . ' has no documented responses.';
                }
                $endpoints[] = [
                    'method' => strtoupper($method),
                    'path' => sanitize_text_field($path),
                    'summary' => sanitize_text_field($operation['summary'] ?? ''),
                    'description' => sanitize_textarea_field($operation['description'] ?? ''),
                    'auth_required' => !empty($operation['security'] ?? $spec['security'] ?? []),
                    'authentication' => $security,
                    'tags' => self::clean_list($operation['tags'] ?? []),
                    'visibility' => 'public',
                    'status' => 'active',
                    'deprecated' => !empty($operation['deprecated']),
                    'version' => sanitize_text_field($version),
                    'parameters' => $parameters,
                    'request_body' => $request_body,
                    'responses' => $responses,
                    'models' => self::models_from_operation($operation),
                ];
            }
        }

        if (!$endpoints) {
            return ['ok' => false, 'message' => 'OpenAPI spec does not contain supported path operations.'];
        }

        return [
            'ok' => true,
            'schema' => [
                'version' => sanitize_text_field($version),
                'endpoints' => $endpoints,
            ],
            'models' => self::map_models($spec['components']['schemas'] ?? [], $version),
            'warnings' => $warnings,
            'unsupported' => $unsupported,
        ];
    }

    private static function validate_spec($decoded){
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'OpenAPI source must decode to an object.'];
        }
        $version = (string) ($decoded['openapi'] ?? '');
        if (!preg_match('/^3\.(0|1)\.\d+$/', $version)) {
            return ['ok' => false, 'message' => 'Unsupported OpenAPI version. Use OpenAPI 3.0.x or 3.1.x.'];
        }
        if (empty($decoded['paths']) || !is_array($decoded['paths'])) {
            return ['ok' => false, 'message' => 'OpenAPI spec must include a paths object.'];
        }
        $operation_ids = [];
        foreach ($decoded['paths'] as $path_item) {
            if (!is_array($path_item)) {
                continue;
            }
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (empty($path_item[$method]['operationId'])) {
                    continue;
                }
                $id = (string) $path_item[$method]['operationId'];
                if (isset($operation_ids[$id])) {
                    return ['ok' => false, 'message' => 'Duplicate operationId found: ' . sanitize_text_field($id)];
                }
                $operation_ids[$id] = true;
            }
        }
        return ['ok' => true, 'spec' => $decoded, 'message' => 'OpenAPI parsed.'];
    }

    private static function map_parameters(array $parameters){
        $mapped = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            if (!empty($parameter['$ref'])) {
                $mapped[] = [
                    'name' => basename(str_replace('\\', '/', $parameter['$ref'])),
                    'location' => 'query',
                    'description' => 'Referenced parameter: ' . sanitize_text_field($parameter['$ref']),
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'example' => '',
                    'rules' => '',
                    'allowed_values' => [],
                    'nullable' => false,
                ];
                continue;
            }
            $schema = $parameter['schema'] ?? [];
            $mapped[] = [
                'name' => sanitize_text_field($parameter['name'] ?? ''),
                'location' => sanitize_key($parameter['in'] ?? 'query'),
                'description' => sanitize_textarea_field($parameter['description'] ?? ''),
                'required' => !empty($parameter['required']),
                'type' => self::map_type($schema),
                'default' => sanitize_text_field($schema['default'] ?? ''),
                'example' => sanitize_text_field($parameter['example'] ?? $schema['example'] ?? ''),
                'rules' => '',
                'allowed_values' => self::clean_list($schema['enum'] ?? []),
                'nullable' => !empty($schema['nullable']),
            ];
        }
        return array_values(array_filter($mapped, function($item){ return $item['name'] !== ''; }));
    }

    private static function map_request_body(array $request_body){
        if (!$request_body) {
            return ['enabled' => false, 'format' => 'json', 'content_type' => 'application/json', 'description' => '', 'fields' => [], 'example' => []];
        }
        $content = $request_body['content'] ?? [];
        $type = isset($content['application/json']) ? 'application/json' : array_key_first($content);
        $media = is_string($type) && isset($content[$type]) ? $content[$type] : [];
        $schema = $media['schema'] ?? [];
        return [
            'enabled' => true,
            'format' => $type === 'application/json' ? 'json' : ($type === 'multipart/form-data' ? 'multipart' : 'form'),
            'content_type' => sanitize_text_field($type ?: 'application/json'),
            'description' => sanitize_textarea_field($request_body['description'] ?? ''),
            'fields' => self::fields_from_schema($schema),
            'example' => self::example_from_media($media, $schema),
        ];
    }

    private static function map_responses(array $responses, array &$warnings, $operation_label){
        $mapped = [];
        foreach ($responses as $status => $response) {
            if (!is_array($response)) {
                continue;
            }
            $status_code = absint($status);
            if ((string) $status === 'default') {
                $status_code = 200;
                $warnings[] = $operation_label . ' default response imported as HTTP 200 fallback.';
            } elseif ($status_code < 100 || $status_code > 599) {
                $warnings[] = $operation_label . ' response ' . sanitize_text_field((string) $status) . ' imported as HTTP 200 fallback.';
                $status_code = 200;
            }
            $content = $response['content'] ?? [];
            $media = $content['application/json'] ?? (is_array($content) && $content ? reset($content) : []);
            $schema = is_array($media) ? ($media['schema'] ?? []) : [];
            $mapped[] = [
                'status' => $status_code,
                'description' => sanitize_textarea_field($response['description'] ?? ''),
                'headers' => self::map_parameters(array_map(function($header, $name){
                    $header = is_array($header) ? $header : [];
                    $header['name'] = $name;
                    $header['in'] = 'header';
                    return $header;
                }, $response['headers'] ?? [], array_keys($response['headers'] ?? []))),
                'body_schema' => self::fields_from_schema($schema),
                'example' => self::example_from_media($media, $schema),
                'model' => self::schema_ref_name($schema),
            ];
        }
        return $mapped;
    }

    private static function map_models(array $schemas, $version){
        $models = [];
        foreach ($schemas as $name => $schema) {
            if (!is_array($schema)) {
                continue;
            }
            $models[] = [
                'name' => sanitize_text_field($name),
                'version' => sanitize_text_field($version),
                'description' => sanitize_textarea_field($schema['description'] ?? ''),
                'fields' => self::fields_from_schema($schema),
            ];
        }
        return $models;
    }

    private static function fields_from_schema(array $schema){
        if (!empty($schema['$ref'])) {
            return [['name' => self::schema_ref_name($schema), 'type' => 'object', 'description' => 'Referenced model', 'required' => true, 'nullable' => false, 'example' => '', 'item_type' => '', 'fields' => []]];
        }
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $fields = [];
        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }
            $type = self::map_type($property);
            $fields[] = [
                'name' => sanitize_text_field($name),
                'type' => $type,
                'description' => sanitize_textarea_field($property['description'] ?? ''),
                'required' => in_array($name, $required, true),
                'nullable' => !empty($property['nullable']),
                'example' => sanitize_text_field($property['example'] ?? ''),
                'item_type' => isset($property['items']) && is_array($property['items']) ? self::map_type($property['items']) : '',
                'fields' => $type === 'object' ? self::fields_from_schema($property) : [],
            ];
        }
        return $fields;
    }

    private static function export_paths(array $schema, $api = null){
        $paths = [];
        foreach (($schema['endpoints'] ?? []) as $endpoint) {
            $path = self::export_gateway_path($endpoint['path'] ?? '/', $api);
            $method = strtolower($endpoint['method'] ?? 'get');
            $paths[$path][$method] = [
                'summary' => $endpoint['summary'] ?? '',
                'description' => $endpoint['description'] ?? '',
                'deprecated' => !empty($endpoint['deprecated']),
                'tags' => $endpoint['tags'] ?? [],
                'parameters' => self::export_parameters($endpoint['parameters'] ?? []),
                'responses' => self::export_responses($endpoint['responses'] ?? []),
                'security' => !empty($endpoint['auth_required']) ? [['ApiKeyAuth' => []]] : [],
            ];
            $body = $endpoint['request_body'] ?? [];
            if (!empty($body['enabled'])) {
                $paths[$path][$method]['requestBody'] = [
                    'description' => $body['description'] ?? '',
                    'required' => true,
                    'content' => [
                        ($body['content_type'] ?? 'application/json') => [
                            'schema' => self::schema_from_fields($body['fields'] ?? []),
                            'example' => $body['example'] ?? new stdClass(),
                        ],
                    ],
                ];
            }
        }
        return $paths;
    }

    private static function export_gateway_path($path, $api = null){
        $path = '/' . ltrim((string) $path, '/');
        $api_slug = $api ? sanitize_title($api->post_name) : 'api';
        $legacy_path = '/platform/v1/api/' . $api_slug;

        if ($path === $legacy_path || $path === '/' . $api_slug) {
            return '/';
        }

        if (strpos($path, '/platform/v1/api/') === 0) {
            $path = substr($path, strlen('/platform/v1/api'));
        }

        return '/' . ltrim($path, '/');
    }

    private static function export_parameters(array $parameters){
        $out = [];
        foreach ($parameters as $parameter) {
            $out[] = [
                'name' => $parameter['name'] ?? '',
                'in' => $parameter['location'] ?? 'query',
                'description' => $parameter['description'] ?? '',
                'required' => !empty($parameter['required']),
                'schema' => self::openapi_schema_for_type($parameter['type'] ?? 'string', $parameter),
                'example' => $parameter['example'] ?? '',
            ];
        }
        return $out;
    }

    private static function export_responses(array $responses){
        $out = [];
        foreach ($responses as $response) {
            $status = (string) absint($response['status'] ?? 200);
            $out[$status] = [
                'description' => $response['description'] ?? '',
                'content' => [
                    'application/json' => [
                        'schema' => !empty($response['freedomapi_output_schema'])
                            ? $response['freedomapi_output_schema']
                            : (($response['model'] ?? '') !== ''
                            ? ['$ref' => '#/components/schemas/' . $response['model']]
                            : self::schema_from_fields($response['body_schema'] ?? [])),
                        'example' => $response['example'] ?? new stdClass(),
                    ],
                ],
            ];
        }
        return $out ?: ['200' => ['description' => 'Success']];
    }

    private static function export_models(array $models){
        $out = [];
        foreach ($models as $model) {
            $out[$model['name']] = array_merge(self::schema_from_fields($model['fields'] ?? []), [
                'description' => $model['description'] ?? '',
            ]);
        }
        return $out;
    }

    private static function schema_from_fields(array $fields){
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $properties[$name] = self::openapi_schema_for_type($field['type'] ?? 'string', $field);
            $properties[$name]['description'] = $field['description'] ?? '';
            if (($field['example'] ?? '') !== '') {
                $properties[$name]['example'] = $field['example'];
            }
            if (!empty($field['required'])) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    private static function openapi_schema_for_type($type, array $definition){
        $type = sanitize_key($type ?: 'string');
        if ($type === 'integer') return ['type' => 'integer'];
        if ($type === 'number') return ['type' => 'number'];
        if ($type === 'boolean') return ['type' => 'boolean'];
        if ($type === 'array') return ['type' => 'array', 'items' => ['type' => sanitize_key($definition['item_type'] ?? 'string') ?: 'string']];
        if ($type === 'object') return ['type' => 'object'];
        if ($type === 'date') return ['type' => 'string', 'format' => 'date'];
        if ($type === 'datetime') return ['type' => 'string', 'format' => 'date-time'];
        if ($type === 'uuid') return ['type' => 'string', 'format' => 'uuid'];
        if ($type === 'email') return ['type' => 'string', 'format' => 'email'];
        if ($type === 'url') return ['type' => 'string', 'format' => 'uri'];
        if ($type === 'binary') return ['type' => 'string', 'format' => 'binary'];
        if ($type === 'enum') return ['type' => 'string', 'enum' => $definition['allowed_values'] ?? []];
        return ['type' => 'string'];
    }

    private static function validate_export(array $spec){
        if (empty($spec['paths'])) {
            return ['ok' => false, 'message' => 'Cannot export OpenAPI without paths.'];
        }
        return ['ok' => true];
    }

    private static function map_type(array $schema){
        if (!empty($schema['enum'])) return 'enum';
        $format = sanitize_key($schema['format'] ?? '');
        if ($format === 'date') return 'date';
        if ($format === 'date-time') return 'datetime';
        if ($format === 'uuid') return 'uuid';
        if ($format === 'email') return 'email';
        if ($format === 'uri' || $format === 'url') return 'url';
        if ($format === 'binary') return 'binary';
        $type = sanitize_key($schema['type'] ?? 'string');
        return in_array($type, APIPlatform_Endpoint_Schema_Service::data_types(), true) ? $type : 'string';
    }

    private static function security_label(array $spec, array &$unsupported){
        $schemes = $spec['components']['securitySchemes'] ?? [];
        foreach ($schemes as $scheme) {
            if (($scheme['type'] ?? '') === 'apiKey') return 'API Key';
            if (($scheme['type'] ?? '') === 'http' && ($scheme['scheme'] ?? '') === 'bearer') return 'Bearer';
            if (($scheme['type'] ?? '') === 'http' && ($scheme['scheme'] ?? '') === 'basic') return 'Basic';
            if (($scheme['type'] ?? '') === 'oauth2') $unsupported[] = 'OAuth security imported as informational metadata only.';
            if (($scheme['type'] ?? '') === 'openIdConnect') $unsupported[] = 'OpenID Connect imported as informational metadata only.';
        }
        return 'Application Key';
    }

    private static function models_from_operation(array $operation){
        $models = [];
        foreach (($operation['responses'] ?? []) as $response) {
            $content = $response['content']['application/json']['schema'] ?? [];
            $name = self::schema_ref_name(is_array($content) ? $content : []);
            if ($name !== '') {
                $models[] = $name;
            }
        }
        return array_values(array_unique($models));
    }

    private static function schema_ref_name(array $schema){
        if (empty($schema['$ref'])) {
            return '';
        }
        return sanitize_text_field(basename(str_replace('\\', '/', $schema['$ref'])));
    }

    private static function example_from_media($media, array $schema){
        if (is_array($media) && isset($media['example'])) {
            return $media['example'];
        }
        if (is_array($media) && !empty($media['examples']) && is_array($media['examples'])) {
            $first = reset($media['examples']);
            if (is_array($first) && isset($first['value'])) {
                return $first['value'];
            }
        }
        return self::example_from_schema($schema);
    }

    private static function example_from_schema(array $schema){
        if (isset($schema['example'])) {
            return $schema['example'];
        }
        $example = [];
        foreach (($schema['properties'] ?? []) as $name => $property) {
            $example[$name] = $property['example'] ?? self::example_for_type(self::map_type(is_array($property) ? $property : []));
        }
        return $example ?: new stdClass();
    }

    private static function example_for_type($type){
        if ($type === 'integer') return 123;
        if ($type === 'number') return 12.3;
        if ($type === 'boolean') return true;
        if ($type === 'array') return [];
        if ($type === 'object') return new stdClass();
        if ($type === 'email') return 'user@example.com';
        if ($type === 'url') return 'https://example.com';
        return 'string';
    }

    private static function clean_list($value){
        return array_values(array_filter(array_map('sanitize_text_field', (array) $value)));
    }

    private static function parse_yaml($raw){
        if (function_exists('yaml_parse')) {
            $parsed = @yaml_parse($raw);
            return is_array($parsed) ? $parsed : null;
        }
        $jsonish = self::yaml_to_jsonish($raw);
        return is_array($jsonish) ? $jsonish : null;
    }

    private static function yaml_to_jsonish($raw){
        $lines = preg_split('/\r?\n/', $raw);
        $root = [];
        $stack = [['indent' => -1, 'value' => &$root]];
        foreach ($lines as $line) {
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $trim = trim($line);
            while (count($stack) > 1 && $indent <= $stack[count($stack) - 1]['indent']) {
                array_pop($stack);
            }
            $parent =& $stack[count($stack) - 1]['value'];
            if (strpos($trim, '- ') === 0) {
                if (!is_array($parent)) {
                    $parent = [];
                }
                $item = substr($trim, 2);
                $parent[] = self::yaml_scalar_or_pair($item);
                if (is_array($parent[count($parent) - 1])) {
                    $stack[] = ['indent' => $indent, 'value' => &$parent[count($parent) - 1]];
                }
                continue;
            }
            if (strpos($trim, ':') !== false) {
                [$key, $value] = array_map('trim', explode(':', $trim, 2));
                $key = trim($key, '"\'');
                if ($value === '') {
                    $parent[$key] = [];
                    $stack[] = ['indent' => $indent, 'value' => &$parent[$key]];
                } else {
                    $parent[$key] = self::yaml_scalar($value);
                }
            }
        }
        return $root;
    }

    private static function yaml_scalar_or_pair($value){
        if (strpos($value, ':') !== false) {
            [$key, $scalar] = array_map('trim', explode(':', $value, 2));
            return [trim($key, '"\'') => self::yaml_scalar($scalar)];
        }
        return self::yaml_scalar($value);
    }

    private static function yaml_scalar($value){
        $value = trim($value, " \t\"'");
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        if (is_numeric($value)) return strpos($value, '.') !== false ? (float) $value : (int) $value;
        if (preg_match('/^\[(.*)\]$/', $value, $m)) {
            return array_map('trim', explode(',', $m[1]));
        }
        return $value;
    }

    public static function to_yaml($value, $indent = 0){
        $space = str_repeat('  ', $indent);
        if (!is_array($value)) {
            return self::yaml_scalar_out($value);
        }
        $lines = [];
        $is_list = array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $key => $item) {
            if ($is_list) {
                $lines[] = $space . '- ' . (is_array($item) ? "\n" . self::to_yaml($item, $indent + 1) : self::yaml_scalar_out($item));
            } else {
                $lines[] = $space . $key . ': ' . (is_array($item) ? "\n" . self::to_yaml($item, $indent + 1) : self::yaml_scalar_out($item));
            }
        }
        return implode("\n", $lines);
    }

    private static function yaml_scalar_out($value){
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return 'null';
        if (is_numeric($value)) return (string) $value;
        return '"' . str_replace('"', '\"', (string) $value) . '"';
    }
}
