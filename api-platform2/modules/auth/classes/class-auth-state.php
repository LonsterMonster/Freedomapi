<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_State {
    const TTL = 600;

    public static function create($provider, array $context = []){
        $provider = sanitize_key($provider);
        $action = self::normalize_action($context['action'] ?? $context['mode'] ?? 'login');
        $state = self::random_token(32);
        $record = [
            'state' => $state,
            'provider' => $provider,
            'nonce' => self::random_token(32),
            'pkce_verifier' => self::random_token(64),
            'created_at' => time(),
            'expires_at' => time() + self::TTL,
            'redirect_to' => self::safe_redirect($context['redirect_to'] ?? ''),
            'action' => $action,
            'mode' => $action === 'link_account' ? 'link' : 'login',
            'user_id' => $action === 'link_account' ? absint($context['user_id'] ?? get_current_user_id()) : 0,
        ];

        set_transient(self::key($state), $record, self::TTL);
        return $record;
    }

    public static function consume($state, $provider){
        $state = sanitize_text_field((string) $state);
        $provider = sanitize_key($provider);

        if ($state === '') {
            return new WP_Error('missing_state', 'The login callback was invalid.', [
                'state_found' => 'no',
                'state_expired' => 'no',
                'state_consumed' => 'no',
            ]);
        }

        $key = self::key($state);
        $record = get_transient($key);
        delete_transient($key);

        if (!is_array($record)) {
            APIPlatform_Auth_Audit::record('invalid_state_rejected', ['provider' => $provider]);
            return new WP_Error('expired_state', 'The authentication request expired. Please try again.', [
                'state_found' => 'no',
                'state_expired' => 'unknown',
                'state_consumed' => 'no',
            ]);
        }

        if (($record['provider'] ?? '') !== $provider || (int) ($record['expires_at'] ?? 0) < time()) {
            APIPlatform_Auth_Audit::record('invalid_state_rejected', ['provider' => $provider, 'reason' => 'provider_or_expiration']);
            return new WP_Error('invalid_state', 'The login callback was invalid.', [
                'state_found' => 'yes',
                'state_action' => self::action_from_state($record),
                'state_expired' => (int) ($record['expires_at'] ?? 0) < time() ? 'yes' : 'no',
                'state_consumed' => 'yes',
            ]);
        }

        return $record;
    }

    public static function pkce_challenge($verifier){
        return rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');
    }

    public static function safe_redirect($redirect_to){
        $redirect_to = trim((string) $redirect_to);
        $default = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('dashboard') : home_url('/');

        if ($redirect_to === '') {
            return $default;
        }

        $safe = wp_validate_redirect($redirect_to, '');
        if ($safe === '') {
            $safe = wp_validate_redirect(home_url('/' . ltrim($redirect_to, '/')), '');
        }

        return apply_filters('apiplatform_auth_safe_redirect', $safe ?: $default, $redirect_to);
    }

    public static function action_from_state(array $state){
        return self::normalize_action($state['action'] ?? $state['mode'] ?? 'login');
    }

    public static function normalize_action($action){
        $action = sanitize_key((string) $action);
        if (in_array($action, ['link_account', 'link', 'connect', 'account_linking'], true)) {
            return 'link_account';
        }

        return 'login';
    }

    private static function key($state){
        return 'apiplatform_auth_state_' . md5((string) $state);
    }

    private static function random_token($bytes){
        $bytes = max(16, absint($bytes));
        if (function_exists('random_bytes')) {
            return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        }

        return rtrim(strtr(base64_encode(wp_generate_password($bytes, true, true)), '+/', '-_'), '=');
    }
}
