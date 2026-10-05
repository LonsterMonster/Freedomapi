<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Google_Auth_Provider implements APIPlatform_Auth_Provider_Interface {
    const DISCOVERY_URL = 'https://accounts.google.com/.well-known/openid-configuration';

    public function get_id(){ return 'google'; }
    public function get_name(){ return 'Google'; }
    public function get_type(){ return 'openid_connect'; }
    public function get_button_label(){ return 'Sign in with Google'; }
    public function get_default_scopes(){ return ['openid', 'profile', 'email']; }
    public function supports_pkce(){ return true; }
    public function supports_verified_email(){ return true; }

    public function get_authorization_url(array $context){
        $settings = APIPlatform_Auth_Providers::settings($this->get_id());
        $metadata = $this->discovery();
        if (is_wp_error($metadata)) return $metadata;

        return add_query_arg([
            'client_id' => $settings['client_id'],
            'redirect_uri' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($this->get_id()) : home_url('/auth/google/callback'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->get_default_scopes()),
            'state' => $context['state'],
            'nonce' => $context['nonce'],
            'code_challenge' => APIPlatform_Auth_State::pkce_challenge($context['pkce_verifier']),
            'code_challenge_method' => 'S256',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ], $metadata['authorization_endpoint']);
    }

    public function handle_callback(array $request, array $context){
        if (!empty($request['error'])) {
            return new WP_Error('provider_error', 'The authentication response could not be verified.');
        }

        $code = sanitize_text_field((string) ($request['code'] ?? ''));
        if ($code === '') {
            return new WP_Error('missing_code', 'The login callback was invalid.');
        }

        $tokens = $this->exchange_code($code, $context);
        if (is_wp_error($tokens)) return $tokens;

        $claims = $this->validate_id_token($tokens['id_token'] ?? '', $context);
        if (is_wp_error($claims)) return $claims;

        $identity = [
            'provider' => $this->get_id(),
            'provider_type' => $this->get_type(),
            'provider_user_id' => (string) ($claims['sub'] ?? ''),
            'email' => isset($claims['email']) ? sanitize_email((string) $claims['email']) : null,
            'email_verified' => array_key_exists('email_verified', $claims) ? (bool) $claims['email_verified'] : null,
            'display_name' => sanitize_text_field((string) ($claims['name'] ?? '')),
            'first_name' => sanitize_text_field((string) ($claims['given_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($claims['family_name'] ?? '')),
            'avatar_url' => esc_url_raw((string) ($claims['picture'] ?? '')),
            'locale' => sanitize_text_field((string) ($claims['locale'] ?? '')),
            'raw_profile' => $this->safe_claims($claims),
        ];

        if (empty($identity['provider_user_id'])) {
            return new WP_Error('missing_subject', 'The authentication response could not be verified.');
        }

        $settings = APIPlatform_Auth_Providers::settings($this->get_id());
        if (!empty($settings['require_verified_email']) && empty($identity['email_verified'])) {
            return new WP_Error('email_unverified', 'Google did not verify your email address.');
        }

        return $identity;
    }

    public function validate_settings(array $settings){
        if (class_exists('APIPlatform_Auth_Providers') && APIPlatform_Auth_Providers::normalize_bool($settings['enabled'] ?? false)) {
            if (trim((string) ($settings['client_id'] ?? '')) === '') {
                return new WP_Error('missing_client_id', 'Google Client ID is required before enabling Google sign-in.');
            }
            if (trim((string) ($settings['client_secret'] ?? '')) === '') {
                return new WP_Error('missing_client_secret', 'Google Client Secret is required before enabling Google sign-in.');
            }

            $callback = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($this->get_id()) : home_url('/auth/google/callback');
            $host = wp_parse_url($callback, PHP_URL_HOST);
            $is_local = in_array($host, ['localhost', '127.0.0.1'], true) || preg_match('/\.test$/', (string) $host);
            if (wp_parse_url($callback, PHP_URL_SCHEME) !== 'https' && !$is_local) {
                return new WP_Error('https_required', 'Google sign-in requires an HTTPS callback URL in production.');
            }
        }

        return true;
    }

    public function get_settings_schema(){
        return [
            'enabled' => ['type' => 'checkbox', 'label' => 'Enabled'],
            'client_id' => ['type' => 'text', 'label' => 'Client ID'],
            'client_secret' => ['type' => 'secret', 'label' => 'Client Secret'],
            'button_label' => ['type' => 'text', 'label' => 'Button label'],
            'allow_account_creation' => ['type' => 'checkbox', 'label' => 'Allow account creation'],
            'require_verified_email' => ['type' => 'checkbox', 'label' => 'Require verified email'],
            'allow_linking' => ['type' => 'checkbox', 'label' => 'Allow account linking'],
            'auto_link_verified_email' => ['type' => 'checkbox', 'label' => 'Automatically link matching verified email'],
            'show_on_wp_login' => ['type' => 'checkbox', 'label' => 'Show on WordPress login'],
            'show_on_armember_login' => ['type' => 'checkbox', 'label' => 'Show on ARMember login'],
        ];
    }

    private function exchange_code($code, array $context){
        $settings = APIPlatform_Auth_Providers::settings($this->get_id());
        $metadata = $this->discovery();
        if (is_wp_error($metadata)) return $metadata;

        $response = wp_remote_post($metadata['token_endpoint'], [
            'timeout' => 15,
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($this->get_id()) : home_url('/auth/google/callback'),
                'code_verifier' => $context['pkce_verifier'] ?? '',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('token_exchange_failed', 'Authentication is temporarily unavailable.');
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['id_token'])) {
            APIPlatform_Auth_Audit::record('invalid_token_rejected', ['provider' => $this->get_id(), 'reason' => 'missing_id_token']);
            return new WP_Error('missing_id_token', 'The authentication response could not be verified.');
        }

        return ['id_token' => (string) $body['id_token']];
    }

    private function validate_id_token($jwt, array $context){
        $jwt = (string) $jwt;
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return new WP_Error('invalid_id_token', 'The authentication response could not be verified.');
        }

        $header = json_decode($this->base64url_decode($parts[0]), true);
        $claims = json_decode($this->base64url_decode($parts[1]), true);
        if (!is_array($header) || !is_array($claims)) {
            return new WP_Error('invalid_id_token_payload', 'The authentication response could not be verified.');
        }

        if (!$this->verify_signature($parts, $header)) {
            APIPlatform_Auth_Audit::record('invalid_token_rejected', ['provider' => $this->get_id(), 'reason' => 'signature']);
            return new WP_Error('invalid_id_token_signature', 'The authentication response could not be verified.');
        }

        $settings = APIPlatform_Auth_Providers::settings($this->get_id());
        $issuer = (string) ($claims['iss'] ?? '');
        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            return new WP_Error('invalid_issuer', 'The authentication response could not be verified.');
        }

        $audience = $claims['aud'] ?? '';
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array($settings['client_id'], $audiences, true)) {
            return new WP_Error('invalid_audience', 'The authentication response could not be verified.');
        }

        if (count($audiences) > 1 && isset($claims['azp']) && $claims['azp'] !== $settings['client_id']) {
            return new WP_Error('invalid_authorized_party', 'The authentication response could not be verified.');
        }

        $now = time();
        if (empty($claims['exp']) || (int) $claims['exp'] < ($now - 60)) {
            return new WP_Error('expired_id_token', 'The authentication response could not be verified.');
        }

        if (!empty($claims['iat']) && (int) $claims['iat'] > ($now + 300)) {
            return new WP_Error('invalid_iat', 'The authentication response could not be verified.');
        }

        if (empty($claims['nonce']) || !hash_equals((string) ($context['nonce'] ?? ''), (string) $claims['nonce'])) {
            APIPlatform_Auth_Audit::record('invalid_nonce_rejected', ['provider' => $this->get_id()]);
            return new WP_Error('invalid_nonce', 'The authentication response could not be verified.');
        }

        return $claims;
    }

    private function verify_signature(array $parts, array $header){
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return false;
        }

        $keys = $this->jwks();
        if (is_wp_error($keys)) return false;

        foreach ((array) ($keys['keys'] ?? []) as $key) {
            if (($key['kid'] ?? '') !== $header['kid'] || empty($key['x5c'][0])) {
                continue;
            }

            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            $public_key = openssl_pkey_get_public($cert);
            if (!$public_key) return false;

            $verified = openssl_verify($parts[0] . '.' . $parts[1], $this->base64url_decode($parts[2]), $public_key, OPENSSL_ALGO_SHA256);
            return $verified === 1;
        }

        return false;
    }

    private function discovery(){
        $cached = get_transient('apiplatform_google_oidc_discovery');
        if (is_array($cached)) return $cached;

        $response = wp_remote_get(self::DISCOVERY_URL, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return new WP_Error('discovery_failed', 'Authentication is temporarily unavailable.');
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['authorization_endpoint']) || empty($body['token_endpoint']) || empty($body['jwks_uri'])) {
            return new WP_Error('discovery_invalid', 'Authentication is temporarily unavailable.');
        }

        set_transient('apiplatform_google_oidc_discovery', $body, HOUR_IN_SECONDS);
        return $body;
    }

    private function jwks(){
        $cached = get_transient('apiplatform_google_oidc_jwks');
        if (is_array($cached)) return $cached;

        $metadata = $this->discovery();
        if (is_wp_error($metadata)) return $metadata;

        $response = wp_remote_get($metadata['jwks_uri'], ['timeout' => 15]);
        if (is_wp_error($response)) {
            return new WP_Error('jwks_failed', 'Authentication is temporarily unavailable.');
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['keys'])) {
            return new WP_Error('jwks_invalid', 'Authentication is temporarily unavailable.');
        }

        set_transient('apiplatform_google_oidc_jwks', $body, HOUR_IN_SECONDS);
        return $body;
    }

    private function base64url_decode($value){
        return base64_decode(strtr((string) $value, '-_', '+/'));
    }

    private function safe_claims(array $claims){
        foreach (['at_hash', 'c_hash', 'nonce'] as $key) {
            unset($claims[$key]);
        }
        return $claims;
    }
}
