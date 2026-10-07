<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('APIPlatform_Log_Sanitizer')) {
    class APIPlatform_Log_Sanitizer {
        const MAX_HEADERS = 8192;
        const MAX_QUERY = 8192;
        const MAX_REQUEST_BODY = 32768;
        const MAX_RESPONSE_BODY = 65536;

        private static $sensitive_headers = ['authorization','proxy-authorization','x-api-key','cookie','set-cookie','x-authorization','x-auth-token','x-access-token','x-refresh-token','x-client-secret','x-api-secret','api-key','api_secret'];
        private static $sensitive_fields = ['api_key','key','token','access_token','refresh_token','authorization','password','passwd','secret','client_secret','app_secret'];

        public static function headers($headers) {
            $safe = [];
            if (!is_array($headers)) return self::meta('unsupported_headers');
            foreach ($headers as $name => $value) {
                $name = sanitize_text_field((string) $name);
                if ($name === '') continue;
                $key = strtolower($name);
                if (in_array($key, self::$sensitive_headers, true)) {
                    $safe[$name] = $key === 'authorization' && preg_match('/^\s*bearer\s+/i', (string) $value) ? 'Bearer [REDACTED]' : '[REDACTED]';
                } else {
                    $safe[$name] = self::bounded_text(is_array($value) ? implode(', ', $value) : (string) $value, self::MAX_HEADERS);
                }
            }
            return self::bounded_array($safe, self::MAX_HEADERS);
        }

        public static function query($query) {
            return self::bounded_array(self::redact_fields(is_array($query) ? $query : []), self::MAX_QUERY);
        }

        public static function body($body, $content_type, $limit) {
            if (is_object($body)) {
                $body = json_decode(function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body), true);
            }
            $is_array = is_array($body);
            $raw_body = $is_array ? '' : (is_scalar($body) ? (string) $body : '');
            if (!$is_array && $raw_body === '') return [];
            $content_type = strtolower((string) $content_type);
            if (strpos($content_type, 'json') !== false) {
                $decoded = $is_array ? $body : json_decode($raw_body, true);
                if (!$is_array && json_last_error() !== JSON_ERROR_NONE) return self::meta('malformed_json');
                return self::bounded_array(self::redact_fields($decoded), $limit);
            }
            if (strpos($content_type, 'text/') === 0 || strpos($content_type, 'form-urlencoded') !== false) {
                return ['captured' => true, 'text' => self::bounded_text($raw_body, $limit)];
            }
            return self::meta('binary_or_unsupported_content_type');
        }

        public static function user_agent($value) {
            return self::bounded_text((string) $value, 512) ?: 'Not provided';
        }

        private static function redact_fields($value) {
            if (!is_array($value)) return self::bounded_text((string) $value, self::MAX_REQUEST_BODY);
            $safe = [];
            foreach ($value as $key => $item) {
                $key_text = strtolower((string) $key);
                if (in_array($key_text, self::$sensitive_fields, true)) {
                    $safe[$key] = '[REDACTED]';
                } else {
                    $safe[$key] = is_array($item) ? self::redact_fields($item) : self::bounded_text((string) $item, self::MAX_REQUEST_BODY);
                }
            }
            return $safe;
        }

        private static function bounded_array($value, $limit) {
            $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
            if (strlen((string) $json) <= $limit) return $value;
            return ['captured' => true, 'truncated' => true, 'value' => self::bounded_text((string) $json, $limit)];
        }

        private static function bounded_text($value, $limit) {
            $value = sanitize_textarea_field((string) $value);
            if (strlen($value) <= $limit) return $value;
            return substr($value, 0, max(0, $limit - 12)) . ' [TRUNCATED]';
        }

        private static function meta($reason) {
            return ['captured' => false, 'reason' => sanitize_key($reason)];
        }
    }
}




