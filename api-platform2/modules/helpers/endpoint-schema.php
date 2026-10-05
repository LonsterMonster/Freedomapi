<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Endpoint_Schema_Service {

    const META_SCHEMAS = 'apiplatform_endpoint_schema_versions';
    const META_MODELS = 'apiplatform_data_model_versions';
    const META_VALIDATION_MODE = 'apiplatform_schema_validation_mode';
    const META_REJECT_UNKNOWN = 'apiplatform_schema_reject_unknown';
    const META_EVENTS = 'apiplatform_schema_events';

    public static function data_types(){
        return ['string', 'integer', 'number', 'boolean', 'array', 'object', 'date', 'datetime', 'uuid', 'email', 'url', 'enum', 'binary'];
    }

    public static function validation_modes(){
        return ['off' => 'Off', 'log' => 'Observe', 'enforce' => 'Enforce'];
    }

    public static function validation_mode_descriptions(){
        return [
            'off' => 'Response contract is not checked at runtime.',
            'log' => 'Response is checked and mismatches are recorded, but the original response is still returned.',
            'enforce' => 'Response is checked and mismatches are blocked with a safe FreedomAPI error.',
        ];
    }

    public static function validate_response($api_id, array $endpoint, $status, $content_type, $body){
        $mode = sanitize_key(get_post_meta(absint($api_id), self::META_VALIDATION_MODE, true) ?: 'off');
        if (!isset(self::validation_modes()[$mode])) {
            $mode = 'off';
        }
        $mode_label = $mode === 'log' ? 'observe' : $mode;
        $responses = is_array($endpoint['responses'] ?? null) ? $endpoint['responses'] : [];
        $declared = null;
        foreach ($responses as $candidate) {
            if (absint($candidate['status'] ?? 0) === absint($status)) {
                $declared = $candidate;
                break;
            }
        }
        if (!$declared) {
            foreach ($responses as $candidate) {
                if (sanitize_key($candidate['status'] ?? '') === 'default' || absint($candidate['status'] ?? -1) === 0) {
                    $declared = $candidate;
                    break;
                }
            }
        }
        if (!empty($declared['freedomapi_output_schema']) && function_exists('freedomapi_validate_response_schema')) {
            $value = json_decode(wp_json_encode($body));
            $valid = freedomapi_validate_response_schema($value, $declared['freedomapi_output_schema']);
            return ['ok' => $valid, 'valid' => $valid, 'validation_performed' => true, 'status' => $valid ? 'valid' : 'mismatch', 'mode' => 'enforce', 'errors' => $valid ? [] : [['reason' => 'approved_output_contract_mismatch']], 'schema_status' => 'declared'];
        }
        $schema_fields = is_array($declared['body_schema'] ?? null) ? $declared['body_schema'] : [];
        if (is_array($declared) && empty($schema_fields) && !empty($declared['model'])) {
            foreach (self::models($api_id, self::version_label($api_id)) as $model) {
                if (($model['name'] ?? '') === $declared['model']) { $schema_fields = is_array($model['fields'] ?? null) ? $model['fields'] : []; break; }
            }
        }
        if (!$declared || (!$schema_fields && empty($declared['model']))) {
            return ['ok' => true, 'valid' => true, 'validation_performed' => false, 'status' => 'no_schema', 'mode' => 'off', 'errors' => [], 'schema_status' => 'not_declared'];
        }
        if ($mode === 'off') {
            return ['ok' => true, 'valid' => true, 'validation_performed' => false, 'status' => 'not_checked', 'mode' => 'off', 'errors' => [], 'schema_status' => 'declared'];
        }
        $content_type = strtolower((string) $content_type);
        if ($content_type !== '' && strpos($content_type, 'json') === false) {
            return ['ok' => true, 'valid' => true, 'validation_performed' => false, 'status' => 'not_checked', 'mode' => $mode_label, 'errors' => [], 'schema_status' => 'unsupported_content_type'];
        }
        $errors = [];
        self::validate_response_fields($body, $schema_fields, '$', $errors, 0);
        $valid = !$errors;
        return ['ok' => $valid || $mode !== 'enforce', 'valid' => $valid, 'validation_performed' => true, 'status' => $valid ? 'valid' : 'mismatch', 'mode' => $mode_label, 'errors' => array_slice($errors, 0, 10), 'schema_status' => 'declared', 'schema_status_code' => absint($declared['status'] ?? $status)];
    }

    private static function validate_response_fields($value, array $fields, $path, array &$errors, $depth){
        if ($depth > 12 || count($errors) >= 10) return;
        if (!is_array($value)) {
            $errors[] = ['path' => $path, 'reason' => 'expected_object', 'expected' => 'object', 'received' => gettype($value)];
            return;
        }
        foreach ($fields as $field) {
            if (!is_array($field) || count($errors) >= 10) continue;
            $name = sanitize_text_field($field['name'] ?? '');
            if ($name === '') continue;
            $field_path = $path . '.' . $name;
            if (!array_key_exists($name, $value)) {
                if (!empty($field['required'])) $errors[] = ['path' => $field_path, 'reason' => 'required_property_missing', 'expected' => sanitize_key($field['type'] ?? 'field')];
                continue;
            }
            $type = sanitize_key($field['type'] ?? 'string');
            $actual = $value[$name];
            $valid = ($type === 'string' && is_string($actual)) || ($type === 'integer' && is_int($actual)) || ($type === 'number' && is_numeric($actual) && !is_bool($actual)) || ($type === 'boolean' && is_bool($actual)) || ($type === 'null' && $actual === null) || ($type === 'object' && is_array($actual)) || ($type === 'array' && is_array($actual)) || in_array($type, ['date','datetime','uuid','email','url','enum'], true) && is_string($actual);
            if (!$valid) { $errors[] = ['path' => $field_path, 'reason' => 'type_mismatch', 'expected' => $type, 'received' => gettype($actual)]; continue; }
            if ($type === 'object' && !empty($field['fields'])) self::validate_response_fields($actual, $field['fields'], $field_path, $errors, $depth + 1);
            if ($type === 'array' && !empty($field['fields'])) foreach ($actual as $index => $item) self::validate_response_fields($item, $field['fields'], $field_path . '[' . absint($index) . ']', $errors, $depth + 1);
        }
    }

    public static function version_label($api_id){
        return get_post_meta($api_id, 'apiplatform_portal_version_label', true) ?: 'v1';
    }

    public static function current_schema($api_id, $include_extensions = true){
        $version = self::version_label($api_id);
        $schemas = get_post_meta($api_id, self::META_SCHEMAS, true);
        $schemas = is_array($schemas) ? $schemas : [];

        if (!empty($schemas[$version]) && is_array($schemas[$version])) {
            $schema = self::normalize_schema($api_id, $schemas[$version], $version);
            return $include_extensions && function_exists('freedomapi_extension_schema') ? freedomapi_extension_schema($api_id, $schema) : $schema;
        }

        $schema = self::legacy_schema($api_id, $version);
        return $include_extensions && function_exists('freedomapi_extension_schema') ? freedomapi_extension_schema($api_id, $schema) : $schema;
    }

    public static function schema_for_version($api_id, $version){
        $version = sanitize_text_field($version);
        $schemas = get_post_meta($api_id, self::META_SCHEMAS, true);
        $schemas = is_array($schemas) ? $schemas : [];

        if (!empty($schemas[$version]) && is_array($schemas[$version])) {
            $schema = self::normalize_schema($api_id, $schemas[$version], $version);
            return function_exists('freedomapi_extension_schema') ? freedomapi_extension_schema($api_id, $schema) : $schema;
        }

        return null;
    }

    public static function save_schema($api_id, $user_id, array $schema){
        $api_id = absint($api_id);
        if (!self::can_manage($api_id, $user_id)) {
            return ['ok' => false, 'code' => 'api_permission_denied', 'message' => 'You cannot edit the schema for that API.'];
        }
        $version = sanitize_text_field($schema['version'] ?? self::version_label($api_id));
        $schemas = get_post_meta($api_id, self::META_SCHEMAS, true);
        $schemas = is_array($schemas) ? $schemas : [];
        $normalized = self::normalize_schema($api_id, $schema, $version);
        $previous = $schemas[$version] ?? null;

        $schemas[$version] = $normalized;
        update_post_meta($api_id, self::META_SCHEMAS, $schemas);
        update_post_meta($api_id, 'apiplatform_docs_methods', self::schema_methods($normalized));
        update_post_meta($api_id, 'api_parameters', self::legacy_parameters($normalized));
        update_post_meta($api_id, 'api_request_body_example', self::request_body_example($normalized));
        update_post_meta($api_id, 'apiplatform_docs_success_response', self::success_response_example($normalized));
        update_post_meta($api_id, 'apiplatform_docs_errors', self::error_responses($normalized));
        self::record_event($api_id, $user_id, $previous ? 'endpoint_edited' : 'endpoint_created', 'Endpoint schema saved for ' . $version . '.');

        return ['ok' => true, 'message' => 'Endpoint schema saved for ' . $version . '.', 'schema' => $normalized];
    }

    public static function save_models($api_id, $user_id, $version, array $models){
        $api_id = absint($api_id);
        if (!self::can_manage($api_id, $user_id)) {
            return 'Error: You cannot edit data models for that API.';
        }
        $version = sanitize_text_field($version ?: self::version_label($api_id));
        $all = get_post_meta($api_id, self::META_MODELS, true);
        $all = is_array($all) ? $all : [];
        $clean = [];

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = sanitize_text_field($model['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $clean[] = [
                'name' => $name,
                'version' => sanitize_text_field($model['version'] ?? $version),
                'description' => sanitize_textarea_field($model['description'] ?? ''),
                'fields' => self::normalize_fields($model['fields'] ?? []),
            ];
        }

        $all[$version] = [
            'models' => $clean,
            'updated_at' => current_time('mysql'),
            'updated_by_user_id' => absint($user_id),
        ];
        update_post_meta($api_id, self::META_MODELS, $all);
        self::record_event($api_id, $user_id, 'model_updated', 'Data models saved for ' . $version . '.');
        return 'Data models saved for ' . $version . '.';
    }

    public static function models($api_id, $version = ''){
        $version = $version !== '' ? sanitize_text_field($version) : self::version_label($api_id);
        $models = get_post_meta($api_id, self::META_MODELS, true);
        $models = is_array($models) ? $models : [];
        $version_models = $models[$version] ?? [];

        if (isset($version_models['models']) && is_array($version_models['models'])) {
            return $version_models['models'];
        }

        return is_array($version_models) ? $version_models : [];
    }

    public static function save_validation_settings($api_id, $user_id, $mode, $reject_unknown){
        $api_id = absint($api_id);
        if (!self::can_manage($api_id, $user_id)) {
            return 'Error: You cannot change validation settings for that API.';
        }
        $mode = sanitize_key($mode);
        if (!isset(self::validation_modes()[$mode])) {
            $mode = 'off';
        }
        update_post_meta($api_id, self::META_VALIDATION_MODE, $mode);
        update_post_meta($api_id, self::META_REJECT_UNKNOWN, $reject_unknown ? 1 : 0);
        self::record_event($api_id, $user_id, 'validation_enabled', 'Validation mode set to ' . $mode . '.');
        return 'Validation mode saved.';
    }

    public static function validation_summary($api_id){
        $schema = self::current_schema($api_id);
        $warnings = [];
        $errors = [];

        foreach ($schema['endpoints'] as $endpoint) {
            if (trim((string) ($endpoint['summary'] ?? '')) === '') {
                $warnings[] = 'Endpoint ' . $endpoint['method'] . ' ' . $endpoint['path'] . ' is missing a summary.';
            }
            if (empty($endpoint['responses'])) {
                $errors[] = 'Endpoint ' . $endpoint['method'] . ' ' . $endpoint['path'] . ' has no responses.';
            }
            foreach ($endpoint['parameters'] as $parameter) {
                if (!empty($parameter['required']) && trim((string) ($parameter['example'] ?? '')) === '') {
                    $warnings[] = 'Required parameter ' . $parameter['name'] . ' is missing an example.';
                }
            }
        }

        $total = 6;
        $score = max(0, 100 - (count($errors) * 20) - (count($warnings) * 8));

        return [
            'score' => min(100, $score),
            'mode' => get_post_meta($api_id, self::META_VALIDATION_MODE, true) ?: 'off',
            'reject_unknown' => (bool) get_post_meta($api_id, self::META_REJECT_UNKNOWN, true),
            'warnings' => $warnings,
            'errors' => $errors,
            'ready' => count($errors) === 0 && $score >= 70,
            'total_checks' => $total,
        ];
    }

    public static function compare_with_previous($api_id){
        $current_version = self::version_label($api_id);
        $schemas = get_post_meta($api_id, self::META_SCHEMAS, true);
        $schemas = is_array($schemas) ? $schemas : [];
        $versions = array_keys($schemas);
        $previous_version = '';

        foreach (array_reverse($versions) as $version) {
            if ($version !== $current_version) {
                $previous_version = $version;
                break;
            }
        }

        if ($previous_version === '') {
            return ['previous_version' => '', 'changes' => [], 'breaking' => []];
        }

        $current = self::current_schema($api_id);
        $previous = self::normalize_schema($api_id, $schemas[$previous_version], $previous_version);
        $current_map = self::endpoint_map($current);
        $previous_map = self::endpoint_map($previous);
        $changes = [];
        $breaking = [];

        foreach ($current_map as $key => $endpoint) {
            if (!isset($previous_map[$key])) {
                $changes[] = 'Added endpoint ' . $key;
                continue;
            }
            $current_params = self::parameter_map($endpoint);
            $previous_params = self::parameter_map($previous_map[$key]);
            foreach ($current_params as $param_key => $param) {
                if (!isset($previous_params[$param_key])) {
                    $changes[] = 'Added parameter ' . $param_key . ' on ' . $key;
                    continue;
                }
                if (($param['type'] ?? '') !== ($previous_params[$param_key]['type'] ?? '')) {
                    $message = 'Changed type for ' . $param_key . ' on ' . $key;
                    $changes[] = $message;
                    $breaking[] = $message;
                }
            }
            foreach ($previous_params as $param_key => $param) {
                if (!isset($current_params[$param_key])) {
                    $message = 'Removed parameter ' . $param_key . ' from ' . $key;
                    $changes[] = $message;
                    if (!empty($param['required'])) {
                        $breaking[] = $message;
                    }
                }
            }
            if (!empty($endpoint['deprecated']) && empty($previous_map[$key]['deprecated'])) {
                $changes[] = 'Deprecated endpoint ' . $key;
            }
        }

        foreach ($previous_map as $key => $endpoint) {
            if (!isset($current_map[$key])) {
                $changes[] = 'Removed endpoint ' . $key;
                $breaking[] = 'Removed endpoint ' . $key;
            }
        }

        return ['previous_version' => $previous_version, 'changes' => $changes, 'breaking' => $breaking];
    }

    public static function validate_request($api_id, $request, $version = ''){
        $mode = get_post_meta($api_id, self::META_VALIDATION_MODE, true) ?: 'off';
        if ($mode === 'off') {
            return ['ok' => true, 'mode' => 'off', 'errors' => [], 'warnings' => []];
        }

        $schema = $version !== '' ? (self::schema_for_version($api_id, $version) ?: self::current_schema($api_id)) : self::current_schema($api_id);
        $method = strtoupper($request->get_method());
        $endpoint = self::endpoint_for_method($schema, $method);
        $errors = [];
        $warnings = [];

        if ($endpoint && empty($endpoint['parameters']) && empty($endpoint['request_body']['enabled'])) {
            return ['ok' => true, 'mode' => $mode, 'errors' => [], 'warnings' => [], 'validation_performed' => false, 'scope' => 'request'];
        }

        if (!$endpoint) {
            $errors[] = ['field' => 'method', 'code' => 'unsupported_method', 'message' => 'HTTP method is not supported by this endpoint schema.'];
        } else {
            foreach ($endpoint['parameters'] as $parameter) {
                $location = sanitize_key($parameter['location'] ?? 'query');
                $name = (string) ($parameter['name'] ?? '');
                $value = self::request_value($request, $location, $name);

                if (!empty($parameter['required']) && ($value === null || $value === '')) {
                    $errors[] = ['field' => $name, 'code' => 'required', 'message' => 'Required ' . $location . ' parameter is missing.'];
                    continue;
                }

                if ($value !== null && $value !== '') {
                    $type_error = self::validate_type($value, $parameter);
                    if ($type_error) {
                        $errors[] = ['field' => $name, 'code' => 'invalid_type', 'message' => $type_error];
                    }
                }
            }

            $body_result = self::validate_body($request, $endpoint, !empty($schema['reject_unknown']));
            $errors = array_merge($errors, $body_result['errors']);
            $warnings = array_merge($warnings, $body_result['warnings']);
        }

        $result = ['ok' => !$errors, 'mode' => $mode, 'errors' => $errors, 'warnings' => $warnings];

        if ($mode === 'log' && $errors && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API SCHEMA VALIDATION] API ID ' . absint($api_id) . ' validation issues: ' . count($errors));
        }

        return $result;
    }

    public static function documentation_data($api_id, array $data){
        $schema = self::current_schema($api_id);
        $endpoint = $schema['endpoints'][0] ?? null;
        if (!$endpoint) {
            return $data;
        }

        $data['api']['methods'] = self::schema_methods($schema);
        $data['endpoint_description'] = $endpoint['description'] ?: ($endpoint['summary'] ?? '');
        $data['parameters'] = $endpoint['parameters'];
        $data['body_example'] = self::documentation_body($endpoint);
        $data['example_response'] = self::documentation_response($endpoint);
        $data['errors'] = self::documentation_errors($endpoint);
        $data['code_examples'] = APIPlatform_Endpoint_Example_Generator::examples($data['api']['endpoint_url'], $endpoint);
        $data['schema'] = $schema;
        $data['schema_generated'] = true;

        return $data;
    }

    public static function record_event($api_id, $user_id, $event_type, $message){
        $events = get_post_meta($api_id, self::META_EVENTS, true);
        $events = is_array($events) ? $events : [];
        array_unshift($events, [
            'actor_user_id' => absint($user_id),
            'event_type' => sanitize_key($event_type),
            'message' => sanitize_text_field($message),
            'created_at' => current_time('mysql'),
        ]);
        update_post_meta($api_id, self::META_EVENTS, array_slice($events, 0, 50));
    }

    public static function normalize_schema($api_id, array $schema, $version){
        $endpoints = [];
        foreach (($schema['endpoints'] ?? []) as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $method = strtoupper(sanitize_key($endpoint['method'] ?? 'GET'));
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $method = 'GET';
            }
            $path = sanitize_text_field($endpoint['path'] ?? ('/platform/v1/api/' . get_post_field('post_name', $api_id)));
            $parameters = self::normalize_parameters($endpoint['parameters'] ?? []);
            $request_body = self::normalize_request_body($endpoint['request_body'] ?? []);
            $responses = self::normalize_responses($endpoint['responses'] ?? []);
            $id = sanitize_title($method . '-' . trim($path, '/')) ?: 'default-endpoint';
            $endpoints[] = [
                'id' => $id,
                'method' => $method,
                'methods' => [$method],
                'path' => $path,
                'summary' => sanitize_text_field($endpoint['summary'] ?? ''),
                'description' => sanitize_textarea_field($endpoint['description'] ?? ''),
                'auth_required' => !empty($endpoint['auth_required']),
                'authentication' => sanitize_text_field($endpoint['authentication'] ?? 'Application Key'),
                'tags' => self::clean_list($endpoint['tags'] ?? []),
                'visibility' => sanitize_key($endpoint['visibility'] ?? 'public'),
                'status' => sanitize_key($endpoint['status'] ?? 'active'),
                'deprecated' => !empty($endpoint['deprecated']),
                'version' => sanitize_text_field($endpoint['version'] ?? $version),
                'parameters' => $parameters,
                'request_body' => $request_body,
                'responses' => $responses,
                'models' => self::clean_list($endpoint['models'] ?? []),
            ];
        }

        if (!$endpoints) {
            $endpoints = self::legacy_schema($api_id, $version)['endpoints'];
        }

        return [
            'version' => sanitize_text_field($version),
            'validation_mode' => get_post_meta($api_id, self::META_VALIDATION_MODE, true) ?: 'off',
            'reject_unknown' => (bool) get_post_meta($api_id, self::META_REJECT_UNKNOWN, true),
            'endpoints' => $endpoints,
            'models' => self::models($api_id, $version),
            'updated_at' => current_time('mysql'),
        ];
    }

    private static function legacy_schema($api_id, $version){
        $api = get_post($api_id);
        $methods = get_post_meta($api_id, 'apiplatform_docs_methods', true);
        $methods = self::sanitize_methods($methods ?: 'GET');
        $parameters = get_post_meta($api_id, 'api_parameters', true);
        $errors = get_post_meta($api_id, 'apiplatform_docs_errors', true);
        $body = get_post_meta($api_id, 'api_request_body_example', true);
        $response = get_post_meta($api_id, 'apiplatform_docs_success_response', true) ?: get_post_meta($api_id, 'api_json', true);
        $endpoint = $api && function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api)
            : (
                $api && class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                    : rest_url('platform/v1/api/' . ($api ? $api->post_name : 'api'))
            );
        $path = $api ? '/platform/v1/api/' . $api->post_name : '/platform/v1/api/api';

        return [
            'version' => $version,
            'validation_mode' => get_post_meta($api_id, self::META_VALIDATION_MODE, true) ?: 'off',
            'reject_unknown' => (bool) get_post_meta($api_id, self::META_REJECT_UNKNOWN, true),
            'endpoints' => [[
                'id' => 'default-endpoint',
                'method' => $methods[0] ?? 'GET',
                'methods' => $methods,
                'path' => $path,
                'url' => $endpoint,
                'summary' => get_post_meta($api_id, 'apiplatform_docs_endpoint_description', true),
                'description' => get_post_meta($api_id, 'apiplatform_docs_endpoint_description', true),
                'auth_required' => true,
                'authentication' => 'Application Key',
                'tags' => [],
                'visibility' => 'public',
                'status' => 'active',
                'deprecated' => false,
                'version' => $version,
                'parameters' => self::normalize_parameters(is_array($parameters) ? $parameters : []),
                'request_body' => self::request_body_from_json($body),
                'responses' => self::responses_from_legacy($response, is_array($errors) ? $errors : []),
                'models' => [],
            ]],
            'models' => self::models($api_id, $version),
            'updated_at' => current_time('mysql'),
        ];
    }

    private static function can_manage($api_id, $user_id){
        return class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can(absint($user_id), absint($api_id), 'apis.manage_schema')
            : user_can(absint($user_id), 'manage_options');
    }

    private static function normalize_parameters($parameters){
        $clean = [];
        foreach ((array) $parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $name = sanitize_text_field($parameter['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = sanitize_key($parameter['type'] ?? 'string');
            if (!in_array($type, self::data_types(), true)) {
                $type = 'string';
            }
            $clean[] = [
                'name' => $name,
                'location' => sanitize_key($parameter['location'] ?? $parameter['in'] ?? 'query'),
                'description' => sanitize_textarea_field($parameter['description'] ?? ''),
                'required' => !empty($parameter['required']),
                'type' => $type,
                'default' => sanitize_text_field($parameter['default'] ?? ''),
                'example' => sanitize_text_field($parameter['example'] ?? ''),
                'rules' => sanitize_text_field($parameter['rules'] ?? ''),
                'allowed_values' => self::clean_list($parameter['allowed_values'] ?? []),
                'nullable' => !empty($parameter['nullable']),
            ];
        }
        return $clean;
    }

    private static function normalize_boolean($value){
        if (is_bool($value)) return $value;
        if ($value === null) return false;
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return !in_array($value, ['', '0', 'false', 'no', 'off', 'null'], true);
        }
        return !empty($value);
    }
    private static function normalize_request_body($body){
        $body = is_array($body) ? $body : [];
        $format = sanitize_key($body['format'] ?? 'json');
        if (!in_array($format, ['json', 'form', 'multipart', 'text'], true)) {
            $format = 'json';
        }
        return [
            'enabled' => self::normalize_boolean($body['enabled'] ?? false),
            'format' => $format,
            'content_type' => sanitize_text_field($body['content_type'] ?? ($format === 'json' ? 'application/json' : 'text/plain')),
            'description' => sanitize_textarea_field($body['description'] ?? ''),
            'fields' => self::normalize_fields($body['fields'] ?? []),
            'example' => is_array($body['example'] ?? null) ? $body['example'] : sanitize_textarea_field($body['example'] ?? ''),
        ];
    }

    private static function normalize_responses($responses){
        $clean = [];
        foreach ((array) $responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            $status_raw = $response['status'] ?? $response['status_code'] ?? 200;
            $status = sanitize_key((string) $status_raw) === 'default' ? 'default' : absint($status_raw);
            if ($status !== 'default' && ($status < 100 || $status > 599)) {
                continue;
            }
            $clean[] = [
                'status' => $status,
                'description' => sanitize_textarea_field($response['description'] ?? ''),
                'headers' => self::normalize_parameters($response['headers'] ?? []),
                'body_schema' => self::normalize_fields($response['body_schema'] ?? []),
                'example' => is_array($response['example'] ?? null) ? $response['example'] : sanitize_textarea_field($response['example'] ?? ''),
                'model' => sanitize_text_field($response['model'] ?? ''),
            ];
        }
        return $clean ?: [['status' => 200, 'description' => 'Success', 'headers' => [], 'body_schema' => [], 'example' => ['message' => 'OK'], 'model' => '']];
    }

    private static function normalize_fields($fields){
        $clean = [];
        foreach ((array) $fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = sanitize_text_field($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = sanitize_key($field['type'] ?? 'string');
            if (!in_array($type, self::data_types(), true)) {
                $type = 'string';
            }
            $clean[] = [
                'name' => $name,
                'type' => $type,
                'description' => sanitize_textarea_field($field['description'] ?? ''),
                'required' => !empty($field['required']),
                'nullable' => !empty($field['nullable']),
                'example' => sanitize_text_field($field['example'] ?? ''),
                'item_type' => sanitize_key($field['item_type'] ?? ''),
                'fields' => self::normalize_fields($field['fields'] ?? []),
            ];
        }
        return $clean;
    }

    private static function request_value($request, $location, $name){
        if ($location === 'header') {
            return $request->get_header($name);
        }
        if ($location === 'body') {
            $body = $request->get_json_params();
            return is_array($body) ? ($body[$name] ?? null) : null;
        }
        return $request->get_param($name);
    }

    private static function validate_body($request, array $endpoint, $reject_unknown = false){
        $body = $endpoint['request_body'] ?? [];
        if (empty($body['enabled'])) {
            return ['errors' => [], 'warnings' => []];
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return ['errors' => [['field' => 'body', 'code' => 'invalid_body', 'message' => 'Request body must be valid JSON.']], 'warnings' => []];
        }
        $errors = [];
        $known = [];
        foreach (($body['fields'] ?? []) as $field) {
            $name = $field['name'];
            $known[] = $name;
            $exists = array_key_exists($name, $params);
            if (!empty($field['required']) && !$exists) {
                $errors[] = ['field' => $name, 'code' => 'required', 'message' => 'Required body field is missing.'];
                continue;
            }
            if ($exists) {
                $type_error = self::validate_type($params[$name], $field);
                if ($type_error) {
                    $errors[] = ['field' => $name, 'code' => 'invalid_type', 'message' => $type_error];
                }
            }
        }

        if ($reject_unknown) {
            foreach (array_keys($params) as $name) {
                if (!in_array($name, $known, true)) {
                    $errors[] = ['field' => $name, 'code' => 'unknown_field', 'message' => 'Unknown body field is not allowed.'];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    private static function validate_type($value, array $definition){
        $type = sanitize_key($definition['type'] ?? 'string');
        if ($value === null && !empty($definition['nullable'])) {
            return '';
        }
        if (!empty($definition['allowed_values']) && !in_array((string) $value, array_map('strval', $definition['allowed_values']), true)) {
            return 'Value is not in the allowed set.';
        }
        if ($type === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) return 'Value must be an integer.';
        if ($type === 'number' && !is_numeric($value)) return 'Value must be numeric.';
        if ($type === 'boolean' && !in_array($value, [true, false, 'true', 'false', '1', '0', 1, 0], true)) return 'Value must be boolean.';
        if ($type === 'array' && !is_array($value)) return 'Value must be an array.';
        if ($type === 'object' && !is_array($value)) return 'Value must be an object.';
        if ($type === 'email' && !is_email((string) $value)) return 'Value must be an email address.';
        if ($type === 'url' && !wp_http_validate_url((string) $value)) return 'Value must be a URL.';
        if ($type === 'uuid' && !preg_match('/^[0-9a-fA-F-]{36}$/', (string) $value)) return 'Value must be a UUID.';
        return '';
    }

    private static function endpoint_for_method(array $schema, $method){
        foreach ($schema['endpoints'] as $endpoint) {
            if (in_array($method, array_map('strtoupper', $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET']), true)) {
                return $endpoint;
            }
        }
        return null;
    }

    private static function endpoint_map(array $schema){
        $map = [];
        foreach ($schema['endpoints'] as $endpoint) {
            $key = strtoupper($endpoint['method'] ?? 'GET') . ' ' . ($endpoint['path'] ?? '');
            $map[$key] = $endpoint;
        }
        return $map;
    }

    private static function parameter_map(array $endpoint){
        $map = [];
        foreach (($endpoint['parameters'] ?? []) as $parameter) {
            $key = sanitize_key($parameter['location'] ?? 'query') . ':' . sanitize_key($parameter['name'] ?? '');
            $map[$key] = $parameter;
        }
        return $map;
    }

    private static function schema_methods(array $schema){
        $methods = [];
        foreach ($schema['endpoints'] as $endpoint) {
            $methods = array_merge($methods, $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET']);
        }
        return array_values(array_unique(array_map('strtoupper', $methods ?: ['GET'])));
    }

    private static function legacy_parameters(array $schema){
        return $schema['endpoints'][0]['parameters'] ?? [];
    }

    private static function request_body_example(array $schema){
        return wp_json_encode(self::example_from_fields($schema['endpoints'][0]['request_body']['fields'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function success_response_example(array $schema){
        return wp_json_encode($schema['endpoints'][0]['responses'][0]['example'] ?? ['message' => 'OK'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function error_responses(array $schema){
        return self::documentation_errors($schema['endpoints'][0] ?? []);
    }

    private static function documentation_body(array $endpoint){
        $body = $endpoint['request_body'] ?? [];
        return [
            'enabled' => self::normalize_boolean($body['enabled'] ?? false),
            'content_type' => $body['content_type'] ?? 'application/json',
            'example' => wp_json_encode(is_array($body['example'] ?? null) ? $body['example'] : self::example_from_fields($body['fields'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'source' => 'Auto-generated from endpoint schema.',
            'fields' => $body['fields'] ?? [],
        ];
    }

    private static function documentation_response(array $endpoint){
        $response = $endpoint['responses'][0] ?? [];
        return [
            'label' => 'Auto-generated from endpoint schema',
            'code' => wp_json_encode($response['example'] ?? ['message' => 'OK'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private static function documentation_errors(array $endpoint){
        $errors = [];
        foreach (($endpoint['responses'] ?? []) as $response) {
            if ((int) ($response['status'] ?? 200) >= 400) {
                $errors[] = [
                    'status' => (int) $response['status'],
                    'code' => sanitize_key($response['description'] ?: 'error'),
                    'meaning' => $response['description'] ?: 'Error response.',
                    'resolution' => 'Review the endpoint schema and request values.',
                ];
            }
        }
        return $errors;
    }

    private static function request_body_from_json($json){
        $decoded = json_decode((string) $json, true);
        return [
            'enabled' => trim((string) $json) !== '',
            'format' => 'json',
            'content_type' => 'application/json',
            'description' => '',
            'fields' => [],
            'example' => is_array($decoded) ? $decoded : [],
        ];
    }

    private static function responses_from_legacy($response, array $errors){
        $decoded = json_decode((string) $response, true);
        $responses = [['status' => 200, 'description' => 'Success', 'headers' => [], 'body_schema' => [], 'example' => is_array($decoded) ? $decoded : ['message' => 'OK'], 'model' => '']];
        foreach ($errors as $error) {
            $responses[] = [
                'status' => absint($error['status'] ?? 400),
                'description' => sanitize_text_field($error['meaning'] ?? $error['description'] ?? 'Error'),
                'headers' => [],
                'body_schema' => [],
                'example' => ['error' => sanitize_key($error['code'] ?? 'error')],
                'model' => 'ErrorResponse',
            ];
        }
        return $responses;
    }

    private static function example_from_fields(array $fields){
        $example = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') continue;
            $example[$name] = ($field['example'] ?? '') !== '' ? $field['example'] : self::type_example($field['type'] ?? 'string');
        }
        return $example;
    }

    private static function type_example($type){
        if ($type === 'integer') return 123;
        if ($type === 'number') return 12.3;
        if ($type === 'boolean') return true;
        if ($type === 'array') return [];
        if ($type === 'object') return new stdClass();
        if ($type === 'email') return 'user@example.com';
        if ($type === 'url') return 'https://example.com';
        if ($type === 'uuid') return '00000000-0000-4000-8000-000000000000';
        return 'string';
    }

    private static function clean_list($value){
        if (is_string($value)) {
            $value = preg_split('/[\n,]+/', $value);
        }
        return array_values(array_filter(array_map('sanitize_text_field', (array) $value)));
    }

    private static function sanitize_methods($value){
        $raw = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $methods = [];
        foreach ($raw as $method) {
            $method = strtoupper(sanitize_key($method));
            if (in_array($method, $allowed, true)) {
                $methods[] = $method;
            }
        }
        return array_values(array_unique($methods ?: ['GET']));
    }
}

class APIPlatform_Endpoint_Example_Generator {
    public static function examples($endpoint, array $schema_endpoint){
        if (class_exists('APIPlatform_SDK_Generator_Service')) {
            return APIPlatform_SDK_Generator_Service::endpoint_snippets($endpoint, $schema_endpoint);
        }

        $method = strtoupper($schema_endpoint['method'] ?? 'GET');
        $body = $schema_endpoint['request_body'] ?? [];
        $has_body = !empty($body['enabled']);
        $body_example = $body['example'] ?? [];
        $body_json = $has_body ? wp_json_encode(is_array($body_example) ? $body_example : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';

        return [
            'curl' => ['title' => 'curl', 'language' => 'bash', 'code' => self::curl($endpoint, $method, $has_body, $body_json)],
            'javascript' => ['title' => 'JavaScript fetch', 'language' => 'javascript', 'code' => self::fetch($endpoint, $method, $has_body, $body_json)],
            'node' => ['title' => 'Node.js', 'language' => 'javascript', 'code' => self::fetch($endpoint, $method, $has_body, $body_json)],
            'php' => ['title' => 'PHP cURL', 'language' => 'php', 'code' => self::php($endpoint, $method, $has_body, $body_json)],
            'python' => ['title' => 'Python requests', 'language' => 'python', 'code' => self::python($endpoint, $method, $has_body, $body_json)],
            'csharp' => ['title' => 'C#', 'language' => 'csharp', 'code' => "using var client = new HttpClient();\nclient.DefaultRequestHeaders.Add(\"X-API-Key\", \"YOUR_APPLICATION_KEY\");\nvar response = await client.SendAsync(new HttpRequestMessage(HttpMethod." . ucfirst(strtolower($method)) . ", \"" . $endpoint . "\"));\nvar body = await response.Content.ReadAsStringAsync();"],
        ];
    }

    private static function curl($endpoint, $method, $has_body, $body){
        $lines = ['curl -X ' . $method . ' "' . $endpoint . '" \\', '  -H "X-API-Key: YOUR_APPLICATION_KEY" \\', '  -H "Accept: application/json"'];
        if ($has_body) {
            $lines[] = '  -H "Content-Type: application/json" \\';
            $lines[] = "  --data '" . $body . "'";
        }
        return implode("\n", $lines);
    }

    private static function fetch($endpoint, $method, $has_body, $body){
        return "const response = await fetch(\"" . $endpoint . "\", {\n  method: \"" . $method . "\",\n  headers: {\n    \"X-API-Key\": \"YOUR_APPLICATION_KEY\",\n    \"Accept\": \"application/json\"" . ($has_body ? ",\n    \"Content-Type\": \"application/json\"" : "") . "\n  }" . ($has_body ? ",\n  body: JSON.stringify(" . $body . ")" : "") . "\n});\n\nconst data = await response.json();";
    }

    private static function php($endpoint, $method, $has_body, $body){
        return "<?php\n\n\$ch = curl_init('" . $endpoint . "');\ncurl_setopt_array(\$ch, [\n    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_CUSTOMREQUEST => '" . $method . "'," . ($has_body ? "\n    CURLOPT_POSTFIELDS => '" . str_replace("'", "\\'", $body) . "'," : "") . "\n    CURLOPT_HTTPHEADER => [\n        'X-API-Key: YOUR_APPLICATION_KEY',\n        'Accept: application/json'," . ($has_body ? "\n        'Content-Type: application/json'," : "") . "\n    ],\n]);\n\n\$response = curl_exec(\$ch);";
    }

    private static function python($endpoint, $method, $has_body, $body){
        return "import requests\n\nresponse = requests.request(\n    \"" . $method . "\",\n    \"" . $endpoint . "\",\n    headers={\"X-API-Key\": \"YOUR_APPLICATION_KEY\", \"Accept\": \"application/json\"},\n    timeout=30" . ($has_body ? ",\n    json=" . $body : "") . ",\n)\nprint(response.json())";
    }
}

add_filter('apiplatform_api_documentation_data', function($data, $api){
    return class_exists('APIPlatform_Endpoint_Schema_Service')
        ? APIPlatform_Endpoint_Schema_Service::documentation_data($api->ID, $data)
        : $data;
}, 20, 2);

add_filter('apiplatform_api_documentation_supported_methods', function($methods, $api){
    $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api->ID);
    $out = [];
    foreach ($schema['endpoints'] as $endpoint) {
        $out = array_merge($out, $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET']);
    }
    return array_values(array_unique(array_map('strtoupper', $out ?: $methods)));
}, 20, 2);

add_filter('apiplatform_api_tester_supported_methods', function($methods, $api){
    return apply_filters('apiplatform_api_documentation_supported_methods', $methods, $api);
}, 20, 2);






