<?php
if (!defined('ABSPATH')) exit;

final class FreedomAPI_AI_Connection {
    const META = '_freedomapi_ai_connection_v1';

    public static function available() {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    private static function encryption_key() {
        // An independent secret in wp-config.php is recommended; salts are a safe default.
        $secret = defined('FREEDOMAPI_AI_ENCRYPTION_KEY') ? FREEDOMAPI_AI_ENCRYPTION_KEY : wp_salt('auth');
        return hash_hkdf('sha256', $secret, 32, 'freedomapi-ai-connection-v1');
    }

    private static function aad() { return 'freedomapi-ai:user:' . get_current_user_id(); }

    public static function save($key, $model) {
        if (!get_current_user_id()) return new WP_Error('forbidden', 'Login required.', ['status' => 401]);
        if (!self::available()) return new WP_Error('encryption_unavailable', 'OpenSSL AES-256-GCM is required.', ['status' => 503]);
        if (!is_string($key) || !preg_match('/^sk-[A-Za-z0-9_\-]{16,500}$/D', $key)) return new WP_Error('invalid_key', 'Enter a valid OpenAI secret API key.', ['status' => 400]);
        if (!is_string($model) || !preg_match('/^[a-zA-Z0-9._:\-]{1,100}$/D', $model)) return new WP_Error('invalid_model', 'Enter a valid OpenAI model ID.', ['status' => 400]);
        try {
            $iv = random_bytes(12); $tag = '';
            $encrypted = openssl_encrypt($key, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, self::aad(), 16);
            if ($encrypted === false) throw new RuntimeException('Encryption failed');
            $record = ['version' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag),
                'ciphertext' => base64_encode($encrypted), 'last4' => substr($key, -4), 'model' => $model];
            if (!update_user_meta(get_current_user_id(), self::META, $record)) throw new RuntimeException('Storage failed');
        } catch (Throwable $e) { return new WP_Error('connection_save_failed', 'Could not securely save the connection.', ['status' => 500]); }
        return self::status();
    }

    public static function status() {
        $record = get_user_meta(get_current_user_id(), self::META, true);
        return ['connected' => is_array($record), 'last4' => is_array($record) ? $record['last4'] : '',
            'model' => is_array($record) ? $record['model'] : 'gpt-4.1-mini'];
    }

    public static function secret() {
        if (!get_current_user_id()) return new WP_Error('forbidden', 'Login required.', ['status' => 401]);
        if (!self::available()) return new WP_Error('encryption_unavailable', 'OpenSSL AES-256-GCM is required.', ['status' => 503]);
        $r = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($r)) return new WP_Error('connection_missing', 'Save your OpenAI connection first.', ['status' => 400]);
        try {
            $iv = base64_decode($r['iv'] ?? '', true); $tag = base64_decode($r['tag'] ?? '', true);
            $cipher = base64_decode($r['ciphertext'] ?? '', true);
            if (($r['version'] ?? 0) !== 1 || $iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $cipher === false) throw new RuntimeException('Invalid encrypted record');
            $key = openssl_decrypt($cipher, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, self::aad());
            if ($key === false) throw new RuntimeException('Authentication failed');
            return $key;
        } catch (Throwable $e) { return new WP_Error('connection_unreadable', 'Connection cannot be decrypted. Re-enter your API key; the site encryption secret may have changed.', ['status' => 400]); }
    }

    public static function disconnect() {
        if (!get_current_user_id()) return new WP_Error('forbidden', 'Login required.', ['status' => 401]);
        if (get_user_meta(get_current_user_id(), self::META, true) !== '' && !delete_user_meta(get_current_user_id(), self::META)) {
            return new WP_Error('connection_delete_failed', 'Could not remove the connection.', ['status' => 500]);
        }
        return self::status();
    }
}
