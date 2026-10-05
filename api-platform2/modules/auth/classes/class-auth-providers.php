<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_Providers {
    const OPTION_PREFIX = 'apiplatform_auth_provider_';
    const DISPLAY_CACHE_GROUP = 'apiplatform_auth_providers';

    public static function all(){
        $providers = [];

        if (class_exists('APIPlatform_Google_Auth_Provider')) {
            $google = new APIPlatform_Google_Auth_Provider();
            $providers[$google->get_id()] = $google;
        }

        $providers = apply_filters('apiplatform_auth_providers', $providers);

        return array_filter($providers, function($provider){
            return $provider instanceof APIPlatform_Auth_Provider_Interface && self::valid_slug($provider->get_id());
        });
    }

    public static function get($provider_id){
        $provider_id = sanitize_key($provider_id);
        $providers = self::all();
        return $providers[$provider_id] ?? null;
    }

    public static function enabled(){
        return array_filter(self::all(), function($provider){
            return self::is_enabled($provider->get_id());
        });
    }

    public static function is_enabled($provider_id){
        $provider_id = sanitize_key($provider_id);
        if (!self::valid_slug($provider_id)) {
            return false;
        }

        $settings = self::settings($provider_id);
        return self::normalize_bool($settings['enabled'] ?? false);
    }

    public static function valid_slug($provider_id){
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,60}$/', (string) $provider_id);
    }

    public static function settings($provider_id){
        $provider_id = sanitize_key($provider_id);
        $provider = self::get($provider_id);
        $defaults = [
            'enabled' => 0,
            'client_id' => '',
            'client_secret' => '',
            'button_label' => $provider ? $provider->get_button_label() : '',
            'allow_account_creation' => 1,
            'require_verified_email' => 1,
            'allow_linking' => 1,
            'auto_link_verified_email' => 0,
            'show_on_wp_login' => 1,
            'show_on_armember_login' => 1,
        ];

        $settings = get_option(self::option_name($provider_id), []);
        $settings = is_array($settings) ? $settings : [];
        $settings = array_merge($defaults, $settings);
        foreach (['enabled', 'allow_account_creation', 'require_verified_email', 'allow_linking', 'auto_link_verified_email', 'show_on_wp_login', 'show_on_armember_login'] as $key) {
            $settings[$key] = self::normalize_bool($settings[$key] ?? false) ? 1 : 0;
        }

        return apply_filters('apiplatform_auth_provider_settings', $settings, $provider_id);
    }

    public static function save_settings($provider_id, array $posted){
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You cannot manage authentication providers.');
        }

        $provider = self::get($provider_id);
        if (!$provider) {
            return new WP_Error('missing_provider', 'This authentication provider does not exist.');
        }

        $existing = self::settings($provider_id);
        $settings = [
            'enabled' => self::normalize_bool($posted['enabled'] ?? false) ? 1 : 0,
            'client_id' => sanitize_text_field((string) ($posted['client_id'] ?? '')),
            'client_secret' => trim((string) ($posted['client_secret'] ?? '')) !== ''
                ? sanitize_text_field((string) $posted['client_secret'])
                : (string) ($existing['client_secret'] ?? ''),
            'button_label' => sanitize_text_field((string) ($posted['button_label'] ?? $provider->get_button_label())),
            'allow_account_creation' => self::normalize_bool($posted['allow_account_creation'] ?? false) ? 1 : 0,
            'require_verified_email' => self::normalize_bool($posted['require_verified_email'] ?? false) ? 1 : 0,
            'allow_linking' => self::normalize_bool($posted['allow_linking'] ?? false) ? 1 : 0,
            'auto_link_verified_email' => self::normalize_bool($posted['auto_link_verified_email'] ?? false) ? 1 : 0,
            'show_on_wp_login' => self::normalize_bool($posted['show_on_wp_login'] ?? false) ? 1 : 0,
            'show_on_armember_login' => self::normalize_bool($posted['show_on_armember_login'] ?? false) ? 1 : 0,
        ];

        $validation = $provider->validate_settings($settings);
        if (is_wp_error($validation)) {
            return $validation;
        }

        update_option(self::option_name($provider_id), $settings, false);
        self::clear_display_cache($provider_id);

        APIPlatform_Auth_Audit::record(self::normalize_bool($settings['enabled']) ? 'provider_settings_enabled' : 'provider_settings_disabled', [
            'provider' => $provider_id,
            'credentials_changed' => trim((string) ($posted['client_secret'] ?? '')) !== '' ? 'yes' : 'no',
        ]);

        return true;
    }

    public static function public_metadata($provider){
        if (!$provider instanceof APIPlatform_Auth_Provider_Interface) {
            $provider = self::get($provider);
        }
        if (!$provider) return [];

        $settings = self::settings($provider->get_id());
        return [
            'id' => $provider->get_id(),
            'name' => $provider->get_name(),
            'type' => $provider->get_type(),
            'button_label' => $settings['button_label'] ?: $provider->get_button_label(),
            'start_url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_start_url($provider->get_id()) : home_url('/auth/' . $provider->get_id()),
            'callback_url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($provider->get_id()) : home_url('/auth/' . $provider->get_id() . '/callback'),
            'enabled' => self::is_enabled($provider->get_id()),
        ];
    }

    public static function enabled_for_context($context){
        $context = sanitize_key($context);

        return array_filter(self::enabled(), function($provider) use ($context){
            $settings = self::settings($provider->get_id());

            if ($context === 'wp_login') {
                return self::normalize_bool($settings['show_on_wp_login'] ?? false);
            }

            if ($context === 'armember_login') {
                return self::normalize_bool($settings['show_on_armember_login'] ?? false);
            }

            if ($context === 'link_account') {
                return self::normalize_bool($settings['allow_linking'] ?? false);
            }

            return true;
        });
    }

    public static function option_name($provider_id){
        return self::OPTION_PREFIX . sanitize_key($provider_id);
    }

    public static function normalize_bool($value){
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function clear_display_cache($provider_id = ''){
        $provider_id = sanitize_key($provider_id);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('enabled', self::DISPLAY_CACHE_GROUP);
            wp_cache_delete('enabled_for_context', self::DISPLAY_CACHE_GROUP);
            if ($provider_id !== '') {
                wp_cache_delete('provider_' . $provider_id, self::DISPLAY_CACHE_GROUP);
            }
        }

        do_action('apiplatform_auth_provider_display_cache_cleared', $provider_id);
    }
}
