<?php
if (!defined('ABSPATH')) exit;

interface APIPlatform_SDK_Language_Interface {
    public function language();
    public function label();
    public function preview(array $ir, array $settings);
    public function files(array $ir, array $settings);
    public function snippet(array $ir, array $endpoint);
}

class APIPlatform_SDK_Generator_Service {

    const META_SETTINGS = 'apiplatform_sdk_settings';
    const GENERATOR_VERSION = '7F.1';

    public static function languages(){
        return [
            'javascript' => 'JavaScript Fetch',
            'node' => 'Node.js',
            'python' => 'Python',
            'php' => 'PHP',
            'csharp' => 'C#',
        ];
    }

    public static function normalize_language($language){
        $raw = strtolower(trim((string) $language));
        $raw = str_replace(['_', ' '], ['-', '-'], $raw);
        $aliases = [
            'js' => 'javascript',
            'javascript-fetch' => 'javascript',
            'fetch' => 'javascript',
            'nodejs' => 'node',
            'node-js' => 'node',
            'py' => 'python',
            'c#' => 'csharp',
            'cs' => 'csharp',
            'c-sharp' => 'csharp',
        ];
        $language = $aliases[$raw] ?? sanitize_key($raw);

        return isset(self::languages()[$language]) ? $language : '';
    }

    public static function settings($api_id){
        $stored = get_post_meta($api_id, self::META_SETTINGS, true);
        $stored = is_array($stored) ? $stored : [];
        $allowed = array_values(array_intersect((array) ($stored['allowed_languages'] ?? array_keys(self::languages())), array_keys(self::languages())));

        return [
            'enabled' => !empty($stored['enabled']),
            'allowed_languages' => $allowed ?: array_keys(self::languages()),
            'package_name' => sanitize_title($stored['package_name'] ?? get_post_field('post_name', $api_id)),
            'namespace' => self::clean_identifier($stored['namespace'] ?? 'FreedomAPI'),
            'client_class' => self::clean_identifier($stored['client_class'] ?? 'FreedomApiClient'),
            'include_models' => !isset($stored['include_models']) || !empty($stored['include_models']),
            'include_examples' => !isset($stored['include_examples']) || !empty($stored['include_examples']),
        ];
    }

    public static function save_settings($api_id, $user_id, array $data){
        if (class_exists('APIPlatform_Ownership_Service') && !APIPlatform_Ownership_Service::can(absint($user_id), absint($api_id), 'apis.manage_docs')) {
            return 'Error: You cannot change SDK settings for that API.';
        }
        $languages = [];
        foreach ((array) ($data['allowed_languages'] ?? []) as $language) {
            $language = self::normalize_language($language);
            if ($language !== '') {
                $languages[] = $language;
            }
        }
        $languages = array_values(array_unique($languages));
        $settings = [
            'enabled' => !empty($data['enabled']),
            'allowed_languages' => $languages ?: array_keys(self::languages()),
            'package_name' => sanitize_title($data['package_name'] ?? get_post_field('post_name', $api_id)),
            'namespace' => self::clean_identifier($data['namespace'] ?? 'FreedomAPI'),
            'client_class' => self::clean_identifier($data['client_class'] ?? 'FreedomApiClient'),
            'include_models' => !empty($data['include_models']),
            'include_examples' => !empty($data['include_examples']),
            'updated_at' => current_time('mysql'),
            'updated_by_user_id' => absint($user_id),
        ];

        update_post_meta($api_id, self::META_SETTINGS, $settings);
        if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
            APIPlatform_Endpoint_Schema_Service::record_event($api_id, $user_id, $settings['enabled'] ? 'sdk_downloads_enabled' : 'sdk_downloads_disabled', $settings['enabled'] ? 'SDK downloads enabled.' : 'SDK downloads disabled.');
        }

        return 'SDK generation settings saved.';
    }

    public static function preview($api_id, $language, array $options = []){
        $language = self::normalize_language($language);
        if ($language === '') {
            return ['ok' => false, 'message' => 'Select a supported SDK language.'];
        }
        $ir_result = self::intermediate($api_id, $options['version'] ?? '');
        if (empty($ir_result['ok'])) {
            return $ir_result;
        }
        $generator = self::generator($language);
        if (!$generator) {
            return ['ok' => false, 'message' => 'Unsupported SDK language.'];
        }
        $settings = array_merge(self::settings($api_id), self::clean_options($options));
        if (!in_array($generator->language(), $settings['allowed_languages'], true)) {
            return ['ok' => false, 'message' => 'That SDK language is not enabled for this API.'];
        }
        $preview = $generator->preview($ir_result['ir'], $settings);

        return [
            'ok' => true,
            'language' => $generator->language(),
            'label' => $generator->label(),
            'ir' => $ir_result['ir'],
            'files' => $preview['files'],
            'methods' => $preview['methods'],
            'models' => $preview['models'],
            'warnings' => array_merge($ir_result['warnings'], $preview['warnings']),
            'unsupported' => $ir_result['unsupported'],
            'settings' => $settings,
        ];
    }

    public static function package($api_id, $language, array $options = []){
        $language = self::normalize_language($language);
        if ($language === '') {
            return ['ok' => false, 'message' => 'Select a supported SDK language.'];
        }
        $preview = self::preview($api_id, $language, $options);
        if (empty($preview['ok'])) {
            return $preview;
        }
        $generator = self::generator($language);
        $files = $generator->files($preview['ir'], $preview['settings']);
        if (empty($preview['settings']['include_examples'])) {
            foreach (array_keys($files) as $path) {
                if (strpos($path, 'examples/') === 0 || strpos($path, 'Examples/') === 0) {
                    unset($files[$path]);
                }
            }
        }
        if (!$files) {
            return ['ok' => false, 'message' => 'SDK generator returned no files for ' . $preview['label'] . '.'];
        }

        return [
            'ok' => true,
            'language' => $preview['language'],
            'label' => $preview['label'],
            'root' => self::package_root($preview['ir'], $preview['language'], $preview['settings']),
            'files' => $files,
            'warnings' => $preview['warnings'],
        ];
    }

    public static function archive($api_id, $user_id, $language, array $options = []){
        $language = self::normalize_language($language);
        if ($language === '') {
            return ['ok' => false, 'message' => 'Select a supported SDK language.'];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'ZIP generation requires the PHP ZipArchive extension.'];
        }
        $package = self::package($api_id, $language, $options);
        if (empty($package['ok'])) {
            return $package;
        }

        $upload = wp_upload_dir();
        if (empty($upload['basedir'])) {
            return ['ok' => false, 'message' => 'Upload directory is unavailable.'];
        }

        $base = trailingslashit($upload['basedir']) . 'apiplatform-sdk-tmp';
        if (!wp_mkdir_p($base)) {
            return ['ok' => false, 'message' => 'Temporary SDK directory could not be created.'];
        }
        if (!file_exists($base . '/index.html')) {
            file_put_contents($base . '/index.html', '');
        }

        self::cleanup($base);
        $token = wp_generate_password(24, false, false);
        $settings_hash = substr(md5(wp_json_encode(self::settings($api_id))), 0, 10);
        $dir = trailingslashit($base) . absint($user_id) . '-' . absint($api_id) . '-' . sanitize_key($language) . '-' . sanitize_title($options['version'] ?? '') . '-' . self::GENERATOR_VERSION . '-' . $settings_hash . '-' . $token;
        if (!wp_mkdir_p($dir)) {
            return ['ok' => false, 'message' => 'Temporary SDK workspace could not be created.'];
        }

        $zip_path = trailingslashit($dir) . sanitize_file_name($package['root']) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            self::delete_dir($dir);
            return ['ok' => false, 'message' => 'SDK archive could not be opened.'];
        }

        foreach ($package['files'] as $relative => $content) {
            $relative = self::safe_relative_path($package['root'] . '/' . $relative);
            if ($relative === '') {
                continue;
            }
            $zip->addFromString($relative, (string) $content);
        }
        $zip->close();

        if (!file_exists($zip_path)) {
            self::delete_dir($dir);
            return ['ok' => false, 'message' => 'SDK archive was not created.'];
        }

        if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
            APIPlatform_Endpoint_Schema_Service::record_event($api_id, $user_id, 'sdk_download_generated', 'SDK download generated for ' . $package['label'] . '.');
        }

        return [
            'ok' => true,
            'path' => $zip_path,
            'dir' => $dir,
            'filename' => basename($zip_path),
            'content_type' => 'application/zip',
        ];
    }

    public static function documentation_examples($api_id, $endpoint_url = '', array $schema_endpoint = []){
        $ir_result = self::intermediate($api_id);
        if (empty($ir_result['ok'])) {
            return self::endpoint_snippets($endpoint_url, $schema_endpoint);
        }
        $endpoint = $ir_result['ir']['endpoints'][0] ?? [];
        if ($schema_endpoint) {
            foreach ($ir_result['ir']['endpoints'] as $candidate) {
                if (($candidate['method'] ?? '') === strtoupper($schema_endpoint['method'] ?? 'GET')) {
                    $endpoint = $candidate;
                    break;
                }
            }
        }

        $examples = ['curl' => self::curl_snippet($endpoint)];
        foreach (self::languages() as $language => $label) {
            $generator = self::generator($language);
            if ($generator) {
                $examples[$language] = [
                    'title' => $label,
                    'language' => $language === 'csharp' ? 'csharp' : ($language === 'php' ? 'php' : ($language === 'python' ? 'python' : 'javascript')),
                    'code' => $generator->snippet($ir_result['ir'], $endpoint),
                ];
            }
        }
        return $examples;
    }

    public static function endpoint_snippets($endpoint_url, array $schema_endpoint){
        $method = strtoupper($schema_endpoint['method'] ?? 'GET');
        $body = $schema_endpoint['request_body'] ?? [];
        $has_body = !empty($body['enabled']);
        $body_json = $has_body ? wp_json_encode(is_array($body['example'] ?? null) ? $body['example'] : new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        $endpoint = [
            'method' => $method,
            'url' => esc_url_raw($endpoint_url),
            'path' => esc_url_raw($endpoint_url),
            'method_name' => strtolower($method) . 'Request',
            'path_parameters' => [],
            'query_parameters' => [],
            'header_parameters' => [],
            'body_enabled' => $has_body,
            'body_json' => $body_json,
        ];
        $ir = ['base_url' => '', 'endpoints' => [$endpoint], 'api' => ['title' => 'FreedomAPI'], 'version' => ''];
        $examples = ['curl' => self::curl_snippet($endpoint)];
        foreach (self::languages() as $language => $label) {
            $generator = self::generator($language);
            $examples[$language] = [
                'title' => $label,
                'language' => $language === 'csharp' ? 'csharp' : ($language === 'php' ? 'php' : ($language === 'python' ? 'python' : 'javascript')),
                'code' => $generator->snippet($ir, $endpoint),
            ];
        }
        return $examples;
    }

    public static function intermediate($api_id, $version = ''){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return ['ok' => false, 'message' => 'Canonical endpoint schema service is unavailable.'];
        }
        $api = get_post($api_id);
        if (!$api) {
            return ['ok' => false, 'message' => 'API not found.'];
        }

        $requested_version = sanitize_text_field($version);
        $version = sanitize_text_field($version ?: APIPlatform_Endpoint_Schema_Service::version_label($api_id));
        $schema = APIPlatform_Endpoint_Schema_Service::schema_for_version($api_id, $version);
        if (!$schema) {
            if ($requested_version !== '') {
                return ['ok' => false, 'message' => 'Selected API version is not available for SDK generation.'];
            }
            $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api_id);
            $version = $schema['version'] ?? $version;
        }
        $models = APIPlatform_Endpoint_Schema_Service::models($api_id, $version);
        $settings = self::settings($api_id);
        $base_url = self::public_base_url($api);
        $warnings = [];
        $unsupported = [];
        $names = [];
        $endpoints = [];

        foreach (($schema['endpoints'] ?? []) as $endpoint) {
            $method = strtoupper($endpoint['method'] ?? 'GET');
            $path = self::endpoint_path($endpoint, $api);
            $name = self::operation_name($method, $path, $endpoint);
            if (isset($names[$name])) {
                $names[$name]++;
                $warnings[] = 'Duplicate method name ' . $name . ' renamed to ' . $name . $names[$name] . '.';
                $name .= $names[$name];
            } else {
                $names[$name] = 1;
            }
            if (empty($endpoint['operationId'])) {
                $warnings[] = $method . ' ' . $path . ' is missing operationId; generated method name ' . $name . '.';
            }
            $parameters = self::parameters($endpoint['parameters'] ?? []);
            $relative_path = self::gateway_relative_path($path, $api);
            if (!empty($parameters['cookie'])) {
                $unsupported[] = $method . ' ' . $path . ' uses cookie parameters; generated clients document them but do not manage browser cookie state.';
            }
            $body = $endpoint['request_body'] ?? [];
            $endpoints[] = [
                'id' => sanitize_title($method . '-' . trim($path, '/')),
                'method' => $method,
                'path' => $relative_path,
                'url' => $base_url . $relative_path,
                'summary' => sanitize_text_field($endpoint['summary'] ?? ''),
                'description' => sanitize_textarea_field($endpoint['description'] ?? ''),
                'method_name' => $name,
                'path_parameters' => $parameters['path'],
                'query_parameters' => $parameters['query'],
                'header_parameters' => $parameters['header'],
                'cookie_parameters' => $parameters['cookie'],
                'required_parameters' => self::required_parameters($parameters),
                'body_enabled' => !empty($body['enabled']),
                'body_format' => sanitize_key($body['format'] ?? 'json'),
                'body_content_type' => sanitize_text_field($body['content_type'] ?? 'application/json'),
                'body_fields' => $body['fields'] ?? [],
                'body_json' => !empty($body['enabled']) ? wp_json_encode(is_array($body['example'] ?? null) ? $body['example'] : new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
                'responses' => $endpoint['responses'] ?? [],
            ];
        }

        if (!$endpoints) {
            return ['ok' => false, 'message' => 'No canonical endpoints are available for SDK generation.'];
        }

        return [
            'ok' => true,
            'warnings' => array_values(array_unique($warnings)),
            'unsupported' => array_values(array_unique($unsupported)),
            'ir' => [
                'api_id' => absint($api_id),
                'api' => [
                    'title' => get_the_title($api),
                    'slug' => $api->post_name,
                    'summary' => get_post_meta($api_id, 'apiplatform_portal_summary', true),
                ],
                'version' => $version,
                'base_url' => $base_url,
                'auth' => [
                    'type' => 'application_key',
                    'header' => 'X-API-Key',
                    'placeholder' => 'YOUR_APPLICATION_KEY',
                ],
                'endpoints' => $endpoints,
                'models' => $settings['include_models'] ? self::models($models) : [],
                'settings' => [
                    'namespace' => $settings['namespace'],
                    'client_class' => $settings['client_class'],
                    'package_name' => $settings['package_name'],
                ],
                'generated_at' => current_time('mysql'),
                'generator_version' => self::GENERATOR_VERSION,
            ],
        ];
    }

    public static function generator($language){
        $language = self::normalize_language($language);
        if ($language === '') {
            return null;
        }
        $map = [
            'javascript' => 'APIPlatform_SDK_JavaScript_Generator',
            'node' => 'APIPlatform_SDK_Node_Generator',
            'python' => 'APIPlatform_SDK_Python_Generator',
            'php' => 'APIPlatform_SDK_PHP_Generator',
            'csharp' => 'APIPlatform_SDK_CSharp_Generator',
        ];
        if (empty($map[$language]) || !class_exists($map[$language])) {
            return null;
        }
        return new $map[$language]();
    }

    public static function package_root(array $ir, $language, array $settings){
        $language = self::normalize_language($language);
        return sanitize_file_name(($settings['package_name'] ?: sanitize_title($ir['api']['title'])) . '-' . sanitize_title($ir['version']) . '-' . sanitize_key($language));
    }

    private static function clean_options(array $options){
        $clean = [];
        foreach (['package_name', 'namespace', 'client_class', 'version'] as $key) {
            if (isset($options[$key])) {
                $clean[$key] = $key === 'package_name'
                    ? sanitize_title($options[$key])
                    : ($key === 'version' ? sanitize_text_field($options[$key]) : self::clean_identifier($options[$key]));
            }
        }
        foreach (['include_models', 'include_examples'] as $key) {
            if (isset($options[$key])) {
                $clean[$key] = !empty($options[$key]);
            }
        }
        return $clean;
    }

    private static function clean_identifier($value){
        $value = preg_replace('/[^A-Za-z0-9_\\\\]/', '', (string) $value);
        return $value !== '' ? $value : 'FreedomAPI';
    }

    private static function endpoint_path(array $endpoint, $api){
        $path = sanitize_text_field($endpoint['path'] ?? '');
        if ($path === '') {
            $path = '/platform/v1/api/' . ($api ? $api->post_name : 'api');
        }
        return '/' . ltrim($path, '/');
    }

    private static function public_base_url($api){
        if (function_exists('apiplatform_public_gateway_url') && $api) {
            return untrailingslashit(apiplatform_public_gateway_url($api));
        }

        if ($api && class_exists('APIPlatform_Routes')) {
            return untrailingslashit(APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name));
        }

        return untrailingslashit(rest_url('platform/v1/api'));
    }

    private static function gateway_relative_path($path, $api){
        $path = '/' . ltrim((string) $path, '/');
        $api_slug = $api ? sanitize_title($api->post_name) : 'api';
        $legacy_path = '/platform/v1/api/' . $api_slug;

        if ($path === $legacy_path || $path === '/' . $api_slug) {
            return '';
        }

        if (strpos($path, '/platform/v1/api/') === 0) {
            $path = substr($path, strlen('/platform/v1/api'));
        }

        return '/' . ltrim($path, '/');
    }

    private static function operation_name($method, $path, array $endpoint){
        if (!empty($endpoint['operationId'])) {
            return self::camel($endpoint['operationId']);
        }
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($method . ' ' . trim($path, '/')));
        $parts = array_values(array_filter($parts, function($part){ return !in_array($part, ['platform', 'v1', 'api'], true); }));
        return self::camel(implode(' ', $parts ?: [strtolower($method), 'request']));
    }

    public static function camel($value){
        $parts = preg_split('/[^A-Za-z0-9]+/', (string) $value);
        $parts = array_values(array_filter($parts));
        if (!$parts) return 'request';
        $first = strtolower(array_shift($parts));
        foreach ($parts as $part) {
            $first .= ucfirst(strtolower($part));
        }
        return $first;
    }

    public static function pascal($value){
        return ucfirst(self::camel($value));
    }

    public static function snake($value){
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $value);
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        return strtolower(trim($value, '_')) ?: 'request';
    }

    private static function parameters(array $parameters){
        $grouped = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $location = sanitize_key($parameter['location'] ?? $parameter['in'] ?? 'query');
            if (!isset($grouped[$location])) {
                $location = 'query';
            }
            $parameter['name'] = sanitize_text_field($parameter['name'] ?? '');
            if ($parameter['name'] === '') {
                continue;
            }
            $parameter['var'] = self::camel($parameter['name']);
            $parameter['snake'] = self::snake($parameter['name']);
            $grouped[$location][] = $parameter;
        }
        return $grouped;
    }

    private static function required_parameters(array $grouped){
        $required = [];
        foreach (array_merge($grouped['path'], $grouped['query'], $grouped['header']) as $parameter) {
            if (!empty($parameter['required'])) {
                $required[] = $parameter;
            }
        }
        return $required;
    }

    private static function models(array $models){
        $out = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = sanitize_text_field($model['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'class' => self::pascal($name),
                'description' => sanitize_textarea_field($model['description'] ?? ''),
                'fields' => $model['fields'] ?? [],
            ];
        }
        return $out;
    }

    public static function curl_snippet(array $endpoint){
        $lines = ['curl -X ' . $endpoint['method'] . ' "' . self::example_url($endpoint) . '" \\', '  -H "X-API-Key: YOUR_APPLICATION_KEY" \\', '  -H "Accept: application/json"'];
        if (!empty($endpoint['body_enabled'])) {
            $lines[] = '  -H "Content-Type: ' . ($endpoint['body_content_type'] ?? 'application/json') . '" \\';
            $lines[] = "  --data '" . ($endpoint['body_json'] ?: '{}') . "'";
        }
        return implode("\n", $lines);
    }

    public static function example_url(array $endpoint){
        $url = $endpoint['url'] ?? $endpoint['path'] ?? '';
        foreach (($endpoint['path_parameters'] ?? []) as $parameter) {
            $replacement = rawurlencode(strtoupper($parameter['name'] ?? 'VALUE'));
            $url = str_replace('{' . ($parameter['name'] ?? '') . '}', $replacement, $url);
        }
        return $url;
    }

    public static function safe_relative_path($path){
        $path = str_replace('\\', '/', (string) $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = sanitize_file_name($part);
        }
        return implode('/', $parts);
    }

    public static function cleanup($base){
        if (!is_dir($base)) {
            return;
        }
        $expires = time() - HOUR_IN_SECONDS;
        foreach (glob(trailingslashit($base) . '*') ?: [] as $path) {
            if (is_dir($path) && filemtime($path) < $expires) {
                self::delete_dir($path);
            }
        }
    }

    public static function delete_dir($dir){
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::delete_dir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

abstract class APIPlatform_SDK_Abstract_Generator implements APIPlatform_SDK_Language_Interface {
    public function preview(array $ir, array $settings){
        $files = $this->files($ir, $settings);
        if (empty($settings['include_examples'])) {
            foreach (array_keys($files) as $path) {
                if (strpos($path, 'examples/') === 0 || strpos($path, 'Examples/') === 0) {
                    unset($files[$path]);
                }
            }
        }
        return [
            'files' => array_keys($files),
            'methods' => array_map(function($endpoint){ return $endpoint['method_name'] . ' - ' . $endpoint['method'] . ' ' . $endpoint['path']; }, $ir['endpoints']),
            'models' => array_map(function($model){ return $model['name']; }, $ir['models']),
            'warnings' => $this->warnings($ir),
        ];
    }

    protected function warnings(array $ir){
        $warnings = [];
        foreach ($ir['endpoints'] as $endpoint) {
            if (empty($endpoint['body_json']) && !empty($endpoint['body_enabled'])) {
                $warnings[] = $endpoint['method'] . ' ' . $endpoint['path'] . ' has no request body example; generated client uses an empty object placeholder.';
            }
        }
        return $warnings;
    }

    protected function readme(array $ir, array $settings, $install){
        $methods = implode("\n", array_map(function($endpoint){ return '- `' . $endpoint['method_name'] . '` ' . $endpoint['method'] . ' ' . $endpoint['path']; }, $ir['endpoints']));
        $models = $ir['models'] ? implode("\n", array_map(function($model){ return '- `' . $model['name'] . '`'; }, $ir['models'])) : '- No reusable models in this version.';
        return '# ' . $ir['api']['title'] . " SDK\n\n" .
            'API version: `' . $ir['version'] . "`\n\n" .
            'Generated by FreedomAPI SDK Generator `' . $ir['generator_version'] . '` at `' . $ir['generated_at'] . "`.\n\n" .
            "## Install\n\n" . $install . "\n\n" .
            "## Authentication\n\nSet `FREEDOMAPI_KEY` in your environment. Do not commit application keys.\n\n" .
            "## Base URL\n\nDefault base URL: `" . $ir['base_url'] . "`\n\n" .
            "## Endpoints\n\n" . $methods . "\n\n" .
            "## Models\n\n" . $models . "\n\n" .
            "## Errors\n\nGenerated clients expose structured errors with HTTP status, error code, message, request ID when present, and response body when safe.\n";
    }

    protected function jsMethodName($name){ return APIPlatform_SDK_Generator_Service::camel($name); }
    protected function pyMethodName($name){ return APIPlatform_SDK_Generator_Service::snake($name); }
    protected function csMethodName($name){ return APIPlatform_SDK_Generator_Service::pascal($name) . 'Async'; }
}

class APIPlatform_SDK_JavaScript_Generator extends APIPlatform_SDK_Abstract_Generator {
    public function language(){ return 'javascript'; }
    public function label(){ return 'JavaScript Fetch'; }

    public function files(array $ir, array $settings){
        return [
            'README.md' => $this->readme($ir, $settings, 'Copy `src/client.js` into your frontend project.'),
            'package.json' => wp_json_encode(['name' => $settings['package_name'], 'version' => '0.1.0', 'type' => 'module'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'src/errors.js' => "export class FreedomApiError extends Error {\n  constructor(message, details = {}) {\n    super(message);\n    this.name = 'FreedomApiError';\n    this.status = details.status;\n    this.code = details.code;\n    this.requestId = details.requestId;\n    this.body = details.body;\n  }\n}\n",
            'src/models.js' => $this->models($ir),
            'src/client.js' => $this->client($ir, false),
            'examples/first-request.js' => $this->snippet($ir, $ir['endpoints'][0]),
        ];
    }

    public function snippet(array $ir, array $endpoint){
        return "import { FreedomApiClient } from './src/client.js';\n\nconst client = new FreedomApiClient({ apiKey: 'YOUR_APPLICATION_KEY' });\nconst result = await client." . $this->jsMethodName($endpoint['method_name']) . "({});\nconsole.log(result.data);\n";
    }

    protected function client(array $ir, $node){
        $methods = '';
        foreach ($ir['endpoints'] as $endpoint) {
            $methods .= $this->method($endpoint);
        }
        return "import { FreedomApiError } from './errors.js';\n\nexport class FreedomApiClient {\n  constructor({ apiKey, baseUrl = '" . esc_js($ir['base_url']) . "' } = {}) {\n    this.apiKey = apiKey || " . ($node ? "process.env.FREEDOMAPI_KEY" : "''") . ";\n    this.baseUrl = baseUrl.replace(/\\/$/, '');\n  }\n\n  async request(method, path, { query = {}, headers = {}, body } = {}) {\n    const url = new URL(this.baseUrl + path);\n    Object.entries(query).forEach(([key, value]) => { if (value !== undefined && value !== null) url.searchParams.append(key, value); });\n    const response = await fetch(url, { method, headers: { 'X-API-Key': this.apiKey || 'YOUR_APPLICATION_KEY', 'Accept': 'application/json', ...headers }, body });\n    const text = await response.text();\n    const data = text ? JSON.parse(text) : null;\n    if (!response.ok) throw new FreedomApiError(data?.message || 'FreedomAPI request failed', { status: response.status, code: data?.code, requestId: response.headers.get('x-request-id'), body: data });\n    return { status: response.status, headers: response.headers, data };\n  }\n" . $methods . "}\n";
    }

    protected function method(array $endpoint){
        $path = addslashes($endpoint['path']);
        foreach ($endpoint['path_parameters'] as $parameter) {
            $path = str_replace('{' . $parameter['name'] . '}', '${encodeURIComponent(options.' . $parameter['var'] . ')}', $path);
        }
        $query = $this->jsObject($endpoint['query_parameters']);
        $headers = $this->jsObject($endpoint['header_parameters'], true);
        $body = !empty($endpoint['body_enabled']) ? ", body: JSON.stringify(options.body || " . ($endpoint['body_json'] ?: '{}') . "), headers: { 'Content-Type': '" . esc_js($endpoint['body_content_type']) . "', ...headers }" : ', headers';
        return "\n  async " . $this->jsMethodName($endpoint['method_name']) . "(options = {}) {\n    const path = `" . $path . "`;\n    const query = " . $query . ";\n    const headers = " . $headers . ";\n    return this.request('" . $endpoint['method'] . "', path, { query" . $body . " });\n  }\n";
    }

    protected function jsObject(array $parameters, $header = false){
        $pairs = [];
        foreach ($parameters as $parameter) {
            $key = $header ? $parameter['name'] : $parameter['name'];
            $pairs[] = "'" . esc_js($key) . "': options." . $parameter['var'];
        }
        return '{ ' . implode(', ', $pairs) . ' }';
    }

    protected function models(array $ir){
        $out = "export const models = ";
        return $out . wp_json_encode($ir['models'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ";\n";
    }
}

class APIPlatform_SDK_Node_Generator extends APIPlatform_SDK_JavaScript_Generator {
    public function language(){ return 'node'; }
    public function label(){ return 'Node.js'; }

    public function files(array $ir, array $settings){
        $files = parent::files($ir, $settings);
        $files['README.md'] = $this->readme($ir, $settings, '`npm install` inside this generated package or copy `src/client.js` into your Node.js project.');
        $files['src/client.js'] = $this->client($ir, true);
        return $files;
    }

    public function snippet(array $ir, array $endpoint){
        return "import { FreedomApiClient } from './src/client.js';\n\nconst client = new FreedomApiClient({ apiKey: process.env.FREEDOMAPI_KEY });\nconst result = await client." . $this->jsMethodName($endpoint['method_name']) . "({});\nconsole.log(result.data);\n";
    }
}

class APIPlatform_SDK_Python_Generator extends APIPlatform_SDK_Abstract_Generator {
    public function language(){ return 'python'; }
    public function label(){ return 'Python'; }
    public function files(array $ir, array $settings){
        return [
            'README.md' => $this->readme($ir, $settings, '`pip install -e .` from the generated directory.'),
            'pyproject.toml' => "[project]\nname = \"" . sanitize_title($settings['package_name']) . "\"\nversion = \"0.1.0\"\ndependencies = [\"requests\"]\n",
            'freedomapi_client/__init__.py' => "from .client import FreedomApiClient\nfrom .errors import FreedomApiError\n",
            'freedomapi_client/errors.py' => "class FreedomApiError(Exception):\n    def __init__(self, message, status=None, code=None, request_id=None, body=None):\n        super().__init__(message)\n        self.status = status\n        self.code = code\n        self.request_id = request_id\n        self.body = body\n",
            'freedomapi_client/models.py' => 'MODELS = ' . var_export($ir['models'], true) . "\n",
            'freedomapi_client/client.py' => $this->client($ir),
            'examples/first_request.py' => $this->snippet($ir, $ir['endpoints'][0]),
        ];
    }
    public function snippet(array $ir, array $endpoint){
        return "import os\nfrom freedomapi_client import FreedomApiClient\n\nclient = FreedomApiClient(api_key=os.getenv(\"FREEDOMAPI_KEY\"))\nresult = client." . $this->pyMethodName($endpoint['method_name']) . "()\nprint(result[\"data\"])\n";
    }
    private function client(array $ir){
        $methods = '';
        foreach ($ir['endpoints'] as $endpoint) {
            $methods .= $this->method($endpoint);
        }
        return "import json\nimport os\nfrom urllib.parse import quote\nimport requests\nfrom .errors import FreedomApiError\n\nclass FreedomApiClient:\n    def __init__(self, api_key=None, base_url=\"" . esc_js($ir['base_url']) . "\"):\n        self.api_key = api_key or os.getenv(\"FREEDOMAPI_KEY\") or \"YOUR_APPLICATION_KEY\"\n        self.base_url = base_url.rstrip(\"/\")\n\n    def request(self, method, path, query=None, headers=None, body=None):\n        response = requests.request(method, self.base_url + path, params=query or {}, headers={\"X-API-Key\": self.api_key, \"Accept\": \"application/json\", **(headers or {})}, json=body, timeout=30)\n        try:\n            data = response.json() if response.text else None\n        except ValueError:\n            data = response.text\n        if response.status_code >= 400:\n            raise FreedomApiError((data or {}).get(\"message\", \"FreedomAPI request failed\") if isinstance(data, dict) else \"FreedomAPI request failed\", response.status_code, (data or {}).get(\"code\") if isinstance(data, dict) else None, response.headers.get(\"x-request-id\"), data)\n        return {\"status\": response.status_code, \"headers\": response.headers, \"data\": data}\n" . $methods;
    }
    private function method(array $endpoint){
        $path = $endpoint['path'];
        $replacements = '';
        foreach ($endpoint['path_parameters'] as $parameter) {
            $replacements .= "\n        path = path.replace(\"{" . esc_js($parameter['name']) . "}\", quote(str(options.get(\"" . esc_js($parameter['snake']) . "\", \"\"))))";
        }
        $body = !empty($endpoint['body_enabled']) ? "json.loads('" . str_replace("'", "\\'", $endpoint['body_json'] ?: '{}') . "')" : 'None';
        return "\n    def " . $this->pyMethodName($endpoint['method_name']) . "(self, **options):\n        path = \"" . esc_js($path) . "\"" . $replacements . "\n        query = {" . $this->pyPairs($endpoint['query_parameters']) . "}\n        headers = {" . $this->pyPairs($endpoint['header_parameters']) . "}\n        body = options.get(\"body\", " . $body . ")\n        return self.request(\"" . $endpoint['method'] . "\", path, query, headers, body)\n";
    }
    private function pyPairs(array $parameters){
        return implode(', ', array_map(function($parameter){ return '"' . $parameter['name'] . '": options.get("' . $parameter['snake'] . '")'; }, $parameters));
    }
}

class APIPlatform_SDK_PHP_Generator extends APIPlatform_SDK_Abstract_Generator {
    public function language(){ return 'php'; }
    public function label(){ return 'PHP'; }
    public function files(array $ir, array $settings){
        return [
            'README.md' => $this->readme($ir, $settings, '`composer install` from the generated directory or copy `src/Client.php`.'),
            'composer.json' => wp_json_encode(['name' => 'freedomapi/' . sanitize_title($settings['package_name']), 'autoload' => ['psr-4' => [$settings['namespace'] . '\\\\' => 'src/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'src/ApiException.php' => "<?php\nnamespace " . $settings['namespace'] . ";\n\nclass ApiException extends \\RuntimeException {\n    public int \$status;\n    public ?string \$code;\n    public mixed \$body;\n}\n",
            'src/Models.php' => "<?php\nnamespace " . $settings['namespace'] . ";\n\nfinal class Models { public const DEFINITIONS = " . var_export($ir['models'], true) . "; }\n",
            'src/Client.php' => $this->client($ir, $settings),
            'examples/first_request.php' => $this->snippet($ir, $ir['endpoints'][0]),
        ];
    }
    public function snippet(array $ir, array $endpoint){
        return "<?php\n\nuse " . self::settingsNamespace($ir) . "\\FreedomApiClient;\n\n\$client = new FreedomApiClient(getenv('FREEDOMAPI_KEY'));\n\$result = \$client->" . $endpoint['method_name'] . "();\nprint_r(\$result['data']);\n";
    }
    private static function settingsNamespace(array $ir){ return $ir['settings']['namespace'] ?? 'FreedomAPI'; }
    private function client(array $ir, array $settings){
        $methods = '';
        foreach ($ir['endpoints'] as $endpoint) {
            $methods .= $this->method($endpoint);
        }
        return "<?php\nnamespace " . $settings['namespace'] . ";\n\nclass FreedomApiClient {\n    public function __construct(private ?string \$apiKey = null, private string \$baseUrl = '" . esc_js($ir['base_url']) . "') { \$this->apiKey = \$apiKey ?: getenv('FREEDOMAPI_KEY') ?: 'YOUR_APPLICATION_KEY'; }\n    public function request(string \$method, string \$path, array \$query = [], array \$headers = [], mixed \$body = null): array {\n        \$url = rtrim(\$this->baseUrl, '/') . \$path . (\$query ? '?' . http_build_query(array_filter(\$query, fn(\$v) => \$v !== null)) : '');\n        \$ch = curl_init(\$url);\n        \$headerLines = ['X-API-Key: ' . \$this->apiKey, 'Accept: application/json'];\n        foreach (\$headers as \$k => \$v) { if (\$v !== null) \$headerLines[] = \$k . ': ' . \$v; }\n        curl_setopt_array(\$ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => \$method, CURLOPT_HTTPHEADER => \$headerLines]);\n        if (\$body !== null) curl_setopt(\$ch, CURLOPT_POSTFIELDS, is_string(\$body) ? \$body : json_encode(\$body));\n        \$raw = curl_exec(\$ch); \$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE); curl_close(\$ch);\n        \$data = \$raw !== '' ? json_decode((string) \$raw, true) : null;\n        if (\$status >= 400) throw new \\RuntimeException(is_array(\$data) && isset(\$data['message']) ? \$data['message'] : 'FreedomAPI request failed', \$status);\n        return ['status' => \$status, 'data' => \$data];\n    }\n" . $methods . "}\n";
    }
    private function method(array $endpoint){
        return "\n    public function " . $endpoint['method_name'] . "(array \$options = []): array {\n        \$path = '" . esc_js($endpoint['path']) . "';\n        foreach (\$options as \$key => \$value) { \$path = str_replace('{' . \$key . '}', rawurlencode((string) \$value), \$path); }\n        \$query = " . var_export(array_fill_keys(array_map(function($p){ return $p['name']; }, $endpoint['query_parameters']), null), true) . ";\n        foreach (\$query as \$key => \$value) { \$query[\$key] = \$options[\$key] ?? null; }\n        return \$this->request('" . $endpoint['method'] . "', \$path, \$query, [], \$options['body'] ?? " . (!empty($endpoint['body_enabled']) ? var_export(json_decode($endpoint['body_json'] ?: '{}', true), true) : 'null') . ");\n    }\n";
    }
}

class APIPlatform_SDK_CSharp_Generator extends APIPlatform_SDK_Abstract_Generator {
    public function language(){ return 'csharp'; }
    public function label(){ return 'C#'; }
    public function files(array $ir, array $settings){
        return [
            $settings['client_class'] . '.csproj' => "<Project Sdk=\"Microsoft.NET.Sdk\"><PropertyGroup><TargetFramework>net8.0</TargetFramework><Nullable>enable</Nullable></PropertyGroup></Project>\n",
            'README.md' => $this->readme($ir, $settings, '`dotnet build` from the generated directory.'),
            'Client/FreedomApiError.cs' => "namespace " . $settings['namespace'] . ";\npublic class FreedomApiError : Exception { public int Status { get; init; } public string? Code { get; init; } public string? RequestId { get; init; } public string? Body { get; init; } public FreedomApiError(string message) : base(message) {} }\n",
            'Client/FreedomApiClient.cs' => $this->client($ir, $settings),
            'Models/Models.cs' => "namespace " . $settings['namespace'] . ";\npublic static class Models { public const string Definitions = @\"" . str_replace('"', '""', wp_json_encode($ir['models'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\"; }\n",
            'Examples/FirstRequest.cs' => $this->snippet($ir, $ir['endpoints'][0]),
        ];
    }
    public function snippet(array $ir, array $endpoint){
        return "using " . ($ir['settings']['namespace'] ?? 'FreedomAPI') . ";\n\nvar client = new FreedomApiClient(Environment.GetEnvironmentVariable(\"FREEDOMAPI_KEY\"));\nvar result = await client." . $this->csMethodName($endpoint['method_name']) . "();\nConsole.WriteLine(result);\n";
    }
    private function client(array $ir, array $settings){
        $methods = '';
        foreach ($ir['endpoints'] as $endpoint) {
            $methods .= $this->method($endpoint);
        }
        return "using System.Collections.Generic;\nusing System.Linq;\nusing System.Net.Http.Headers;\nnamespace " . $settings['namespace'] . ";\npublic class FreedomApiClient {\n    private readonly HttpClient _http;\n    public FreedomApiClient(string? apiKey = null, string baseUrl = \"" . esc_js($ir['base_url']) . "\") { _http = new HttpClient { BaseAddress = new Uri(baseUrl.TrimEnd('/') + \"/\") }; _http.DefaultRequestHeaders.Add(\"X-API-Key\", apiKey ?? Environment.GetEnvironmentVariable(\"FREEDOMAPI_KEY\") ?? \"YOUR_APPLICATION_KEY\"); _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue(\"application/json\")); }\n    public async Task<string> RequestAsync(HttpMethod method, string path, Dictionary<string, string?>? query = null) { var cleanQuery = query == null ? \"\" : string.Join(\"&\", query.Where(kv => kv.Value != null).Select(kv => $\"{Uri.EscapeDataString(kv.Key)}={Uri.EscapeDataString(kv.Value!)}\")); var requestPath = path.TrimStart('/') + (cleanQuery == \"\" ? \"\" : \"?\" + cleanQuery); var response = await _http.SendAsync(new HttpRequestMessage(method, requestPath)); var body = await response.Content.ReadAsStringAsync(); if (!response.IsSuccessStatusCode) throw new FreedomApiError(\"FreedomAPI request failed\") { Status = (int) response.StatusCode, Body = body }; return body; }\n" . $methods . "}\n";
    }
    private function method(array $endpoint){
        $path = esc_js($endpoint['path']);
        $replacements = '';
        foreach ($endpoint['path_parameters'] as $parameter) {
            $replacements .= "\n        path = path.Replace(\"{" . esc_js($parameter['name']) . "}\", Uri.EscapeDataString(options.GetValueOrDefault(\"" . esc_js($parameter['name']) . "\") ?? \"\"));";
        }
        $query = [];
        foreach ($endpoint['query_parameters'] as $parameter) {
            $query[] = '["' . esc_js($parameter['name']) . '"] = options.GetValueOrDefault("' . esc_js($parameter['name']) . '")';
        }
        return "\n    public Task<string> " . $this->csMethodName($endpoint['method_name']) . "(Dictionary<string, string?>? options = null) {\n        options ??= new Dictionary<string, string?>();\n        var path = \"" . $path . "\";" . $replacements . "\n        var query = new Dictionary<string, string?> { " . implode(', ', $query) . " };\n        return RequestAsync(HttpMethod." . ucfirst(strtolower($endpoint['method'])) . ", path, query);\n    }\n";
    }
}
