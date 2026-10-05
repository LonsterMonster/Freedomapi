<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Lifecycle_Policy {

    const META_STATUS = 'apiplatform_lifecycle_status';
    const META_CHANGED_AT = 'apiplatform_lifecycle_changed_at';
    const META_DEPRECATED_AT = 'apiplatform_portal_deprecated_at';
    const META_DEPRECATION_NOTICE = 'apiplatform_portal_deprecation_notice';
    const META_SUNSET_DATE = 'apiplatform_portal_sunset_date';
    const META_REPLACEMENT_VERSION = 'apiplatform_portal_replacement_version';
    const META_MIGRATION_URL = 'apiplatform_portal_migration_url';
    const META_MIGRATION_MESSAGE = 'apiplatform_portal_migration_message';

    public static function states(){
        $states = [
            'draft' => 'Draft',
            'private' => 'Private',
            'testing' => 'Testing',
            'ready' => 'Ready',
            'public' => 'Public',
            'deprecated' => 'Deprecated',
            'archived' => 'Archived',
        ];

        if (class_exists('APIPlatform_API_Status')) {
            $states = array_intersect_key($states, array_flip(APIPlatform_API_Status::lifecycle_states()));
        }

        return $states;
    }

    public static function allowed_transitions(){
        return [
            'draft' => ['private', 'testing', 'archived'],
            'private' => ['testing', 'ready', 'archived'],
            'testing' => ['private', 'ready', 'archived'],
            'ready' => ['public', 'testing', 'archived'],
            'public' => ['deprecated', 'private', 'archived'],
            'deprecated' => ['public', 'archived'],
            'archived' => ['private'],
        ];
    }

    public static function get_state($api_id){
        $api_id = absint($api_id);
        $raw_state = get_post_meta($api_id, self::META_STATUS, true);
        $state = trim((string) $raw_state) !== ''
            ? (class_exists('APIPlatform_API_Status')
                ? APIPlatform_API_Status::normalize_lifecycle($raw_state)
                : sanitize_key($raw_state))
            : '';

        if (isset(self::states()[$state])) {
            return $state;
        }

        return self::infer_state($api_id);
    }

    public static function ensure_state($api_id, $user_id = 0){
        $raw_state = get_post_meta($api_id, self::META_STATUS, true);
        $state = trim((string) $raw_state) !== ''
            ? (class_exists('APIPlatform_API_Status')
                ? APIPlatform_API_Status::normalize_lifecycle($raw_state)
                : sanitize_key($raw_state))
            : '';

        if (isset(self::states()[$state])) {
            return $state;
        }

        $state = self::infer_state($api_id);
        self::persist_state($api_id, $state, $user_id, 'lifecycle initialized');

        return $state;
    }

    public static function infer_state($api_id){
        $status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active')
            : sanitize_key(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active');
        $visibility = self::visibility($api_id);
        $version_status = sanitize_key(get_post_meta($api_id, 'apiplatform_portal_version_status', true));

        if ($status === 'archived') {
            return 'archived';
        }

        if ($version_status === 'deprecated') {
            return 'deprecated';
        }

        if ($visibility === 'public' && $status === 'active') {
            return 'public';
        }

        if ($visibility === 'unlisted' && $status === 'active') {
            return 'testing';
        }

        if ($status === 'inactive') {
            return 'private';
        }

        return 'private';
    }

    public static function label($state){
        $state = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($state)
            : sanitize_key($state);
        $states = self::states();
        return $states[$state] ?? 'Private';
    }

    public static function transition($api_id, $to, $user_id, array $args = []){
        $api_id = absint($api_id);
        $user_id = absint($user_id);
        $post = get_post($api_id);

        if (!$post || $post->post_type !== 'user_api') {
            return ['ok' => false, 'message' => 'API not found.'];
        }

        $can_manage_lifecycle = class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($user_id, $api_id, 'apis.publish')
            : ((int) $post->post_author === $user_id || current_user_can('manage_options'));

        if (!$can_manage_lifecycle) {
            return ['ok' => false, 'message' => 'You cannot change that API lifecycle.'];
        }

        $from = self::ensure_state($api_id, $user_id);
        $submitted_to = $to;
        $to = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($to)
            : sanitize_key($to);

        if (!isset(self::states()[$to])) {
            return ['ok' => false, 'message' => 'Choose a valid lifecycle state.'];
        }

        if ($from === $to) {
            $previous_visibility = self::visibility($api_id);
            $visibility = self::apply_visibility($api_id, $to, $previous_visibility);
            self::sync_runtime_status($api_id, $to);
            self::sync_version_status($api_id, $to);
            self::log_lifecycle_save($api_id, $submitted_to, $to);

            return [
                'ok' => true,
                'message' => self::transition_message($from, $to, $previous_visibility, $visibility),
                'from' => $from,
                'to' => $to,
                'visibility_changed' => $previous_visibility !== $visibility,
            ];
        }

        $allowed = self::allowed_transitions();
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            return ['ok' => false, 'message' => 'Cannot move lifecycle from ' . self::label($from) . ' to ' . self::label($to) . ' directly.'];
        }

        $validation = self::validate_transition($api_id, $to, $args);
        if (!$validation['ok']) {
            return $validation;
        }

        $previous_visibility = self::visibility($api_id);
        self::persist_deprecation_metadata($api_id, $to, $args);
        self::persist_state($api_id, $to, $user_id, 'lifecycle changed', $from);
        $visibility = self::apply_visibility($api_id, $to, $previous_visibility);
        self::sync_runtime_status($api_id, $to);
        self::sync_version_status($api_id, $to);
        self::log_lifecycle_save($api_id, $submitted_to, $to);

        return [
            'ok' => true,
            'message' => self::transition_message($from, $to, $previous_visibility, $visibility),
            'from' => $from,
            'to' => $to,
            'visibility_changed' => $previous_visibility !== $visibility,
        ];
    }

    public static function readiness($api_id, array $options = []){
        $api_id = absint($api_id);
        $require_public_visibility = array_key_exists('require_public_visibility', $options)
            ? (bool) $options['require_public_visibility']
            : true;
        $post = get_post($api_id);
        $settings = class_exists('APIPlatform_Developer_Portal_Query')
            ? APIPlatform_Developer_Portal_Query::get_settings($api_id)
            : [];
        $schema = class_exists('APIPlatform_Endpoint_Schema_Service')
            ? APIPlatform_Endpoint_Schema_Service::current_schema($api_id)
            : [];
        $endpoints = is_array($schema['endpoints'] ?? null) ? $schema['endpoints'] : [];
        $active_endpoints = array_values(array_filter($endpoints, function($endpoint){
            return is_array($endpoint)
                && sanitize_key($endpoint['status'] ?? 'active') === 'active'
                && trim((string) ($endpoint['path'] ?? '')) !== '';
        }));
        $runtime_type = sanitize_key(get_post_meta($api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal');
        $runtime_supported = in_array($runtime_type, ['internal', 'rest_proxy', 'webhook_proxy', 'external_http'], true);
        $proxy_url = trim((string) get_post_meta($api_id, 'apiplatform_gateway_proxy_url', true));
        $runtime_callback = class_exists('APIPlatform_Gateway') && APIPlatform_Gateway::runtime_callback($runtime_type);
        $runtime_ready = $runtime_supported && ($runtime_type === 'internal' || $proxy_url !== '' || $runtime_callback);
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? 'private')
            : sanitize_key($settings['visibility'] ?? 'private');
        $status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active')
            : sanitize_key(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active');
        $lifecycle = self::get_state($api_id);

        $checks = [
            self::readiness_check('valid_title', 'Add a public title', 'The API needs a public title before it can be listed.', trim((string) ($settings['title'] ?? '')) !== ''),
            self::readiness_check('valid_runtime_slug', 'Use a valid runtime slug', 'The API post slug is required for gateway routing.', $post && trim((string) $post->post_name) !== ''),
            self::readiness_check('valid_portal_slug', 'Use a valid Developer Portal slug', 'The public Developer Portal URL needs a unique, non-reserved slug.', self::portal_slug_ready($api_id, $settings)),
            self::readiness_check('api_enabled', 'Enable the API', 'Disabled or archived APIs cannot be launched publicly.', $status === 'active'),
            self::readiness_check('published_version', 'Set a public version label', 'The API needs a current published version label.', trim((string) ($settings['version_label'] ?? '')) !== ''),
            self::readiness_check('active_endpoint', 'Add at least one active endpoint', 'The canonical schema or legacy API metadata must provide at least one active endpoint path.', !empty($active_endpoints)),
            self::readiness_check('authentication_configured', 'Configure authentication', 'Choose a supported public authentication type.', !empty($settings['authentication_type'])),
            self::readiness_check('runtime_configured', 'Configure runtime execution', 'Internal runtimes can use stored responses; proxy runtimes need a target URL.', $runtime_ready),
        ];

        if ($require_public_visibility) {
            $checks[] = self::readiness_check('public_visibility', 'Set Developer Portal visibility to Public', 'Only Public APIs appear in the directory. Unlisted APIs remain direct-link only.', $visibility === 'public');
            $checks[] = self::readiness_check('published_lifecycle', 'Publish the API lifecycle', 'The API lifecycle must be Public or Deprecated before it appears in the directory.', in_array($lifecycle, ['public', 'deprecated'], true));
        }
        $missing = [];

        foreach ($checks as $check) {
            if (empty($check['ready'])) {
                $missing[] = $check;
            }
        }

        self::log_readiness_values($api_id, $settings, $checks, $visibility, $lifecycle, $status);

        return [
            'ready' => empty($missing),
            'checks' => $checks,
            'missing' => $missing,
            'missing_labels' => wp_list_pluck($missing, 'label'),
        ];
    }

    private static function readiness_check($code, $label, $message, $ready, $fix_url = ''){
        return [
            'code' => sanitize_key($code),
            'label' => sanitize_text_field($label),
            'message' => sanitize_text_field($message),
            'fix_url' => esc_url_raw($fix_url),
            'ready' => (bool) $ready,
        ];
    }

    private static function log_readiness_values($api_id, array $settings, array $checks, $visibility, $lifecycle, $status){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $results = [];
        foreach ($checks as $check) {
            $results[] = sanitize_key($check['code'] ?? '') . '=' . (!empty($check['ready']) ? 'pass' : 'fail');
        }

        error_log('[FreedomAPI readiness read] api_id=' . absint($api_id) . ' raw_visibility=' . sanitize_key(get_post_meta($api_id, 'apiplatform_portal_visibility', true)) . ' normalized_visibility=' . sanitize_key($visibility) . ' raw_lifecycle=' . sanitize_key(get_post_meta($api_id, self::META_STATUS, true)) . ' normalized_lifecycle=' . sanitize_key($lifecycle) . ' raw_status=' . sanitize_key(get_post_meta($api_id, 'apiplatform_status', true)) . ' normalized_status=' . sanitize_key($status) . ' settings_visibility=' . sanitize_key($settings['visibility'] ?? '') . ' checks=' . implode(',', array_filter($results)));
    }

    private static function log_lifecycle_save($api_id, $submitted, $normalized){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[FreedomAPI lifecycle save] api_id=' . absint($api_id) . ' submitted_lifecycle=' . sanitize_key($submitted) . ' normalized_lifecycle=' . sanitize_key($normalized) . ' saved_lifecycle=' . sanitize_key(get_post_meta($api_id, self::META_STATUS, true)) . ' saved_visibility=' . sanitize_key(get_post_meta($api_id, 'apiplatform_portal_visibility', true)));
    }

    private static function portal_slug_ready($api_id, array $settings){
        $slug = sanitize_title($settings['portal_slug'] ?? '');

        if ($slug === '' || !class_exists('APIPlatform_Developer_Portal_Query')) {
            return false;
        }

        return !APIPlatform_Developer_Portal_Query::portal_slug_is_reserved($slug)
            && APIPlatform_Developer_Portal_Query::portal_slug_is_unique($api_id, $slug);
    }

    public static function runtime_access($api_id){
        $state = self::get_state($api_id);
        $status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active')
            : sanitize_key(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active');

        if ($state === 'draft') {
            return ['allowed' => false, 'code' => 'api_draft', 'message' => 'API is still in draft.', 'status' => 403];
        }

        if ($state === 'archived') {
            return ['allowed' => false, 'code' => 'api_archived', 'message' => 'API is archived.', 'status' => 403];
        }

        if ($status !== 'active') {
            return ['allowed' => false, 'code' => 'api_disabled', 'message' => 'API is disabled.', 'status' => 403];
        }

        return ['allowed' => true, 'state' => $state, 'deprecated' => $state === 'deprecated'];
    }

    public static function public_route_allowed(array $card, $route = 'api'){
        $state = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($card['lifecycle'] ?? self::get_state($card['id'] ?? 0))
            : sanitize_key($card['lifecycle'] ?? self::get_state($card['id'] ?? 0));
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($card['visibility'] ?? 'private')
            : sanitize_key($card['visibility'] ?? 'private');

        if ($route === 'directory' || $route === 'related') {
            $readiness = self::readiness($card['id'] ?? 0);
            $status = $card['status'] ?? get_post_meta(absint($card['id'] ?? 0), 'apiplatform_status', true) ?: 'active';
            $allowed = class_exists('APIPlatform_API_Status')
                ? APIPlatform_API_Status::is_publicly_listable($visibility, $state, $status) && !empty($readiness['ready'])
                : ($visibility === 'public' && in_array($state, ['public', 'deprecated'], true) && !empty($readiness['ready']));
            self::log_public_eligibility($card, $route, $readiness, $allowed);
            return $allowed;
        }

        if ($state === 'archived' || $state === 'draft' || $visibility === 'private') {
            return false;
        }

        if ($route === 'test' && empty($card['allow_public_tester'])) {
            return false;
        }

        return in_array($state, ['testing', 'ready', 'public', 'deprecated'], true);
    }

    private static function log_public_eligibility(array $card, $route, array $readiness, $allowed){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status($card['status'] ?? get_post_meta(absint($card['id'] ?? 0), 'apiplatform_status', true) ?: 'active')
            : sanitize_key($card['status'] ?? get_post_meta(absint($card['id'] ?? 0), 'apiplatform_status', true) ?: 'active');
        $state = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($card['lifecycle'] ?? self::get_state($card['id'] ?? 0))
            : sanitize_key($card['lifecycle'] ?? self::get_state($card['id'] ?? 0));
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($card['visibility'] ?? 'private')
            : sanitize_key($card['visibility'] ?? 'private');
        $visibility_passed = $visibility === 'public' ? 'yes' : 'no';
        $enabled_passed = $status === 'active' ? 'yes' : 'no';
        $publication_passed = in_array($state, ['public', 'deprecated'], true) ? 'yes' : 'no';
        $readiness_passed = !empty($readiness['ready']) ? 'yes' : 'no';
        $failure_codes = array_map(function($item){
            return is_array($item) ? sanitize_key($item['code'] ?? '') : sanitize_key((string) $item);
        }, $readiness['missing'] ?? []);

        error_log('[FreedomAPI readiness] route=' . sanitize_key($route) . ' api_id=' . absint($card['id'] ?? 0) . ' visibility=' . $visibility . ' visibility_passed=' . $visibility_passed . ' status=' . $status . ' enabled_passed=' . $enabled_passed . ' lifecycle=' . $state . ' publication_passed=' . $publication_passed . ' readiness_passed=' . $readiness_passed . ' failures=' . implode(',', array_filter($failure_codes)) . ' final_eligible=' . ($allowed ? 'yes' : 'no'));
    }

    public static function last_event($api_id){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_lifecycle_events';

        if (!self::table_exists($table)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT previous_state, new_state, created_at FROM `{$table}` WHERE api_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
            absint($api_id)
        ), ARRAY_A);
    }

    public static function recent_events($api_id, $limit = 10){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_lifecycle_events';

        if (!self::table_exists($table)) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT previous_state, new_state, event_type, created_at FROM `{$table}` WHERE api_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
            absint($api_id),
            absint($limit)
        ), ARRAY_A);
    }

    private static function validate_transition($api_id, $to, array $args){
        if (in_array($to, ['ready', 'public'], true)) {
            $readiness = self::readiness($api_id, ['require_public_visibility' => false]);
            if (!$readiness['ready']) {
                return ['ok' => false, 'message' => 'Complete these readiness checks first: ' . implode(', ', $readiness['missing_labels'] ?? []) . '.'];
            }
        }

        if ($to === 'deprecated') {
            $notice = trim((string) ($args['deprecation_notice'] ?? get_post_meta($api_id, self::META_DEPRECATION_NOTICE, true)));
            if ($notice === '') {
                return ['ok' => false, 'message' => 'Add a deprecation notice before marking this API deprecated.'];
            }
        }

        return ['ok' => true];
    }

    private static function persist_state($api_id, $state, $user_id, $event_type, $previous = ''){
        update_post_meta($api_id, self::META_STATUS, $state);
        update_post_meta($api_id, self::META_CHANGED_AT, current_time('mysql'));
        self::invalidate_readiness_cache($api_id, 'lifecycle_status_changed');
        self::log_event($api_id, $user_id, $previous, $state, $event_type);
    }

    private static function persist_deprecation_metadata($api_id, $state, array $args){
        foreach ([
            self::META_DEPRECATION_NOTICE => 'deprecation_notice',
            self::META_SUNSET_DATE => 'sunset_date',
            self::META_REPLACEMENT_VERSION => 'replacement_version',
            self::META_MIGRATION_URL => 'migration_url',
            self::META_MIGRATION_MESSAGE => 'migration_message',
        ] as $meta_key => $arg_key) {
            if (!array_key_exists($arg_key, $args)) {
                continue;
            }

            $value = $arg_key === 'migration_url' ? esc_url_raw($args[$arg_key]) : sanitize_text_field($args[$arg_key]);
            if ($arg_key === 'deprecation_notice' || $arg_key === 'migration_message') {
                $value = sanitize_textarea_field($args[$arg_key]);
            }
            update_post_meta($api_id, $meta_key, $value);
        }

        if ($state === 'deprecated' && !get_post_meta($api_id, self::META_DEPRECATED_AT, true)) {
            update_post_meta($api_id, self::META_DEPRECATED_AT, current_time('mysql'));
        }
    }

    private static function apply_visibility($api_id, $state, $current){
        $visibility = $current;

        if (in_array($state, ['draft', 'private', 'archived'], true)) {
            $visibility = 'private';
        } elseif ($state === 'testing' && $current === 'public') {
            $visibility = 'unlisted';
        } elseif ($state === 'ready' && $current === 'public') {
            $visibility = 'unlisted';
        } elseif ($state === 'public') {
            $visibility = 'public';
        } elseif ($state === 'deprecated' && !in_array($current, ['public', 'unlisted'], true)) {
            $visibility = 'unlisted';
        }

        if ($visibility !== $current) {
            self::update_visibility($api_id, $visibility);
        }

        return $visibility;
    }

    private static function sync_runtime_status($api_id, $state){
        if ($state === 'archived') {
            update_post_meta($api_id, 'apiplatform_status', 'archived');
        } elseif ($state === 'draft') {
            update_post_meta($api_id, 'apiplatform_status', 'inactive');
        } else {
            update_post_meta($api_id, 'apiplatform_status', 'active');
        }
    }

    private static function sync_version_status($api_id, $state){
        if ($state === 'deprecated') {
            update_post_meta($api_id, 'apiplatform_portal_version_status', 'deprecated');
        } elseif (get_post_meta($api_id, 'apiplatform_portal_version_status', true) === 'deprecated') {
            update_post_meta($api_id, 'apiplatform_portal_version_status', 'current');
        }
    }

    private static function visibility($api_id){
        $visibility = get_post_meta($api_id, 'apiplatform_portal_visibility', true);
        return class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($visibility)
            : (in_array(sanitize_key($visibility), ['private', 'unlisted', 'public'], true) ? sanitize_key($visibility) : 'private');
    }

    private static function update_visibility($api_id, $visibility){
        update_post_meta($api_id, 'apiplatform_portal_visibility', $visibility);
        update_post_meta($api_id, 'apiplatform_portal_updated_at', current_time('mysql'));
        self::invalidate_readiness_cache($api_id, 'portal_visibility_changed');

        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $settings = APIPlatform_Developer_Portal_Query::get_settings($api_id);
            $settings['visibility'] = $visibility;
            $settings['updated_at'] = current_time('mysql');
            APIPlatform_Developer_Portal_Query::sync_registry($api_id, $settings);
        }
    }

    private static function transition_message($from, $to, $previous_visibility, $visibility){
        $message = $from === $to
            ? 'Lifecycle is already ' . self::label($to) . '.'
            : 'Lifecycle changed from ' . self::label($from) . ' to ' . self::label($to) . '.';

        if ($previous_visibility !== $visibility) {
            $message .= ' Visibility changed from ' . ucfirst($previous_visibility) . ' to ' . ucfirst($visibility) . ' to keep lifecycle and discovery compatible.';
        }

        return $message;
    }

    public static function invalidate_readiness_cache($api_id, $reason = ''){
        $api_id = absint($api_id);
        if (!$api_id) {
            return;
        }

        clean_post_cache($api_id);
        wp_cache_delete($api_id, 'post_meta');
        do_action('apiplatform_publication_readiness_invalidated', $api_id, sanitize_key($reason));
    }

    private static function log_event($api_id, $user_id, $previous, $new, $event_type){
        global $wpdb;
        $table = $wpdb->prefix . 'apiplatform_lifecycle_events';

        if (!self::table_exists($table)) {
            return false;
        }

        return (bool) $wpdb->insert($table, [
            'api_id' => absint($api_id),
            'user_id' => absint($user_id),
            'previous_state' => sanitize_key($previous),
            'new_state' => sanitize_key($new),
            'event_type' => sanitize_key($event_type),
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%s']);
    }

    private static function table_exists($table){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

function apiplatform_lifecycle_state($api_id){
    return APIPlatform_Lifecycle_Policy::get_state($api_id);
}

function apiplatform_invalidate_publication_readiness_on_meta_change($meta_id, $object_id, $meta_key, $_meta_value){
    $watched = [
        'apiplatform_portal_visibility',
        'apiplatform_status',
        'apiplatform_lifecycle_status',
        'apiplatform_portal_title',
        'apiplatform_portal_slug',
        'apiplatform_portal_version_label',
        'apiplatform_portal_docs_status',
        'apiplatform_docs_published_at',
        'apiplatform_endpoint_schema_versions',
        'apiplatform_data_model_versions',
        'apiplatform_gateway_runtime_type',
        'apiplatform_gateway_proxy_url',
        'apiplatform_portal_authentication_type',
        'api_json',
        'apiplatform_docs_success_response',
    ];

    if (!in_array((string) $meta_key, $watched, true)) {
        return;
    }

    if (get_post_type($object_id) !== 'user_api') {
        return;
    }

    APIPlatform_Lifecycle_Policy::invalidate_readiness_cache($object_id, 'meta_' . sanitize_key($meta_key));
}

add_action('added_post_meta', 'apiplatform_invalidate_publication_readiness_on_meta_change', 10, 4);
add_action('updated_post_meta', 'apiplatform_invalidate_publication_readiness_on_meta_change', 10, 4);
add_action('deleted_post_meta', 'apiplatform_invalidate_publication_readiness_on_meta_change', 10, 4);
