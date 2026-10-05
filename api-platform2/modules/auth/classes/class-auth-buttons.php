<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_Buttons {
    public static function render(array $args = []){
        $args = array_merge([
            'context' => 'login',
            'return_url' => self::current_url(),
            'show_separator' => true,
            'provider_ids' => [],
        ], $args);

        $providers = APIPlatform_Auth_Providers::enabled_for_context($args['context']);
        if (!empty($args['provider_ids'])) {
            $allowed = array_map('sanitize_key', (array) $args['provider_ids']);
            $providers = array_filter($providers, function($provider) use ($allowed){
                return in_array($provider->get_id(), $allowed, true);
            });
        }

        if (!$providers) {
            return '';
        }

        self::enqueue_styles();

        $classes = apply_filters('apiplatform_auth_buttons_wrapper_class', [
            'apiplatform-social-login',
            'apiplatform-social-login--' . sanitize_html_class($args['context']),
        ], $args);

        $html = '<div class="' . esc_attr(implode(' ', array_filter($classes))) . '" data-apiplatform-auth-buttons="' . esc_attr($args['context']) . '">';
        if (!empty($args['show_separator'])) {
            $html .= '<div class="apiplatform-social-login__separator"><span>OR</span></div>';
        }

        $html .= '<div class="apiplatform-social-login__providers">';
        foreach ($providers as $provider) {
            $meta = APIPlatform_Auth_Providers::public_metadata($provider);
            $action = $args['context'] === 'link_account' ? 'link_account' : 'login';
            $source_context = $args['context'] === 'link_account' ? 'connected_accounts' : sanitize_key($args['context']);
            $url = add_query_arg([
                'auth_action' => $action,
                'source_context' => $source_context,
                'redirect_to' => APIPlatform_Auth_State::safe_redirect($args['return_url']),
            ], $meta['start_url']);
            $label = apply_filters('apiplatform_auth_provider_button_label', $meta['button_label'], $meta, $args);
            $html .= '<a class="apiplatform-social-login__button apiplatform-social-login__button--' . esc_attr($meta['id']) . '" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span></a>';
        }
        $html .= '</div></div>';

        return $html;
    }

    public static function enqueue_styles(){
        if (!defined('APIPLATFORM_AUTH_URL')) {
            return;
        }

        wp_enqueue_style(
            'apiplatform-auth-buttons',
            APIPLATFORM_AUTH_URL . 'assets/css/auth-buttons.css',
            [],
            function_exists('apiplatform_asset_version') ? apiplatform_asset_version('modules/auth/assets/css/auth-buttons.css') : APIPLATFORM_VERSION
        );
    }

    public static function current_url(){
        $request = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return home_url($request);
    }
}
