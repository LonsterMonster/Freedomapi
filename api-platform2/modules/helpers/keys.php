<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
API KEY STORAGE HELPERS
----------------------------------------
*/
function apiplatform_generate_api_key_secret(){

    return 'apk_live_' . wp_generate_password(48, false, false);
}

function apiplatform_mask_api_key($key){

    $key = (string) $key;

    if ($key === '') {
        return '';
    }

    if (strpos($key, 'â€¢') !== false) {
        return $key;
    }

    $prefix = substr($key, 0, 9);
    $suffix = substr($key, -4);

    return $prefix . '••••••••••••' . $suffix;
}

function apiplatform_key_usage_identifier($key){

    return hash_hmac('sha256', (string) $key, wp_salt('auth'));
}

function apiplatform_create_hashed_key_entry($key, $label = 'Default Key', array $attributes = []){

    $entry = [
        'key_id' => !empty($attributes['key_id']) ? sanitize_key($attributes['key_id']) : wp_generate_uuid4(),
        'key_hash' => password_hash($key, PASSWORD_DEFAULT),
        'key_mask' => apiplatform_mask_api_key($key),
        'key_prefix' => substr($key, 0, 12),
        'label' => sanitize_text_field($label),
        'enabled' => !isset($attributes['enabled']) || (bool) $attributes['enabled'],
        'created' => !empty($attributes['created']) ? absint($attributes['created']) : time(),
        'storage' => 'hashed',
    ];

    foreach (['expires', 'scopes'] as $attribute) {
        if (array_key_exists($attribute, $attributes)) {
            $entry[$attribute] = $attributes[$attribute];
        }
    }

    return $entry;
}
function apiplatform_key_entry_display($entry){

    if (is_array($entry)) {
        if (!empty($entry['key_mask'])) {
            return (string) $entry['key_mask'];
        }

        if (!empty($entry['key'])) {
            return apiplatform_mask_api_key((string) $entry['key']);
        }

        return '';
    }

    return apiplatform_mask_api_key((string) $entry);
}

function apiplatform_is_masked_api_key($key){

    $key = (string) $key;

    return strpos($key, '••') !== false || strpos($key, 'â€¢') !== false;
}

function apiplatform_api_key_security_status($api_id){

    $keys = get_post_meta($api_id, 'api_keys', true);
    $keys = is_array($keys) ? $keys : [];
    $legacy = trim((string) get_post_meta($api_id, 'api_key', true));
    $legacy_active = $legacy !== '' && !apiplatform_is_masked_api_key($legacy);

    foreach ($keys as $index => $entry) {
        if (is_array($entry) && !empty($entry['key_hash'])) {
            continue;
        }

        $candidate = trim((string) (is_array($entry) ? ($entry['key'] ?? '') : $entry));
        if ($candidate !== '' && !apiplatform_is_masked_api_key($candidate)) {
            $legacy_active = true;
            break;
        }
    }

    return [
        'state' => $legacy_active ? 'legacy_compatibility' : 'current',
        'legacy_active' => $legacy_active,
    ];
}

function apiplatform_migrate_legacy_api_keys(){

    $migration_version = '1.0.0';
    if (get_option('apiplatform_api_key_storage_migration_version', '0') === $migration_version) {
        return 0;
    }

    $api_ids = get_posts([
        'post_type' => 'user_api',
        'post_status' => 'any',
        'fields' => 'ids',
        'numberposts' => -1,
        'no_found_rows' => true,
    ]);
    $migrated = 0;

    foreach ($api_ids as $api_id) {
        $stored_keys = get_post_meta($api_id, 'api_keys', true);
        $stored_keys = is_array($stored_keys) ? $stored_keys : [];
        $keys = [];
        $changed = false;

        foreach ($stored_keys as $entry) {
            if (is_array($entry) && !empty($entry['key_hash'])) {
                $keys[] = $entry;
                continue;
            }

            $plaintext = trim((string) (is_array($entry) ? ($entry['key'] ?? '') : $entry));
            if ($plaintext === '' || apiplatform_is_masked_api_key($plaintext)) {
                $keys[] = $entry;
                continue;
            }

            $attributes = is_array($entry) ? $entry : [];
            $keys[] = apiplatform_create_hashed_key_entry(
                $plaintext,
                is_array($entry) ? ($entry['label'] ?? 'Migrated API Key') : 'Migrated API Key',
                $attributes
            );
            $changed = true;
        }

        $legacy = trim((string) get_post_meta($api_id, 'api_key', true));
        if ($legacy !== '' && !apiplatform_is_masked_api_key($legacy)) {
            $represented = false;
            foreach ($keys as $index => $entry) {
                if (is_array($entry) && !empty($entry['key_hash']) && password_verify($legacy, $entry['key_hash'])) {
                    $represented = true;
                    break;
                }
            }

            if (!$represented) {
                $keys[] = apiplatform_create_hashed_key_entry($legacy, 'Migrated Legacy Key');
                $changed = true;
            }

            update_post_meta($api_id, 'api_key', apiplatform_mask_api_key($legacy));
            $changed = true;
        }

        if ($changed) {
            update_post_meta($api_id, 'api_keys', $keys);
            $migrated++;
        }
    }

    update_option('apiplatform_api_key_storage_migration_version', $migration_version, false);
    return $migrated;
}

/** Remove dormant persistent plaintext key material without touching hashes. */
function apiplatform_cleanup_legacy_plaintext_api_keys(){
    $migration_version = '1.0.0';
    $marker = 'apiplatform_api_key_plaintext_cleanup_version';
    if (get_option($marker, '0') === $migration_version) {
        return 0;
    }

    $api_ids = get_posts([
        'post_type' => 'user_api',
        'post_status' => 'any',
        'fields' => 'ids',
        'numberposts' => -1,
        'no_found_rows' => true,
    ]);
    $inspected = 0;
    $cleaned = 0;
    $failed = false;

    foreach ($api_ids as $api_id) {
        $inspected++;
        $keys = get_post_meta($api_id, 'api_keys', true);
        $keys = is_array($keys) ? $keys : [];
        $hashed_keys = [];
        $legacy_metadata = [];
        $keys_changed = false;

        foreach ($keys as $entry) {
            if (is_array($entry) && !empty($entry['key_hash'])) {
                if (array_key_exists('key', $entry)) {
                    unset($entry['key']);
                    $keys_changed = true;
                }
                $hashed_keys[] = $entry;
                continue;
            }

            // Entries without a canonical hash are obsolete plaintext/legacy
            // credentials. Preserve only non-secret state needed to indicate
            // that regeneration is required.
            if (is_array($entry)) {
                $safe = [];
                foreach (['key_id', 'label', 'name', 'enabled', 'expires', 'scopes', 'created'] as $field) {
                    if (array_key_exists($field, $entry)) {
                        $safe[$field] = $entry[$field];
                    }
                }
                $safe['storage'] = 'legacy_removed';
                $safe['key_status'] = 'regeneration_required';
                $legacy_metadata[] = $safe;
            }
            $keys_changed = true;
        }

        $clean_keys = array_merge($hashed_keys, $legacy_metadata);
        if ($keys_changed && update_post_meta($api_id, 'api_keys', $clean_keys) === false) {
            $failed = true;
            continue;
        }

        $legacy = (string) get_post_meta($api_id, 'api_key', true);
        if ($legacy !== '' && !apiplatform_is_masked_api_key($legacy)) {
            if (!delete_post_meta($api_id, 'api_key')) {
                $failed = true;
                continue;
            }
            $cleaned++;
        } elseif ($keys_changed) {
            $cleaned++;
        }
    }

    if ($failed) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] legacy plaintext key cleanup incomplete; inspected=' . absint($inspected) . ' cleaned=' . absint($cleaned) . ' marker not advanced.');
        }
        return $cleaned;
    }

    if (update_option($marker, $migration_version, false) === false) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] legacy plaintext key cleanup marker write failed; marker not advanced.');
        }
        return $cleaned;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[API PLATFORM MIGRATION] legacy plaintext key cleanup complete; inspected=' . absint($inspected) . ' cleaned=' . absint($cleaned));
    }
    return $cleaned;
}
function apiplatform_render_one_time_api_key_notice($message, $secret, $endpoint = ''){

    $secret = (string) $secret;
    $endpoint = esc_url_raw((string) $endpoint);

    if ($secret === '') {
        return esc_html($message);
    }

    $source_id = 'apiplatform-one-time-api-key-' . wp_generate_uuid4();
    $endpoint_id = 'apiplatform-one-time-endpoint-' . wp_generate_uuid4();

    $content = '<div class="apiplatform-one-time-api-key-notice">';
    $content .= '<p>' . esc_html($message) . '</p>';
    $content .= '<p><strong>Your new API key:</strong></p>';
    $content .= '<code id="' . esc_attr($source_id) . '" class="apiplatform-one-time-api-key">' . esc_html($secret) . '</code>';

    if (class_exists('APIPlatform_Renderer')) {
        $content .= APIPlatform_Renderer::component(
            'button',
            [
                'type' => 'secondary',
                'label' => 'Copy API Key',
                'html_type' => 'button',
                'class' => 'apiplatform-copy-value apiplatform-one-time-api-key-copy',
                'value' => $secret,
                'aria_label' => 'Copy newly generated API key',
                'data_attrs' => [
                    'copy-value' => $secret,
                    'copy-source' => $source_id,
                    'copy-success' => 'Copied!'
                ]
            ]
        );

        if ($endpoint !== '') {
            $content .= '<p><strong>Endpoint:</strong></p>';
            $content .= '<code id="' . esc_attr($endpoint_id) . '" class="apiplatform-one-time-endpoint">' . esc_html($endpoint) . '</code>';
            $content .= APIPlatform_Renderer::component(
                'button',
                [
                    'type' => 'secondary',
                    'label' => 'Copy Endpoint',
                    'html_type' => 'button',
                    'class' => 'apiplatform-copy-value apiplatform-one-time-endpoint-copy',
                    'value' => $endpoint,
                    'aria_label' => 'Copy API endpoint',
                    'data_attrs' => [
                        'copy-value' => $endpoint,
                        'copy-source' => $endpoint_id,
                        'copy-success' => 'Copied!'
                    ]
                ]
            );
        }
    } else {
        $content .= '<button type="button" class="apiplatform-copy-value apiplatform-one-time-api-key-copy" value="' . esc_attr($secret) . '" data-copy-value="' . esc_attr($secret) . '" data-copy-source="' . esc_attr($source_id) . '" data-copy-success="Copied!">Copy API Key</button>';

        if ($endpoint !== '') {
            $content .= '<p><strong>Endpoint:</strong></p>';
            $content .= '<code id="' . esc_attr($endpoint_id) . '" class="apiplatform-one-time-endpoint">' . esc_html($endpoint) . '</code>';
            $content .= '<button type="button" class="apiplatform-copy-value apiplatform-one-time-endpoint-copy" value="' . esc_attr($endpoint) . '" data-copy-value="' . esc_attr($endpoint) . '" data-copy-source="' . esc_attr($endpoint_id) . '" data-copy-success="Copied!">Copy Endpoint</button>';
        }
    }

    if ($endpoint !== '') {
        $content .= '<p><small><strong>Recommended:</strong> Authorization: Bearer YOUR_API_KEY. X-API-Key is supported as an alternative. Query-string authentication is compatibility-only and may expose credentials in logs or browser history.</small></p>';
    }

    $content .= '<p>Copy this API key now. For security, you won\'t be able to view it again.</p>';
    $content .= '</div>';

    return $content;
}

/*
----------------------------------------
API KEY VALIDATION
----------------------------------------
*/
function apiplatform_extract_presented_api_key(array $headers = [], array $query = []){

    $headers = array_change_key_case($headers, CASE_LOWER);
    $bearer = '';
    $authorization = trim((string) ($headers['authorization'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer(?:\s+(.+))?$/i', $authorization, $matches)) {
        $bearer = trim((string) ($matches[1] ?? ''));
        if ($bearer === '' || preg_match('/\s/', $bearer)) {
            return ['valid' => false, 'error' => 'malformed_authorization'];
        }
    }

    $x_api_key = trim((string) ($headers['x-api-key'] ?? ''));
    if ($x_api_key !== '' && preg_match('/\s/', $x_api_key)) {
        return ['valid' => false, 'error' => 'malformed_api_key'];
    }

    $query_key = trim((string) ($query['api_key'] ?? ''));
    if ($query_key !== '' && preg_match('/\s/', $query_key)) {
        return ['valid' => false, 'error' => 'malformed_api_key'];
    }

    $candidates = array_values(array_filter([
        ['key' => $bearer, 'transport' => 'authorization_bearer'],
        ['key' => $x_api_key, 'transport' => 'x_api_key'],
        ['key' => $query_key, 'transport' => 'query'],
    ], static function($candidate){ return $candidate['key'] !== ''; }));

    if (!$candidates) {
        return ['valid' => false, 'error' => 'missing_api_key'];
    }

    $selected = $candidates[0];
    foreach ($candidates as $candidate) {
        if (!hash_equals((string) $selected['key'], (string) $candidate['key'])) {
            return ['valid' => false, 'error' => 'conflicting_credentials'];
        }
    }

    return [
        'valid' => true,
        'key' => $selected['key'],
        'transport' => $selected['transport'],
    ];
}

function apiplatform_validate_api_key($api_id,$provided){

    $result = apiplatform_validate_api_key_detailed($api_id, $provided);

    return !empty($result['valid']);
}

function apiplatform_validate_api_key_detailed($api_id,$provided){

    $provided = trim((string) $provided);
    if ($provided === '') {
        return [
            'valid' => false,
            'code' => 'invalid_api_key',
            'message' => 'Invalid API key.',
            'status' => 403,
        ];
    }

    $keys = get_post_meta($api_id, 'api_keys', true);
    $keys = is_array($keys) ? $keys : [];
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $legacy_value = trim((string) get_post_meta($api_id, 'api_key', true));
        $legacy_state = $legacy_value === '' ? 'missing' : (apiplatform_is_masked_api_key($legacy_value) ? 'masked' : 'active_plaintext');
        $current_hash_candidates = 0;
        foreach ($keys as $candidate) {
            if (is_array($candidate) && !empty($candidate['key_hash'])) {
                $current_hash_candidates++;
            }
        }
        error_log('[API KEY VALIDATION] Validation Start');
        error_log('[API KEY VALIDATION] API Post ID: ' . absint($api_id));
        error_log('[API KEY VALIDATION] Stored api_keys count: ' . count($keys));
        error_log('[API KEY VALIDATION] Current hash candidates: ' . $current_hash_candidates);
        error_log('[API KEY VALIDATION] Legacy state: ' . $legacy_state);
    }

    $current_hash_match = false;
    foreach ($keys as $entry) {
        if (!is_array($entry) || empty($entry['key_hash']) || !password_verify($provided, $entry['key_hash'])) {
            continue;
        }

        $current_hash_match = true;
        $auth_type = 'current';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API KEY VALIDATION] Current hash match: YES');
        }

        if (is_array($entry) && isset($entry['enabled']) && !$entry['enabled']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[API KEY VALIDATION] Key Enabled: NO');
                error_log('[API KEY VALIDATION] Validation Result: FAIL');
            }
            return [
                'valid' => false,
                'code' => 'disabled_api_key',
                'message' => 'API key is disabled.',
                'status' => 403,
            ];
        }

        if (is_array($entry) && !empty($entry['expires']) && time() > $entry['expires']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[API KEY VALIDATION] Key Expired: YES');
                error_log('[API KEY VALIDATION] Validation Result: FAIL');
            }
            return [
                'valid' => false,
                'code' => 'expired_api_key',
                'message' => 'API key is expired.',
                'status' => 403,
            ];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API KEY VALIDATION] Key Enabled: YES');
            error_log('[API KEY VALIDATION] Auth Type: ' . $auth_type);
            error_log('[API KEY VALIDATION] Validation Result: PASS');
        }

        return [
            'valid' => true,
            'code' => 'valid',
            'message' => 'API key valid.',
            'status' => 200,
            'auth_type' => $auth_type,
            'key_id' => sanitize_text_field($entry['key_id'] ?? ''),
            'key_prefix' => sanitize_text_field($entry['key_prefix'] ?? ''),
        ];
    }


    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[API KEY VALIDATION] Current hash match: ' . ($current_hash_match ? 'YES' : 'NO'));
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[API KEY VALIDATION] Validation Result: FAIL');
    }

    return [
        'valid' => false,
        'code' => 'invalid_api_key',
        'message' => 'Invalid API key.',
        'status' => 403,
    ];
}
/*
----------------------------------------
PER-KEY LIMITS
----------------------------------------
*/
function apiplatform_check_key_limits($api_id,$api_key,$user_id){

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API AUTH] Rate Limit Check Started');
        error_log('[API AUTH] Rate Limit API Post ID: ' . $api_id);
        error_log('[API AUTH] Rate Limit User ID: ' . $user_id);
    }

    if (user_can($user_id,'manage_options')){
        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API AUTH] Rate Limit Result: bypassed for administrator');
        }

        return ['blocked'=>false];
    }

    $plan = apiplatform_get_plan_limits($user_id);

    $burst_limit = ($plan['type']==='global') ? 300 : 100;
    $daily_limit = ($plan['type']==='global') ? 50000 : 10000;

    $hash = function_exists('apiplatform_key_usage_identifier')
        ? apiplatform_key_usage_identifier($api_key)
        : hash_hmac('sha256', (string) $api_key, wp_salt('auth'));

    $burst_key = "apiplatform_burst_$hash";
    $burst = get_transient($burst_key) ?: 0;
    $burst++;

    if ($burst > $burst_limit){
        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API AUTH] Rate Limit Result: blocked by burst limit');
        }

        return ['blocked'=>true,'message'=>'Too many requests','limit'=>$daily_limit,'remaining'=>0];
    }

    set_transient($burst_key,$burst,10);

    $day = date('Ymd');
    $daily_key = "apiplatform_daily_{$hash}_{$day}";
    $daily = get_transient($daily_key) ?: 0;
    $daily++;

    if ($daily > $daily_limit){
        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API AUTH] Rate Limit Result: blocked by daily limit');
        }

        return ['blocked'=>true,'message'=>'Daily quota exceeded','limit'=>$daily_limit,'remaining'=>0];
    }

    set_transient($daily_key,$daily,DAY_IN_SECONDS);

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API AUTH] Rate Limit Result: allowed');
        error_log('[API AUTH] Rate Limit Remaining: ' . max(0,$daily_limit-$daily));
    }

    return ['blocked'=>false,'limit'=>$daily_limit,'remaining'=>max(0,$daily_limit-$daily)];
}

/*
----------------------------------------
KEY SCOPE CHECK (SAFE)
----------------------------------------
*/
function apiplatform_check_key_scope($api_id,$api_key,$required){

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API AUTH] Scope Check Started');
        error_log('[API AUTH] Scope Required: ' . $required);
    }

    $keys = get_post_meta($api_id,'api_keys',true);

    if (!is_array($keys)){
        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API AUTH] Scope Check Result: allowed, no api_keys array');
        }

        return true;
    }

    foreach($keys as $k){

        if (!is_array($k) || empty($k['key_hash']) || !password_verify($api_key, $k['key_hash'])) {
            continue;
        }

        // no scopes = allow all
        if (empty($k['scopes'])){
            if (defined('WP_DEBUG') && WP_DEBUG){
                error_log('[API AUTH] Scope Check Result: allowed, no scopes configured');
            }

            return true;
        }

        $allowed = in_array($required, $k['scopes']);

        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API AUTH] Scope Check Result: ' . ($allowed ? 'allowed' : 'denied'));
        }

        return $allowed;
    }

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API AUTH] Scope Check Result: denied, key not found');
    }

    return false;
}
/*
----------------------------------------
TRACK KEY USAGE (SAFE)
----------------------------------------
*/
function apiplatform_track_key_usage($api_id,$api_key){

    $hash = apiplatform_key_usage_identifier($api_key);
    $meta = 'apiplatform_key_usage_' . $hash;

    $count = (int)get_post_meta($api_id,$meta,true);
    update_post_meta($api_id,$meta,$count + 1);

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API STORAGE] Post ID: ' . $api_id);
        error_log('[API STORAGE] Meta Key: ' . $meta);
        error_log('[API STORAGE] Saved Value: ' . ($count + 1));
    }
}
