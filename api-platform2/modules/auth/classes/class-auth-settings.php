<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_Settings {
    public function __construct(){
        add_action('admin_init', [$this, 'handle_save']);
    }

    public function handle_save(){
        if (empty($_POST['save_auth_provider'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $provider_id = sanitize_key(wp_unslash($_POST['provider_id'] ?? ''));
        if (
            !isset($_POST['apiplatform_auth_provider_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apiplatform_auth_provider_nonce'])), 'apiplatform_auth_provider_' . $provider_id)
        ) {
            add_settings_error('apiplatform_auth_providers', 'auth_nonce_failed', 'Security check failed.', 'error');
            return;
        }

        $result = APIPlatform_Auth_Providers::save_settings($provider_id, wp_unslash($_POST));
        if (is_wp_error($result)) {
            add_settings_error('apiplatform_auth_providers', $result->get_error_code(), $result->get_error_message(), 'error');
            return;
        }

        add_settings_error('apiplatform_auth_providers', 'auth_provider_saved', 'Authentication provider settings saved.', 'updated');
    }

    public static function render(){
        if (!current_user_can('manage_options')) {
            return '<p>Access denied.</p>';
        }

        ob_start();
        settings_errors('apiplatform_auth_providers');
        echo '<div class="apiplatform-card"><h3>Authentication Providers</h3><p>External providers let users sign in to FreedomAPI while preserving WordPress username and password login.</p></div>';
        echo '<div class="apiplatform-card"><h3>Public Login Integration</h3>';
        echo '<p>ARMember integration: <strong>' . esc_html(class_exists('APIPlatform_ARMember_Auth_Integration') ? APIPlatform_ARMember_Auth_Integration::status_label() : 'Not detected') . '</strong></p>';
        echo '<p>Connected Accounts shortcode: <code>[apiplatform_connected_accounts]</code></p>';
        echo '<p>Provider buttons shortcode: <code>[apiplatform_auth_buttons]</code></p>';
        echo '</div>';

        foreach (APIPlatform_Auth_Providers::all() as $provider) {
            self::provider_card($provider);
        }

        return ob_get_clean();
    }

    private static function provider_card(APIPlatform_Auth_Provider_Interface $provider){
        $settings = APIPlatform_Auth_Providers::settings($provider->get_id());
        $schema = $provider->get_settings_schema();
        $callback_url = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($provider->get_id()) : home_url('/auth/' . $provider->get_id() . '/callback');

        echo '<form method="post" class="apiplatform-card apiplatform-auth-provider-card">';
        echo '<h3>' . esc_html($provider->get_name()) . '</h3>';
        echo '<p>Provider type: <code>' . esc_html($provider->get_type()) . '</code></p>';
        echo '<input type="hidden" name="provider_id" value="' . esc_attr($provider->get_id()) . '">';
        echo '<input type="hidden" name="apiplatform_auth_provider_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_auth_provider_' . $provider->get_id())) . '">';

        foreach ($schema as $field => $config) {
            $type = $config['type'] ?? 'text';
            $label = $config['label'] ?? $field;
            $value = $settings[$field] ?? '';

            echo '<p><label><strong>' . esc_html($label) . '</strong><br>';
            if ($type === 'checkbox') {
                echo '<input type="checkbox" name="' . esc_attr($field) . '" value="1" ' . checked(!empty($value), true, false) . '> Yes';
            } elseif ($type === 'secret') {
                echo '<input class="regular-text" type="password" name="' . esc_attr($field) . '" value="" placeholder="' . esc_attr(!empty($value) ? 'Saved secret is preserved unless replaced' : 'Enter client secret') . '" autocomplete="new-password">';
            } else {
                echo '<input class="regular-text" type="text" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" autocomplete="off">';
            }
            echo '</label></p>';
        }

        echo '<p><label><strong>Callback URL</strong><br><input class="regular-text code" type="text" readonly value="' . esc_attr($callback_url) . '"></label></p>';
        if (wp_parse_url($callback_url, PHP_URL_SCHEME) !== 'https') {
            echo '<p style="color:#facc15;">Google requires HTTPS callback URLs in production.</p>';
        }
        echo '<button class="button button-primary" name="save_auth_provider" value="1">Save ' . esc_html($provider->get_name()) . '</button>';
        echo '</form>';
    }
}
