<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_Audit {
    public static function record($event, array $context = []){
        $safe = [];

        foreach ($context as $key => $value) {
            $key = sanitize_key($key);
            if (in_array($key, ['token', 'access_token', 'refresh_token', 'id_token', 'code', 'client_secret', 'nonce', 'pkce_verifier', 'authorization'], true)) {
                continue;
            }
            $safe[$key] = is_scalar($value) ? sanitize_text_field((string) $value) : wp_json_encode($value);
        }

        do_action('apiplatform_auth_audit_event', sanitize_key($event), $safe);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FreedomAPI auth audit] event=' . sanitize_key($event) . ' context=' . wp_json_encode($safe));
        }
    }
}
