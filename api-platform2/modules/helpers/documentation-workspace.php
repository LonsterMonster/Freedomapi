<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Documentation_Workspace {

    public static function draft_meta($api_id){
        $parameters = get_post_meta($api_id, 'api_parameters', true);
        $headers = get_post_meta($api_id, 'apiplatform_docs_headers', true);
        $errors = get_post_meta($api_id, 'apiplatform_docs_errors', true);

        return [
            'overview' => get_post_meta($api_id, 'apiplatform_docs_overview', true),
            'authentication' => get_post_meta($api_id, 'apiplatform_docs_authentication', true),
            'endpoint_description' => get_post_meta($api_id, 'apiplatform_docs_endpoint_description', true),
            'methods' => self::sanitize_methods(get_post_meta($api_id, 'apiplatform_docs_methods', true) ?: 'GET'),
            'parameters_json' => wp_json_encode(is_array($parameters) ? $parameters : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'headers_json' => wp_json_encode(is_array($headers) ? $headers : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'request_body' => get_post_meta($api_id, 'api_request_body_example', true) ?: "{}",
            'success_response' => get_post_meta($api_id, 'apiplatform_docs_success_response', true) ?: (get_post_meta($api_id, 'api_json', true) ?: "{}"),
            'errors_json' => wp_json_encode(is_array($errors) ? $errors : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'rate_limit' => get_post_meta($api_id, 'apiplatform_docs_rate_limit', true),
            'sdk_examples' => get_post_meta($api_id, 'apiplatform_docs_sdk_examples', true),
            'faq' => get_post_meta($api_id, 'apiplatform_docs_faq', true),
            'migration_guide' => get_post_meta($api_id, 'apiplatform_docs_migration_guide', true),
        ];
    }

    public static function save_draft($api_id, $user_id, array $data){
        $api_id = absint($api_id);
        if (!self::can_manage($api_id, $user_id)) {
            return new WP_Error('api_permission_denied', 'You cannot edit documentation for that API.');
        }
        update_post_meta($api_id, 'apiplatform_docs_overview', sanitize_textarea_field($data['overview'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_authentication', sanitize_textarea_field($data['authentication'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_endpoint_description', sanitize_textarea_field($data['endpoint_description'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_methods', self::sanitize_methods($data['methods'] ?? 'GET'));
        update_post_meta($api_id, 'api_parameters', self::json_value($data['parameters_json'] ?? '', []));
        update_post_meta($api_id, 'apiplatform_docs_headers', self::json_value($data['headers_json'] ?? '', []));
        update_post_meta($api_id, 'api_request_body_example', trim((string) ($data['request_body'] ?? '')));
        update_post_meta($api_id, 'apiplatform_docs_success_response', trim((string) ($data['success_response'] ?? '')));
        update_post_meta($api_id, 'apiplatform_docs_errors', self::json_value($data['errors_json'] ?? '', []));
        update_post_meta($api_id, 'apiplatform_docs_rate_limit', sanitize_textarea_field($data['rate_limit'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_sdk_examples', sanitize_textarea_field($data['sdk_examples'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_faq', sanitize_textarea_field($data['faq'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_migration_guide', sanitize_textarea_field($data['migration_guide'] ?? ''));
        update_post_meta($api_id, 'apiplatform_docs_draft_updated_at', current_time('mysql'));
        update_post_meta($api_id, 'apiplatform_docs_last_editor_user_id', absint($user_id));
        $has_snapshot = self::current_snapshot($api_id) ? true : false;
        update_post_meta($api_id, 'apiplatform_docs_state', $has_snapshot ? 'outdated' : 'draft');
        update_post_meta($api_id, 'apiplatform_docs_has_unpublished_changes', $has_snapshot ? 1 : 0);

        if (!$has_snapshot) {
            update_post_meta($api_id, 'apiplatform_portal_docs_status', 'draft');
        }

        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $settings = APIPlatform_Developer_Portal_Query::get_settings($api_id);
            $settings['docs_status'] = $has_snapshot ? 'ready' : 'draft';
            $settings['updated_at'] = current_time('mysql');
            APIPlatform_Developer_Portal_Query::sync_registry($api_id, $settings);
        }

        self::record_event($api_id, $user_id, 'draft_saved', '', 0, 'Documentation draft saved.');
    }

    public static function publish($api, $user_id){
        if (!$api || !self::can_manage($api->ID, $user_id)) {
            return ['ok' => false, 'code' => 'api_permission_denied', 'message' => 'You cannot publish documentation for that API.'];
        }
        $validation = self::validate($api->ID);
        if (!$validation['ok']) {
            return [
                'ok' => false,
                'message' => 'Documentation was not published. Complete these required sections: ' . implode(', ', $validation['missing']) . '.',
            ];
        }

        $version = self::version_label($api->ID);
        $canonical = class_exists('APIPlatform_Frontend_API_Documentation')
            ? APIPlatform_Frontend_API_Documentation::documentation_data_for_api($api, absint($user_id))
            : [];
        $extra = self::draft_meta($api->ID);
        $snapshot = [
            'api_id' => (int) $api->ID,
            'api_title' => get_the_title($api),
            'runtime_slug' => $api->post_name,
            'version_label' => $version,
            'published_at' => current_time('mysql'),
            'documentation' => $canonical,
            'workspace' => [
                'overview_markdown' => $extra['overview'],
                'authentication_markdown' => $extra['authentication'],
                'sdk_examples_markdown' => $extra['sdk_examples'],
                'faq_markdown' => $extra['faq'],
                'migration_guide_markdown' => $extra['migration_guide'],
            ],
            'meta' => [
                'docs_state' => 'published',
                'version_status' => get_post_meta($api->ID, 'apiplatform_portal_version_status', true) ?: 'current',
                'deprecation_notice' => get_post_meta($api->ID, 'apiplatform_portal_deprecation_notice', true),
                'sunset_date' => get_post_meta($api->ID, 'apiplatform_portal_sunset_date', true),
                'replacement_version' => get_post_meta($api->ID, 'apiplatform_portal_replacement_version', true),
            ],
        ];

        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_snapshots';
        $previous = self::current_snapshot($api->ID);

        $wpdb->update($table, ['documentation_state' => 'outdated'], ['api_id' => absint($api->ID), 'documentation_state' => 'published']);
        $wpdb->insert($table, [
            'api_id' => absint($api->ID),
            'version_label' => $version,
            'snapshot_uid' => self::snapshot_uid($api->ID, $version),
            'documentation_state' => 'published',
            'snapshot_data' => wp_json_encode($snapshot),
            'published_by_user_id' => absint($user_id),
            'published_at' => current_time('mysql'),
            'created_at' => current_time('mysql'),
        ]);

        $snapshot_id = (int) $wpdb->insert_id;
        update_post_meta($api->ID, 'apiplatform_portal_docs_status', 'ready');
        update_post_meta($api->ID, 'apiplatform_docs_state', 'published');
        update_post_meta($api->ID, 'apiplatform_docs_published_at', current_time('mysql'));
        update_post_meta($api->ID, 'apiplatform_docs_published_snapshot_id', $snapshot_id);
        update_post_meta($api->ID, 'apiplatform_docs_has_unpublished_changes', 0);

        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $settings = APIPlatform_Developer_Portal_Query::get_settings($api->ID);
            $settings['docs_status'] = 'ready';
            $settings['updated_at'] = current_time('mysql');
            $settings['published_at'] = $settings['published_at'] ?: current_time('mysql');
            APIPlatform_Developer_Portal_Query::sync_registry($api->ID, $settings);
        }

        self::record_event($api->ID, $user_id, $previous ? 'snapshot_replaced' : 'snapshot_published', $version, $snapshot_id, 'Documentation snapshot published.');

        return ['ok' => true, 'message' => 'Documentation published for ' . $version . '.'];
    }

    public static function save_release($api_id, $user_id, array $data){
        if (!self::can_manage($api_id, $user_id)) {
            return 'Error: You cannot manage releases for that API.';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_releases';
        $version = sanitize_text_field($data['version_label'] ?? self::version_label($api_id));
        $type = sanitize_key($data['release_type'] ?? 'changed');
        $allowed = ['added', 'changed', 'fixed', 'deprecated', 'removed', 'security', 'performance'];

        if (!in_array($type, $allowed, true)) {
            $type = 'changed';
        }

        $wpdb->insert($table, [
            'api_id' => absint($api_id),
            'version_label' => $version,
            'release_title' => sanitize_text_field($data['release_title'] ?? ''),
            'summary' => sanitize_textarea_field($data['summary'] ?? ''),
            'release_date' => self::clean_date($data['release_date'] ?? '') ?: current_time('Y-m-d'),
            'release_type' => $type,
            'breaking_change' => !empty($data['breaking_change']) ? 1 : 0,
            'migration_notes' => sanitize_textarea_field($data['migration_notes'] ?? ''),
            'entries' => sanitize_textarea_field($data['entries'] ?? ''),
            'created_by_user_id' => absint($user_id),
            'updated_by_user_id' => absint($user_id),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        self::record_event($api_id, $user_id, 'release_created', $version, 0, 'Documentation release note created.');
        return 'Changelog entry saved.';
    }

    public static function completion($api_id){
        $docs = self::draft_meta($api_id);
        $sections = [
            'Overview' => trim((string) $docs['overview']) !== '',
            'Authentication' => trim((string) $docs['authentication']) !== '',
            'Endpoint description' => trim((string) $docs['endpoint_description']) !== '',
            'Methods' => !empty($docs['methods']),
            'Parameters' => trim((string) $docs['parameters_json']) !== '' && trim((string) $docs['parameters_json']) !== '[]',
            'Response example' => trim((string) $docs['success_response']) !== '' && trim((string) $docs['success_response']) !== '{}',
            'Errors' => trim((string) $docs['errors_json']) !== '' && trim((string) $docs['errors_json']) !== '[]',
            'Rate limits' => trim((string) $docs['rate_limit']) !== '',
            'SDK examples' => trim((string) $docs['sdk_examples']) !== '',
            'FAQ' => trim((string) $docs['faq']) !== '',
            'Migration guide' => trim((string) $docs['migration_guide']) !== '',
        ];
        $complete = count(array_filter($sections));

        return [
            'sections' => $sections,
            'complete' => $complete,
            'total' => count($sections),
            'percent' => (int) round(($complete / max(1, count($sections))) * 100),
            'required_missing' => self::validate($api_id)['missing'],
        ];
    }

    public static function validate($api_id){
        $docs = self::draft_meta($api_id);
        $required = [
            'Overview' => trim((string) $docs['overview']) !== '',
            'Authentication' => trim((string) $docs['authentication']) !== '',
            'Endpoint description' => trim((string) $docs['endpoint_description']) !== '',
            'Supported methods' => !empty($docs['methods']),
            'Success response example' => trim((string) $docs['success_response']) !== '' && trim((string) $docs['success_response']) !== '{}',
        ];
        $missing = array_keys(array_filter($required, function($ready){ return !$ready; }));
        return ['ok' => !$missing, 'missing' => $missing];
    }

    public static function current_snapshot($api_id){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_snapshots';
        if (!self::table_exists($table)) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE api_id = %d AND documentation_state = 'published' ORDER BY published_at DESC, id DESC LIMIT 1", absint($api_id)), ARRAY_A);
    }

    public static function snapshot_by_version($api_id, $version){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_snapshots';
        if (!self::table_exists($table)) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE api_id = %d AND version_label = %s AND documentation_state IN ('published','outdated') ORDER BY published_at DESC, id DESC LIMIT 1", absint($api_id), sanitize_text_field($version)), ARRAY_A);
    }

    public static function snapshots($api_id){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_snapshots';
        if (!self::table_exists($table)) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare("SELECT id, version_label, snapshot_uid, documentation_state, published_by_user_id, published_at, created_at FROM `{$table}` WHERE api_id = %d ORDER BY published_at DESC, id DESC", absint($api_id)), ARRAY_A);
    }

    public static function releases($api_id, $version = ''){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_releases';
        if (!self::table_exists($table)) {
            return [];
        }
        if ($version !== '') {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE api_id = %d AND version_label = %s ORDER BY release_date DESC, id DESC", absint($api_id), sanitize_text_field($version)), ARRAY_A);
        }
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE api_id = %d ORDER BY release_date DESC, id DESC", absint($api_id)), ARRAY_A);
    }

    public static function snapshot_data(array $snapshot){
        $data = json_decode((string) ($snapshot['snapshot_data'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    public static function version_label($api_id){
        return get_post_meta($api_id, 'apiplatform_portal_version_label', true) ?: 'v1';
    }

    public static function markdown($text){
        $text = (string) $text;
        if (trim($text) === '') {
            return '<p>Not documented</p>';
        }

        $escaped = esc_html($text);
        $escaped = preg_replace_callback('/```([a-zA-Z0-9_-]*)\n(.*?)```/s', function($matches){
            return '<pre><code>' . $matches[2] . '</code></pre>';
        }, $escaped);
        $escaped = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $escaped);
        $escaped = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $escaped);
        $escaped = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $escaped);
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
        return wpautop($escaped);
    }

    public static function record_event($api_id, $user_id, $event_type, $version_label = '', $snapshot_id = 0, $message = ''){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_documentation_events';
        if (!self::table_exists($table)) {
            return;
        }
        $wpdb->insert($table, [
            'api_id' => absint($api_id),
            'actor_user_id' => absint($user_id),
            'event_type' => sanitize_key($event_type),
            'version_label' => sanitize_text_field($version_label),
            'snapshot_id' => absint($snapshot_id),
            'message' => sanitize_text_field($message),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function sanitize_methods($value){
        $raw = is_array($value) ? $value : preg_split('/[\s,]+/', (string) wp_unslash($value));
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $methods = [];
        foreach ((array) $raw as $method) {
            $method = strtoupper(sanitize_key((string) $method));
            if (in_array($method, $allowed, true)) {
                $methods[] = $method;
            }
        }
        return array_values(array_unique($methods ?: ['GET']));
    }

    private static function can_manage($api_id, $user_id){
        return class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can(absint($user_id), absint($api_id), 'apis.manage_docs')
            : user_can(absint($user_id), 'manage_options');
    }

    private static function json_value($value, $default){
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private static function clean_date($value){
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function snapshot_uid($api_id, $version){
        return sanitize_key('doc_' . absint($api_id) . '_' . sanitize_title($version) . '_' . wp_generate_uuid4());
    }

    private static function table_exists($table){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
