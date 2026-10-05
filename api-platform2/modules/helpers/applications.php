<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Applications_Service {

    public static function environments(){
        return [
            'development' => 'Development',
            'staging' => 'Staging',
            'production' => 'Production',
            'testing' => 'Testing',
            'internal' => 'Internal',
            'other' => 'Other',
        ];
    }

    public static function statuses(){
        return [
            'active' => 'Active',
            'disabled' => 'Disabled',
            'archived' => 'Archived',
        ];
    }

    public static function access_statuses(){
        return [
            'requested' => 'Requested',
            'approved' => 'Approved',
            'denied' => 'Denied',
            'revoked' => 'Revoked',
        ];
    }

    public static function tables(){
        global $wpdb;
        return [
            'applications' => $wpdb->prefix . 'apiplatform_developer_applications',
            'keys' => $wpdb->prefix . 'apiplatform_application_keys',
            'access' => $wpdb->prefix . 'apiplatform_application_api_access',
            'events' => $wpdb->prefix . 'apiplatform_application_events',
            'logs' => $wpdb->prefix . 'apiplatform_logs',
        ];
    }

    public static function create_application($owner_user_id, array $data){
        global $wpdb;
        $tables = self::tables();
        $owner_user_id = absint($owner_user_id);
        $name = sanitize_text_field($data['name'] ?? '');

        if ($owner_user_id <= 0 || $name === '') {
            return ['ok' => false, 'message' => 'Application name is required.'];
        }

        $environment = sanitize_key($data['environment'] ?? 'development');
        if (!isset(self::environments()[$environment])) {
            $environment = 'development';
        }

        $now = current_time('mysql');
        $slug = self::unique_slug($name);
        $inserted = $wpdb->insert($tables['applications'], [
            'owner_user_id' => $owner_user_id,
            'name' => $name,
            'slug' => $slug,
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'environment' => $environment,
            'status' => 'active',
            'website_url' => self::clean_url($data['website_url'] ?? ''),
            'callback_url' => self::clean_url($data['callback_url'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if (!$inserted) {
            return ['ok' => false, 'message' => 'Application could not be created.'];
        }

        $app_id = (int) $wpdb->insert_id;
        self::record_event($app_id, $owner_user_id, 'application_created', 'Application created.');

        return ['ok' => true, 'id' => $app_id, 'message' => 'Application created.'];
    }

    public static function update_application($app_id, $owner_user_id, array $data){
        global $wpdb;
        $app = self::owned_application($app_id, $owner_user_id);

        if (!$app) {
            return ['ok' => false, 'message' => 'You cannot edit that application.'];
        }

        $name = sanitize_text_field($data['name'] ?? $app['name']);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Application name is required.'];
        }

        $environment = sanitize_key($data['environment'] ?? $app['environment']);
        if (!isset(self::environments()[$environment])) {
            $environment = $app['environment'];
        }

        $status = sanitize_key($data['status'] ?? $app['status']);
        if (!isset(self::statuses()[$status])) {
            $status = $app['status'];
        }

        if ($app['status'] === 'archived' && $status !== 'archived') {
            $status = 'disabled';
        }

        $fields = [
            'name' => $name,
            'description' => sanitize_textarea_field($data['description'] ?? $app['description']),
            'environment' => $environment,
            'status' => $status,
            'website_url' => self::clean_url($data['website_url'] ?? $app['website_url']),
            'callback_url' => self::clean_url($data['callback_url'] ?? $app['callback_url']),
            'updated_at' => current_time('mysql'),
            'archived_at' => $status === 'archived' ? ($app['archived_at'] ?: current_time('mysql')) : null,
        ];

        $updated = $wpdb->update(self::tables()['applications'], $fields, ['id' => absint($app_id)], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);

        if ($updated === false) {
            return ['ok' => false, 'message' => 'Application could not be updated.'];
        }

        self::record_event($app_id, $owner_user_id, 'application_updated', 'Application updated.');

        return ['ok' => true, 'message' => 'Application updated.'];
    }

    public static function list_applications($owner_user_id, array $filters = []){
        global $wpdb;
        $where = ['a.owner_user_id = %d'];
        $args = [absint($owner_user_id)];
        $status = sanitize_key($filters['status'] ?? '');
        $environment = sanitize_key($filters['environment'] ?? '');
        $sort = sanitize_key($filters['sort'] ?? 'updated');

        if ($status !== '' && isset(self::statuses()[$status])) {
            $where[] = 'a.status = %s';
            $args[] = $status;
        } elseif (empty($filters['include_archived'])) {
            $where[] = "a.status <> 'archived'";
        }

        if ($environment !== '' && isset(self::environments()[$environment])) {
            $where[] = 'a.environment = %s';
            $args[] = $environment;
        }

        $order = 'a.updated_at DESC, a.created_at DESC';
        if ($sort === 'used') {
            $order = 'a.last_used_at DESC, a.updated_at DESC, a.created_at DESC';
        } elseif ($sort === 'name') {
            $order = 'a.name ASC, a.updated_at DESC, a.created_at DESC';
        } elseif ($sort === 'requests') {
            $order = 'request_count DESC, a.updated_at DESC, a.created_at DESC';
        }

        $table = self::tables()['applications'];

        if ($sort === 'requests') {
            $logs = self::tables()['logs'];
            $sql = "SELECT a.*, COALESCE(l.request_count, 0) AS request_count FROM `{$table}` a LEFT JOIN (SELECT application_id, COUNT(id) AS request_count FROM `{$logs}` GROUP BY application_id) l ON l.application_id = a.id WHERE " . implode(' AND ', $where) . " ORDER BY {$order}";
            return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        }

        $sql = "SELECT a.* FROM `{$table}` a WHERE " . implode(' AND ', $where) . " ORDER BY {$order}";
        $applications = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return self::hydrate_request_counts($applications);
    }

    private static function hydrate_request_counts(array $applications){
        if (!$applications) {
            return [];
        }

        global $wpdb;
        $logs = self::tables()['logs'];
        $ids = array_values(array_filter(array_map('absint', wp_list_pluck($applications, 'id'))));

        if (!$ids) {
            return $applications;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT application_id, COUNT(id) AS request_count FROM `{$logs}` WHERE application_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") GROUP BY application_id",
            $ids
        ), ARRAY_A);
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['application_id']] = (int) $row['request_count'];
        }

        foreach ($applications as $index => $application) {
            $applications[$index]['request_count'] = $counts[(int) ($application['id'] ?? 0)] ?? 0;
        }

        return $applications;
    }

    public static function owned_application($app_id, $owner_user_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `" . self::tables()['applications'] . "` WHERE id = %d AND owner_user_id = %d LIMIT 1",
            absint($app_id),
            absint($owner_user_id)
        ), ARRAY_A);

        return $row ?: null;
    }

    public static function get_application($app_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `" . self::tables()['applications'] . "` WHERE id = %d LIMIT 1", absint($app_id)), ARRAY_A);
        return $row ?: null;
    }

    public static function create_key($app_id, $owner_user_id, $name = 'Application Key'){
        global $wpdb;
        $app = self::owned_application($app_id, $owner_user_id);

        if (!$app || $app['status'] === 'archived') {
            return ['ok' => false, 'message' => 'You cannot create keys for that application.'];
        }

        $limit = (int) apply_filters('apiplatform_application_key_limit', 10, $app);
        if (count(self::application_keys($app_id, $owner_user_id, true)) >= $limit) {
            return ['ok' => false, 'message' => 'Application key limit reached.'];
        }

        $credential = self::new_credential();
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::tables()['keys'], [
            'application_id' => absint($app_id),
            'owner_user_id' => absint($owner_user_id),
            'key_name' => sanitize_text_field($name ?: 'Application Key'),
            'key_prefix' => $credential['lookup'],
            'key_hash' => password_hash($credential['plaintext'], PASSWORD_DEFAULT),
            'masked_key' => self::mask_key($credential['plaintext']),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if (!$inserted) {
            return ['ok' => false, 'message' => 'Application key could not be created.'];
        }

        $key_id = (int) $wpdb->insert_id;
        self::record_event($app_id, $owner_user_id, 'key_created', 'Application key created.', ['key_id' => $key_id]);

        return ['ok' => true, 'key_id' => $key_id, 'plaintext' => $credential['plaintext'], 'message' => 'Application key created.'];
    }

    public static function regenerate_key($key_id, $owner_user_id){
        global $wpdb;
        $key = self::owned_key($key_id, $owner_user_id);

        if (!$key) {
            return ['ok' => false, 'message' => 'You cannot regenerate that key.'];
        }

        $credential = self::new_credential();
        $updated = $wpdb->update(self::tables()['keys'], [
            'key_prefix' => $credential['lookup'],
            'key_hash' => password_hash($credential['plaintext'], PASSWORD_DEFAULT),
            'masked_key' => self::mask_key($credential['plaintext']),
            'status' => 'active',
            'updated_at' => current_time('mysql'),
            'revoked_at' => null,
        ], ['id' => absint($key_id)], ['%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);

        if ($updated === false) {
            return ['ok' => false, 'message' => 'Application key could not be regenerated.'];
        }

        self::record_event($key['application_id'], $owner_user_id, 'key_regenerated', 'Application key regenerated.', ['key_id' => $key_id]);

        return ['ok' => true, 'plaintext' => $credential['plaintext'], 'message' => 'Application key regenerated.'];
    }

    public static function revoke_key($key_id, $owner_user_id){
        global $wpdb;
        $key = self::owned_key($key_id, $owner_user_id);

        if (!$key) {
            return ['ok' => false, 'message' => 'You cannot revoke that key.'];
        }

        $wpdb->update(self::tables()['keys'], [
            'status' => 'revoked',
            'revoked_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($key_id)], ['%s', '%s', '%s'], ['%d']);
        self::record_event($key['application_id'], $owner_user_id, 'key_revoked', 'Application key revoked.', ['key_id' => $key_id]);

        return ['ok' => true, 'message' => 'Application key revoked.'];
    }

    public static function application_keys($app_id, $owner_user_id, $include_revoked = false){
        global $wpdb;
        $app = self::owned_application($app_id, $owner_user_id);

        if (!$app) {
            return [];
        }

        $where = 'application_id = %d AND owner_user_id = %d';
        $args = [absint($app_id), absint($owner_user_id)];
        if (!$include_revoked) {
            $where .= " AND status <> 'revoked'";
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, application_id, key_name, key_prefix, masked_key, status, last_used_at, expires_at, created_at, revoked_at FROM `" . self::tables()['keys'] . "` WHERE {$where} ORDER BY created_at DESC, id DESC",
            $args
        ), ARRAY_A);
    }

    public static function owned_key($key_id, $owner_user_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `" . self::tables()['keys'] . "` WHERE id = %d AND owner_user_id = %d LIMIT 1",
            absint($key_id),
            absint($owner_user_id)
        ), ARRAY_A);

        return $row ?: null;
    }

    public static function connect_api($app_id, $owner_user_id, $api_id){
        global $wpdb;
        $app = self::owned_application($app_id, $owner_user_id);
        $api = get_post(absint($api_id));

        if (!$app || !$api || $api->post_type !== 'user_api') {
            return ['ok' => false, 'message' => 'Application or API not found.'];
        }

        $visibility = get_post_meta($api->ID, 'apiplatform_portal_visibility', true) ?: 'private';
        $can_manage_access = class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($owner_user_id, $api->ID, 'application_access.manage')
            : user_can($owner_user_id, 'manage_options');
        $status = ($can_manage_access || $visibility === 'public') ? 'approved' : 'requested';
        $now = current_time('mysql');
        $existing = self::access_for($app_id, $api->ID);
        $row = [
            'application_id' => absint($app_id),
            'api_id' => absint($api->ID),
            'access_status' => $status,
            'granted_by_user_id' => $can_manage_access ? absint($owner_user_id) : 0,
            'granted_at' => $status === 'approved' ? $now : null,
            'requested_at' => $now,
            'revoked_at' => null,
            'created_at' => $existing ? $existing['created_at'] : $now,
            'updated_at' => $now,
        ];

        if ($existing) {
            $wpdb->update(self::tables()['access'], $row, ['id' => (int) $existing['id']]);
        } else {
            $wpdb->insert(self::tables()['access'], $row);
        }

        self::record_event($app_id, $owner_user_id, $status === 'approved' ? 'api_access_approved' : 'api_access_requested', 'API access ' . $status . '.', ['api_id' => $api->ID]);

        return ['ok' => true, 'message' => $status === 'approved' ? 'API connected.' : 'API access requested.'];
    }

    public static function publisher_update_access($access_id, $publisher_user_id, $status){
        global $wpdb;
        $status = sanitize_key($status);

        if (!in_array($status, ['approved', 'denied', 'revoked'], true)) {
            return ['ok' => false, 'message' => 'Choose a valid access action.'];
        }

        $access = self::access_by_id($access_id);
        if (!$access) {
            return ['ok' => false, 'message' => 'Access request not found.'];
        }

        $api = get_post((int) $access['api_id']);
        if (!$api || $api->post_type !== 'user_api') {
            return ['ok' => false, 'message' => 'You cannot manage that API access.'];
        }

        $can_manage = class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($publisher_user_id, (int) $access['api_id'], 'application_access.manage')
            : ((int) $api->post_author === (int) $publisher_user_id || current_user_can('manage_options'));
        if (!$can_manage) {
            return ['ok' => false, 'message' => 'You cannot manage that API access.'];
        }

        $now = current_time('mysql');
        $wpdb->update(self::tables()['access'], [
            'access_status' => $status,
            'granted_by_user_id' => $status === 'approved' ? absint($publisher_user_id) : (int) ($access['granted_by_user_id'] ?? 0),
            'granted_at' => $status === 'approved' ? $now : ($access['granted_at'] ?? null),
            'revoked_at' => $status === 'revoked' ? $now : null,
            'updated_at' => $now,
        ], ['id' => absint($access_id)]);

        self::record_event($access['application_id'], $publisher_user_id, 'api_access_' . $status, 'API access ' . $status . '.', ['api_id' => $api->ID]);

        return ['ok' => true, 'message' => 'Application access updated.'];
    }

    public static function application_access($app_id, $owner_user_id){
        global $wpdb;
        $app = self::owned_application($app_id, $owner_user_id);
        if (!$app) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT x.*, p.post_title, p.post_name FROM `" . self::tables()['access'] . "` x INNER JOIN `{$wpdb->posts}` p ON p.ID = x.api_id WHERE x.application_id = %d ORDER BY x.updated_at DESC, x.id DESC",
            absint($app_id)
        ), ARRAY_A);
    }

    public static function publisher_access_for_api($api_id, $publisher_user_id){
        global $wpdb;
        $api = get_post(absint($api_id));
        $can_manage = $api && class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($publisher_user_id, (int) $api_id, 'application_access.manage')
            : ($api && ((int) $api->post_author === (int) $publisher_user_id || current_user_can('manage_options')));
        if (!$api || !$can_manage) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT x.*, a.name, a.slug, a.environment, a.status AS application_status, a.owner_user_id FROM `" . self::tables()['access'] . "` x INNER JOIN `" . self::tables()['applications'] . "` a ON a.id = x.application_id WHERE x.api_id = %d ORDER BY x.updated_at DESC, x.id DESC",
            absint($api_id)
        ), ARRAY_A);
    }

    public static function authenticate($api_id, $credential){
        global $wpdb;
        $credential = trim((string) $credential);

        if (strpos($credential, 'fapi_app_') !== 0) {
            return null;
        }

        $parts = explode('_', $credential, 4);
        if (count($parts) !== 4 || $parts[0] !== 'fapi' || $parts[1] !== 'app') {
            return ['valid' => false, 'code' => 'invalid_api_key', 'message' => 'Invalid API key.', 'status' => 401];
        }

        $lookup = sanitize_key($parts[2]);
        if ($lookup === '') {
            return ['valid' => false, 'code' => 'invalid_api_key', 'message' => 'Invalid API key.', 'status' => 401];
        }

        $key = $wpdb->get_row($wpdb->prepare("SELECT * FROM `" . self::tables()['keys'] . "` WHERE key_prefix = %s LIMIT 1", $lookup), ARRAY_A);
        if (!$key || empty($key['key_hash']) || !password_verify($credential, $key['key_hash'])) {
            return ['valid' => false, 'code' => 'invalid_api_key', 'message' => 'Invalid API key.', 'status' => 401];
        }

        $app = self::get_application((int) $key['application_id']);

        if ($key['status'] === 'revoked') {
            return self::auth_error('key_revoked', 'API key is revoked.', 403, $app, $key, null, $api_id);
        }

        if ($key['status'] !== 'active') {
            return self::auth_error('invalid_api_key', 'Invalid API key.', 401, $app, $key, null, $api_id);
        }

        if (!empty($key['expires_at']) && strtotime($key['expires_at']) < current_time('timestamp')) {
            return self::auth_error('expired_api_key', 'API key is expired.', 401, $app, $key, null, $api_id);
        }

        if (!$app || $app['status'] === 'disabled') {
            return self::auth_error('application_disabled', 'Application is disabled.', 403, $app, $key, null, $api_id);
        }

        if ($app['status'] === 'archived') {
            return self::auth_error('application_archived', 'Application is archived.', 403, $app, $key, null, $api_id);
        }

        $access = self::access_for((int) $app['id'], absint($api_id));
        if (!$access || $access['access_status'] !== 'approved') {
            $code = $access && $access['access_status'] === 'revoked' ? 'api_access_revoked' : 'api_access_not_granted';
            return self::auth_error($code, 'Application API access is not approved.', 403, $app, $key, $access, $api_id);
        }

        return [
            'valid' => true,
            'auth_type' => 'application',
            'user_id' => (int) $app['owner_user_id'],
            'application_id' => (int) $app['id'],
            'application_key_id' => (int) $key['id'],
            'key_prefix' => $key['key_prefix'],
            'api_id' => absint($api_id),
            'access_id' => (int) $access['id'],
        ];
    }

    public static function mark_used(array $context){
        global $wpdb;
        $now = current_time('mysql');
        $tables = self::tables();

        if (!empty($context['application_id'])) {
            $wpdb->update($tables['applications'], ['last_used_at' => $now], ['id' => absint($context['application_id'])], ['%s'], ['%d']);
        }
        if (!empty($context['application_key_id'])) {
            $wpdb->update($tables['keys'], ['last_used_at' => $now], ['id' => absint($context['application_key_id'])], ['%s'], ['%d']);
        }
        if (!empty($context['access_id'])) {
            $wpdb->update($tables['access'], ['last_used_at' => $now], ['id' => absint($context['access_id'])], ['%s'], ['%d']);
        }
    }

    private static function auth_error($code, $message, $status, $app, array $key, $access, $api_id){
        return [
            'valid' => false,
            'code' => sanitize_key($code),
            'message' => $message,
            'status' => absint($status),
            'auth_type' => 'application',
            'user_id' => is_array($app) ? (int) ($app['owner_user_id'] ?? 0) : 0,
            'application_id' => (int) ($key['application_id'] ?? 0),
            'application_key_id' => (int) ($key['id'] ?? 0),
            'key_prefix' => $key['key_prefix'] ?? '',
            'api_id' => absint($api_id),
            'access_id' => is_array($access) ? (int) ($access['id'] ?? 0) : 0,
        ];
    }

    public static function access_for($app_id, $api_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `" . self::tables()['access'] . "` WHERE application_id = %d AND api_id = %d LIMIT 1",
            absint($app_id),
            absint($api_id)
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function access_by_id($access_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `" . self::tables()['access'] . "` WHERE id = %d LIMIT 1", absint($access_id)), ARRAY_A);
        return $row ?: null;
    }

    public static function record_event($app_id, $actor_user_id, $event_type, $message, array $context = []){
        global $wpdb;
        return (bool) $wpdb->insert(self::tables()['events'], [
            'application_id' => absint($app_id),
            'api_id' => absint($context['api_id'] ?? 0),
            'application_key_id' => absint($context['key_id'] ?? 0),
            'actor_user_id' => absint($actor_user_id),
            'event_type' => sanitize_key($event_type),
            'message' => sanitize_text_field($message),
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s']);
    }

    private static function new_credential(){
        $lookup = strtolower(wp_generate_password(10, false, false));
        $secret = wp_generate_password(48, false, false);

        return [
            'lookup' => $lookup,
            'plaintext' => 'fapi_app_' . $lookup . '_' . $secret,
        ];
    }

    private static function mask_key($key){
        $key = (string) $key;
        return substr($key, 0, 19) . '............' . substr($key, -4);
    }

    private static function unique_slug($name){
        global $wpdb;
        $base = sanitize_title($name) ?: 'application';
        $slug = $base;
        $i = 2;

        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM `" . self::tables()['applications'] . "` WHERE slug = %s LIMIT 1", $slug))) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private static function clean_url($value){
        $value = esc_url_raw((string) $value);
        return $value && wp_http_validate_url($value) ? $value : '';
    }
}

function apiplatform_authenticate_application_key($api_id, $credential){
    return APIPlatform_Applications_Service::authenticate($api_id, $credential);
}
