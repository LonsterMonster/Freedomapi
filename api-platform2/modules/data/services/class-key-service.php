<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Key_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Keys
    |--------------------------------------------------------------------------
    */

    public function get_keys($api_id){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API LOOKUP] Post Type Searched: user_api');
            error_log('[API LOOKUP] Meta Key Searched: api_keys');
            error_log('[API LOOKUP] Meta Value Searched: API Post ID ' . $api_id);
        }

        $keys = get_post_meta(
            $api_id,
            'api_keys',
            true
        );

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API LOOKUP] Results Found: ' . (is_array($keys) ? count($keys) : 0));
            error_log('[API LOOKUP] Raw Keys: redacted');
        }

        return is_array($keys)
            ? $keys
            : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Create Key
    |--------------------------------------------------------------------------
    */

    public function create_key(
        $api_id,
        $name = 'Default'
    ){

        $keys =
            $this->get_keys($api_id);

        $new_key = function_exists('apiplatform_generate_api_key_secret')
            ? apiplatform_generate_api_key_secret()
            : 'apk_live_' . wp_generate_password(48, false, false);

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API CREATE] Generated API Key: created');
            error_log('[API CREATE] Post ID: ' . $api_id);
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            return [
                'secret' => '',
                'keys' => $keys,
                'error' => 'secure_key_storage_unavailable',
            ];
        }

        $candidate_keys = array_merge(
            $keys,
            [apiplatform_create_hashed_key_entry($new_key, $name)]
        );

        $updated = update_post_meta(
            $api_id,
            'api_keys',
            $candidate_keys
        );

        if ($updated === false || get_post_meta($api_id, 'api_keys', true) !== $candidate_keys) {
            return [
                'secret' => '',
                'keys' => $keys,
                'error' => 'secure_key_storage_failed',
            ];
        }

        $keys = $candidate_keys;

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API STORAGE] Post ID: ' . $api_id);
            error_log('[API STORAGE] Meta Key: api_keys');
            error_log('[API STORAGE] Saved Value: redacted');
            error_log('[API STORAGE] Storage Result: ' . (get_post_meta($api_id, 'api_keys', true) === $keys ? 'success' : 'verify manually'));
        }

        return [
            'secret' => $new_key,
            'keys' => $keys,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Usage Analytics
    |--------------------------------------------------------------------------
    */

    public function get_usage_stats($limit = 100){

        $stats = [];
        $limit = max(1, min(500, absint($limit)));

        $apis = get_posts([
            'post_type'      => 'user_api',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'no_found_rows'  => true,
        ]);

        foreach ($apis as $api) {
            $meta = get_post_meta($api->ID);
            $keys = $this->get_keys($api->ID);

            $legacy_key = (string) get_post_meta($api->ID, 'api_key', true);
            if ($legacy_key !== '') {
                $keys[] = [
                    'key'  => $legacy_key,
                    'name' => 'Primary',
                ];
            }

            $known_keys = [];
            foreach ($keys as $key_data) {
                $raw_key = is_array($key_data) ? ($key_data['key'] ?? '') : $key_data;
                $raw_key = (string) $raw_key;

                if ($raw_key === '') {
                    continue;
                }

                $known_keys[function_exists('apiplatform_key_usage_identifier') ? apiplatform_key_usage_identifier($raw_key) : hash_hmac('sha256', $raw_key, wp_salt('auth'))] = is_array($key_data)
                    ? (string) ($key_data['name'] ?? ($key_data['label'] ?? ''))
                    : '';
            }

            foreach ($meta as $meta_key => $value) {
                if (strpos($meta_key, 'apiplatform_key_usage_') !== 0) {
                    continue;
                }

                $hash  = substr($meta_key, strlen('apiplatform_key_usage_'));
                $count = isset($value[0]) ? absint($value[0]) : 0;

                if ($count <= 0) {
                    continue;
                }

                $name = $known_keys[$hash] ?? '';

                $stats[] = [
                    'api_id'    => $api->ID,
                    'api_name'  => get_the_title($api),
                    'key_hash'  => $hash,
                    'key_label' => $name !== '' ? $name : substr($hash, 0, 8),
                    'usage'     => $count,
                ];
            }
        }

        usort($stats, function($a, $b){
            return $b['usage'] <=> $a['usage'];
        });

        return array_slice($stats, 0, $limit);
    }
}
