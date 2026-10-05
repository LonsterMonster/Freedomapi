<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_External_Identity_Service {
    public static function table(){
        global $wpdb;
        return $wpdb->prefix . 'apiplatform_external_identities';
    }

    public static function resolve(array $identity, array $settings, array $state){
        $identity = apply_filters('apiplatform_auth_normalized_identity', self::normalize_identity($identity), $settings, $state);

        if (is_wp_error($identity)) {
            return $identity;
        }

        $action = class_exists('APIPlatform_Auth_State')
            ? APIPlatform_Auth_State::action_from_state($state)
            : sanitize_key($state['action'] ?? $state['mode'] ?? 'login');

        if ($action === 'link_account') {
            return self::link_current_user($identity, $settings, $state);
        }

        $existing = self::find_by_provider_identity($identity['provider'], $identity['provider_user_id']);
        if ($existing) {
            $user = get_user_by('id', absint($existing['user_id']));
            if (!$user) {
                return new WP_Error('linked_user_missing', 'Authentication is temporarily unavailable.');
            }
            self::update_identity(absint($existing['id']), $identity, ['last_login_at' => current_time('mysql')]);
            self::sign_in($user->ID);
            APIPlatform_Auth_Audit::record('external_login_succeeded', ['provider' => $identity['provider'], 'user_id' => $user->ID]);
            do_action('apiplatform_auth_after_user_login', $user->ID, self::public_identity($identity));
            return ['user_id' => $user->ID, 'created' => false, 'linked' => false, 'already_connected' => true];
        }

        return self::create_or_conflict($identity, $settings);
    }

    public static function linked_for_user($user_id){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . self::table() . '` WHERE user_id = %d ORDER BY provider ASC, created_at ASC',
            absint($user_id)
        ), ARRAY_A);
    }

    public static function find_by_provider_identity($provider, $provider_user_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . self::table() . '` WHERE provider = %s AND provider_user_id = %s LIMIT 1',
            sanitize_key($provider),
            sanitize_text_field((string) $provider_user_id)
        ), ARRAY_A);
    }

    public static function find_by_user_provider($user_id, $provider){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . self::table() . '` WHERE user_id = %d AND provider = %s LIMIT 1',
            absint($user_id),
            sanitize_key($provider)
        ), ARRAY_A);
    }

    private static function link_current_user(array $identity, array $settings, array $state){
        $user_id = absint($state['user_id'] ?? get_current_user_id());
        if (!$user_id || !is_user_logged_in() || get_current_user_id() !== $user_id) {
            return new WP_Error('link_login_required', 'You must be signed in before connecting this account.');
        }

        $allow_linking = apply_filters('apiplatform_auth_allow_account_linking', !empty($settings['allow_linking']), $identity, $settings, $user_id);
        if (!$allow_linking) {
            return new WP_Error('linking_disabled', 'Account linking using this provider is disabled.');
        }

        $existing = self::find_by_provider_identity($identity['provider'], $identity['provider_user_id']);
        if ($existing && absint($existing['user_id']) !== $user_id) {
            APIPlatform_Auth_Audit::record('duplicate_identity_link_blocked', ['provider' => $identity['provider'], 'user_id' => $user_id]);
            return new WP_Error('identity_owned', 'This Google account is already connected to another FreedomAPI account.');
        }

        if ($existing) {
            self::update_identity(absint($existing['id']), $identity);
            APIPlatform_Auth_Audit::record('external_identity_already_linked', ['provider' => $identity['provider'], 'user_id' => $user_id]);
            return ['user_id' => $user_id, 'created' => false, 'linked' => false, 'already_connected' => true];
        } else {
            $linked = self::insert_identity($user_id, $identity);
            if (is_wp_error($linked)) return $linked;
        }

        APIPlatform_Auth_Audit::record('external_identity_linked', ['provider' => $identity['provider'], 'user_id' => $user_id]);
        do_action('apiplatform_auth_after_identity_linked', $user_id, self::public_identity($identity));
        return ['user_id' => $user_id, 'created' => false, 'linked' => true];
    }

    private static function create_or_conflict(array $identity, array $settings){
        $allow_creation = apply_filters('apiplatform_auth_allow_account_creation', !empty($settings['allow_account_creation']), $identity, $settings);
        if (!$allow_creation) {
            return new WP_Error('creation_disabled', 'Account creation using this provider is disabled.');
        }

        if (!empty($settings['require_verified_email']) && empty($identity['email_verified'])) {
            return new WP_Error('email_unverified', 'Google did not verify your email address.');
        }

        $email = sanitize_email($identity['email'] ?? '');
        if ($email && email_exists($email)) {
            if (!empty($settings['auto_link_verified_email']) && !empty($identity['email_verified'])) {
                $user = get_user_by('email', $email);
                if ($user) {
                    $linked = self::insert_identity($user->ID, $identity);
                    if (is_wp_error($linked)) return $linked;
                    self::sign_in($user->ID);
                    APIPlatform_Auth_Audit::record('verified_email_auto_linked', ['provider' => $identity['provider'], 'user_id' => $user->ID]);
                    return ['user_id' => $user->ID, 'created' => false, 'linked' => true];
                }
            }

            APIPlatform_Auth_Audit::record('existing_email_conflict_blocked', ['provider' => $identity['provider']]);
            return new WP_Error('email_conflict', 'An existing FreedomAPI account already uses this email address. Sign in with your existing account first, then connect Google from Connected Accounts.');
        }

        if (!$email) {
            return new WP_Error('email_required_for_creation', 'This provider did not return an email address that can be used to create an account.');
        }

        $username = self::unique_username($identity);
        $password = wp_generate_password(32, true, true);
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'display_name' => sanitize_text_field((string) ($identity['display_name'] ?: $username)),
            'first_name' => sanitize_text_field((string) ($identity['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($identity['last_name'] ?? '')),
            'role' => get_option('default_role', 'subscriber'),
        ]);

        if (is_wp_error($user_id)) {
            return new WP_Error('user_creation_failed', 'Authentication is temporarily unavailable.');
        }

        $linked = self::insert_identity($user_id, $identity);
        if (is_wp_error($linked)) {
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user($user_id);
            return $linked;
        }

        self::sign_in($user_id);
        APIPlatform_Auth_Audit::record('external_account_created', ['provider' => $identity['provider'], 'user_id' => $user_id]);
        do_action('apiplatform_auth_after_user_created', $user_id, self::public_identity($identity));
        return ['user_id' => $user_id, 'created' => true, 'linked' => true];
    }

    private static function insert_identity($user_id, array $identity){
        global $wpdb;
        $now = current_time('mysql');
        $result = $wpdb->insert(self::table(), [
            'user_id' => absint($user_id),
            'provider' => sanitize_key($identity['provider']),
            'provider_type' => sanitize_key($identity['provider_type']),
            'provider_user_id' => sanitize_text_field((string) $identity['provider_user_id']),
            'provider_email' => sanitize_email((string) ($identity['email'] ?? '')),
            'email_verified' => isset($identity['email_verified']) ? (int) (bool) $identity['email_verified'] : null,
            'display_name' => sanitize_text_field((string) ($identity['display_name'] ?? '')),
            'avatar_url' => esc_url_raw((string) ($identity['avatar_url'] ?? '')),
            'profile_data' => wp_json_encode(self::safe_profile($identity['raw_profile'] ?? [])),
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => $now,
        ]);

        if (!$result) {
            return new WP_Error('identity_link_failed', 'Authentication is temporarily unavailable.');
        }

        return absint($wpdb->insert_id);
    }

    private static function update_identity($identity_id, array $identity, array $extra = []){
        global $wpdb;
        $data = array_merge([
            'provider_email' => sanitize_email((string) ($identity['email'] ?? '')),
            'email_verified' => isset($identity['email_verified']) ? (int) (bool) $identity['email_verified'] : null,
            'display_name' => sanitize_text_field((string) ($identity['display_name'] ?? '')),
            'avatar_url' => esc_url_raw((string) ($identity['avatar_url'] ?? '')),
            'profile_data' => wp_json_encode(self::safe_profile($identity['raw_profile'] ?? [])),
            'updated_at' => current_time('mysql'),
        ], $extra);

        return $wpdb->update(self::table(), $data, ['id' => absint($identity_id)]);
    }

    private static function sign_in($user_id){
        do_action('apiplatform_auth_before_user_login', absint($user_id));
        wp_set_current_user(absint($user_id));
        wp_set_auth_cookie(absint($user_id), true, is_ssl());
    }

    private static function normalize_identity(array $identity){
        if (empty($identity['provider']) || empty($identity['provider_user_id'])) {
            return new WP_Error('invalid_identity', 'The authentication response could not be verified.');
        }

        return [
            'provider' => sanitize_key($identity['provider']),
            'provider_type' => sanitize_key($identity['provider_type'] ?? 'custom'),
            'provider_user_id' => sanitize_text_field((string) $identity['provider_user_id']),
            'email' => isset($identity['email']) ? sanitize_email((string) $identity['email']) : null,
            'email_verified' => array_key_exists('email_verified', $identity) ? (bool) $identity['email_verified'] : null,
            'display_name' => sanitize_text_field((string) ($identity['display_name'] ?? '')),
            'first_name' => sanitize_text_field((string) ($identity['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($identity['last_name'] ?? '')),
            'avatar_url' => esc_url_raw((string) ($identity['avatar_url'] ?? '')),
            'locale' => sanitize_text_field((string) ($identity['locale'] ?? '')),
            'raw_profile' => self::safe_profile($identity['raw_profile'] ?? []),
        ];
    }

    private static function unique_username(array $identity){
        $candidates = [];
        if (!empty($identity['preferred_username'])) $candidates[] = $identity['preferred_username'];
        if (!empty($identity['email'])) $candidates[] = preg_replace('/@.+$/', '', $identity['email']);
        if (!empty($identity['display_name'])) $candidates[] = $identity['display_name'];
        $candidates[] = $identity['provider'] . '_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $identity['provider_user_id']), 0, 12);

        foreach ($candidates as $candidate) {
            $base = sanitize_user($candidate, true);
            if ($base === '') continue;
            $username = $base;
            $i = 2;
            while (username_exists($username)) {
                $username = $base . $i;
                $i++;
            }
            return $username;
        }

        return 'user' . wp_rand(100000, 999999);
    }

    private static function public_identity(array $identity){
        unset($identity['raw_profile']);
        return $identity;
    }

    private static function safe_profile($profile){
        $profile = is_array($profile) ? $profile : [];
        foreach (['access_token', 'refresh_token', 'id_token', 'token', 'code', 'nonce', 'client_secret', 'pkce_verifier'] as $secret_key) {
            unset($profile[$secret_key]);
        }
        return $profile;
    }
}
