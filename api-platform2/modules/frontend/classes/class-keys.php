<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Keys {

    public function __construct(){

        add_action(

            'template_redirect',

            [$this, 'maybe_handle_key_actions']
        );

        add_shortcode(

            'api_keys_page',

            [$this, 'render']
        );
    }

    public function render(){

        /*
        |--------------------------------------------------------------------------
        | Assets
        |--------------------------------------------------------------------------
        */

        APIPlatform_Frontend_Assets::enqueue_keys();

        /*
        |--------------------------------------------------------------------------
        | Auth Check
        |--------------------------------------------------------------------------
        */

        if (!is_user_logged_in()) {

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Login required'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Current User
        |--------------------------------------------------------------------------
        */

        $user_id = get_current_user_id();

        $user = wp_get_current_user();

        $username = $user && $user->exists()

            ? $user->user_login

            : '';

        $notice_key = $this->notice_key($user_id);
        $notice = $this->consume_notice(

            $user_id
        );

        $notice_exists = $notice !== '';

        $this->debug_one_time_notice($notice_key, $notice_exists, 'keys');

        /*
        |--------------------------------------------------------------------------
        | APIs
        |--------------------------------------------------------------------------
        */

        $api_args = [

            'post_type' => 'user_api',

            'author' => $user_id,

            'numberposts' => -1,

            'no_found_rows' => true
        ];

        if (class_exists('APIPlatform_Organization_Permissions')) {
            $ids = APIPlatform_Organization_Permissions::user_api_ids($user_id, 'apis.manage_keys');
            unset($api_args['author']);
            $api_args['post__in'] = $ids ?: [0];
        }

        $apis = get_posts($api_args);

        /*
        |--------------------------------------------------------------------------
        | Prepared Endpoint Cards
        |--------------------------------------------------------------------------
        */

        $api_cards = $this->prepare_api_keys(

            $apis,

            $username
        );

        /*
        |--------------------------------------------------------------------------
        | Debug
        |--------------------------------------------------------------------------
        */

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                '[KEYS PAGE] User ID: ' .

                $user_id
            );

            error_log(

                '[KEYS PAGE] APIs Loaded: ' .

                count($apis)
            );

            error_log(

                '[KEYS PAGE] API Count: ' .

                count($apis)
            );

            error_log(

                '[KEYS PAGE] API Cards Rendered: ' .

                count($api_cards)
            );

            if (empty($apis)){

                error_log(

                    '[KEYS PAGE] Empty User APIs For User ID: ' .

                    $user_id
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = $notice;

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        if (empty($apis)){

            $content .= APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'info',

                    'content' => 'No APIs found. Create an API to generate your first key.'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Key List
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'keys/key-list',

            [

                'api_keys' => $api_cards,

                'apis' => $apis
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Final Layout
        |--------------------------------------------------------------------------
        */

        $page = APIPlatform_Renderer::component(

            'section',

            [

                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [

                        'items' => [

                            '<h1>API Keys</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );

        if ($notice_exists) {

            $this->schedule_notice_delete($notice_key);
        }

        return $page;
    }

    private function prepare_api_keys($apis, $username = ''){

        $api_keys = [];

        foreach($apis as $api){

            $endpoint_url = $this->generate_endpoint_url(

                $api
            );

            if (

                defined('WP_DEBUG') &&

                WP_DEBUG
            ){

                error_log('[KEYS PAGE] Slug: ' . $api->post_name);
            }

            $card_keys = [];

            $keys = class_exists('APIPlatform_Key_Service')

                ? APIPlatform_Key_Service::instance()
                    ->get_keys($api->ID)

                : get_post_meta(

                    $api->ID,

                    'api_keys',

                    true
                );

            if (!is_array($keys)){

                $keys = [];
            }

            $legacy_key = get_post_meta(

                $api->ID,

                'api_key',

                true
            );

            if (empty($keys) && $legacy_key){

                if (

                    defined('WP_DEBUG') &&

                    WP_DEBUG
                ){

                    error_log('[KEYS PAGE] Key Loaded: present');
                    error_log('[KEYS PAGE] Key Source: api_key');
                }

                $keys[] = [

                    'key' => $legacy_key,

                    'enabled' => true
                ];
            }

            if (empty($keys)){

                $this->debug_missing_key(

                    $api
                );

                $card_keys[] = [

                    'api_key' => '',

                    'status' => 'Missing',

                    'request_url' => ''
                ];

                $api_keys[] = $this->make_api_card(

                    $api,

                    $username,

                    $endpoint_url,

                    $card_keys,

                    'Missing'
                );

                continue;
            }

            $card_status = 'Missing';

            foreach($keys as $key){

                if (is_array($key)){

                    $raw_key = $key['key'] ?? '';
                    $api_key = function_exists('apiplatform_key_entry_display')
                        ? apiplatform_key_entry_display($key)
                        : (string) $raw_key;

                    $enabled = $key['enabled'] ?? true;

                    $status = $enabled

                        ? 'Active'

                        : 'Disabled';
                } else {

                    $raw_key = (string) $key;
                    $api_key = function_exists('apiplatform_key_entry_display')
                        ? apiplatform_key_entry_display($key)
                        : (string) $key;

                    $status = 'Active';
                }

                if (!$api_key){

                    $this->debug_missing_key(

                        $api
                    );
                } elseif (

                    defined('WP_DEBUG') &&

                    WP_DEBUG
                ){

                    error_log('[KEYS PAGE] Key Loaded: present');
                    error_log('[KEYS PAGE] Key Source: api_keys');
                }

                $row_status = $api_key ? $status : 'Missing';
                $is_plaintext_legacy = is_array($key) ? !empty($key['key']) : (string) $key !== '';

                if ($row_status === 'Active'){

                    $card_status = 'Active';
                } elseif ($card_status !== 'Active'){

                    $card_status = $row_status;
                }

                $card_keys[] = [

                    'api_key' => $api_key,

                    'status' => $row_status,

                    'request_url' => '',

                    'copyable' => false
                ];
            }

            $api_keys[] = $this->make_api_card(

                $api,

                $username,

                $endpoint_url,

                $card_keys,

                $card_status
            );
        }

        return $api_keys;
    }

    private function make_api_card($api, $username, $endpoint_url, array $keys, $status){

        return [

            'api_name' => get_the_title($api),

            'api_id' => $api->ID,

            'username' => $username,

            'api_slug' => $api->post_name,

            'endpoint_url' => $endpoint_url,

            'keys' => $keys,

            'status' => $status,

            'edit_url' => $this->edit_api_url($api->ID),

            'test_url' => $this->test_api_url($api->ID),

            'docs_url' => $this->documentation_url($api->ID),

            'history_url' => $this->request_history_url($api->ID),

            'regenerate_nonce' => wp_create_nonce(

                'apiplatform_regenerate_key_' . $api->ID
            )
        ];
    }

    public function maybe_handle_key_actions(){

        if (!is_user_logged_in()) {

            return;
        }

        $user_id = get_current_user_id();

        $notice = $this->handle_key_actions($user_id);

        if ($notice === '') {

            return;
        }

        set_transient($this->notice_key($user_id), $notice, 60);

        wp_safe_redirect($this->current_url());
        exit;
    }

    private function consume_notice($user_id){

        $key = $this->notice_key($user_id);
        $notice = get_transient($key);

        if ($notice !== false) {

            return (string) $notice;
        }

        return '';
    }

    private function notice_key($user_id){

        return 'apiplatform_keys_notice_' . absint($user_id);
    }

    private function handle_key_actions($user_id){

        $action = isset($_POST['apiplatform_key_action'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_key_action']))

            : '';

        if ($action === '' && isset($_POST['create_key'])) {

            $action = 'create';
        }

        if (

            !$action ||

            !in_array($action, ['regenerate', 'create'], true)
        ){

            return '';
        }

        $api_id = isset($_POST['api_id'])

            ? absint($_POST['api_id'])

            : 0;

        if ($action === 'create') {

            return $this->handle_create_key($user_id, $api_id);
        }

        $nonce = isset($_POST['apiplatform_regenerate_key_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_regenerate_key_nonce']))

            : '';

        $this->debug_regenerate('[API KEY REGENERATE] API ID: ' . $api_id);
        $this->debug_regenerate('[API KEY REGENERATE] User ID: ' . $user_id);

        if (

            !$api_id ||

            !wp_verify_nonce(

                $nonce,

                'apiplatform_regenerate_key_' . $api_id
            )
        ){

            $this->debug_regenerate('[API KEY REGENERATE] Failure: nonce verification failed');

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Security check failed. Please try again.'
                ]
            );
        }

        $api = get_post($api_id);

        if (

            !$api ||

            $api->post_type !== 'user_api' ||

            !(
                class_exists('APIPlatform_Ownership_Service')
                    ? APIPlatform_Ownership_Service::can($user_id, $api_id, 'apis.manage_keys')
                    : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'))
            )
        ){

            $this->debug_regenerate('[API KEY REGENERATE] Failure: ownership validation failed');

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'You cannot regenerate a key for that API.'
                ]
            );
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            $this->debug_regenerate('[API KEY REGENERATE] Failure: secure key storage unavailable');
            return APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'API key regeneration failed. Please try again.'
            ]);
        }

        $new_key = $this->generate_api_key();
        $keys = [apiplatform_create_hashed_key_entry($new_key, 'Default Key')];

        $keys_updated = update_post_meta($api_id, 'api_keys', $keys);
        $stored_keys = get_post_meta($api_id, 'api_keys', true);

        if (

            $keys_updated === false ||

            $stored_keys !== $keys
        ){

            $this->debug_regenerate('[API KEY REGENERATE] Failure: storage verification failed');

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'API key regeneration failed. Please try again.'
                ]
            );
        }

        $display_updated = update_post_meta($api_id, 'api_key', function_exists('apiplatform_mask_api_key') ? apiplatform_mask_api_key($new_key) : '');
        $stored_key = get_post_meta($api_id, 'api_key', true);

        if ($display_updated === false || !$stored_key) {
            $this->debug_regenerate('[API KEY REGENERATE] Failure: display metadata storage failed');
            return APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'API key regeneration failed. Please try again.'
            ]);
        }

        $this->debug_regenerate('[API KEY REGENERATE] Old Key Replaced: yes');
        $this->debug_regenerate('[API KEY REGENERATE] New Key Generated: created');
        $this->debug_regenerate('[API KEY REGENERATE] Success');

        return APIPlatform_Renderer::component(

            'alert',

            [

                'type' => 'success',

                'content' => function_exists('apiplatform_render_one_time_api_key_notice')
                    ? apiplatform_render_one_time_api_key_notice('API key regenerated successfully.', $new_key, $this->generate_endpoint_url($api))
                    : 'API key regenerated successfully. Copy this API key now. For security, you won\'t be able to view it again: <code>' . esc_html($new_key) . '</code>'
            ]
        );
    }

    private function handle_create_key($user_id, $api_id){

        $nonce = isset($_POST['apiplatform_keys_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_keys_nonce']))

            : '';

        if (!$api_id || !wp_verify_nonce($nonce, 'apiplatform_keys')){

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Security check failed. Please try again.'
                ]
            );
        }

        $api = get_post($api_id);

        if (

            !$api ||

            $api->post_type !== 'user_api' ||

            !(
                class_exists('APIPlatform_Ownership_Service')
                    ? APIPlatform_Ownership_Service::can($user_id, $api_id, 'apis.manage_keys')
                    : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'))
            )
        ){

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'You cannot create a key for that API.'
                ]
            );
        }

        $label = isset($_POST['key_name'])

            ? sanitize_text_field(wp_unslash($_POST['key_name']))

            : 'Default Key';

        if ($label === '') {

            $label = 'Default Key';
        }

        if (class_exists('APIPlatform_Key_Service')) {

            $result = APIPlatform_Key_Service::instance()->create_key($api_id, $label);
            $new_key = (string) ($result['secret'] ?? '');
        } else {

            if (!function_exists('apiplatform_create_hashed_key_entry')) {
                $new_key = '';
            } else {
                $new_key = $this->generate_api_key();
            }
            $keys = get_post_meta($api_id, 'api_keys', true);

            if (!is_array($keys)) {

                $keys = [];
            }

            if ($new_key !== '') {
                $keys[] = apiplatform_create_hashed_key_entry($new_key, $label);
                $updated = update_post_meta($api_id, 'api_keys', $keys);
                if ($updated === false || get_post_meta($api_id, 'api_keys', true) !== $keys) {
                    $new_key = '';
                }
            }
        }

        if ($new_key === '') {

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'API key creation failed. Please try again.'
                ]
            );
        }

        if (!get_post_meta($api_id, 'api_key', true)) {

            update_post_meta($api_id, 'api_key', function_exists('apiplatform_mask_api_key') ? apiplatform_mask_api_key($new_key) : '');
        }

        return APIPlatform_Renderer::component(

            'alert',

            [

                'type' => 'success',

                'content' => function_exists('apiplatform_render_one_time_api_key_notice')
                    ? apiplatform_render_one_time_api_key_notice('API key created successfully.', $new_key, $this->generate_endpoint_url($api))
                    : 'API key created successfully. Copy this API key now. For security, you won\'t be able to view it again: <code>' . esc_html($new_key) . '</code>'
            ]
        );
    }

    private function get_primary_api_key($api_id){

        $keys = get_post_meta($api_id, 'api_keys', true);

        if (is_array($keys) && !empty($keys)){

            $first = reset($keys);

            if (is_array($first)){

                return function_exists('apiplatform_key_entry_display')
                    ? apiplatform_key_entry_display($first)
                    : ($first['key'] ?? '');
            }

            return function_exists('apiplatform_key_entry_display')
                ? apiplatform_key_entry_display($first)
                : (string) $first;
        }

        $legacy = (string) get_post_meta($api_id, 'api_key', true);

        return function_exists('apiplatform_mask_api_key')
            ? apiplatform_mask_api_key($legacy)
            : $legacy;
    }

    private function generate_api_key(){

        if (

            class_exists('APIPlatform_Core') &&

            method_exists('APIPlatform_Core', 'generate_key')
        ){

            do {
                $key = function_exists('apiplatform_generate_api_key_secret')
                    ? apiplatform_generate_api_key_secret()
                    : APIPlatform_Core::generate_key('apk_live_');
            } while ($this->api_key_exists($key));

            return $key;
        }

        do {
            $key = function_exists('apiplatform_generate_api_key_secret')
                ? apiplatform_generate_api_key_secret()
                : 'apk_live_' . wp_generate_password(

                32,

                false,

                false
            );
        } while ($this->api_key_exists($key));

        return $key;
    }

    private function api_key_exists($key){

        $legacy = get_posts([
            'post_type' => 'user_api',
            'post_status' => 'any',
            'meta_key' => 'api_key',
            'meta_value' => $key,
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);

        if (!empty($legacy)) {
            return true;
        }

        $apis = get_posts([
            'post_type' => 'user_api',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);

        foreach ($apis as $api_id) {
            if (apiplatform_validate_api_key($api_id, $key)) {
                return true;
            }
        }

        return false;
    }

    private function generate_endpoint_url($api){

        $endpoint_url = function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api)
            : (
                class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                    : rest_url('platform/v1/api/' . $api->post_name)
            );

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                '[KEYS PAGE] Endpoint Generated For API ' .

                ($api->ID ?? 'missing') .

                ': ' .

                $endpoint_url
            );

            error_log('[KEYS PAGE] Endpoint: ' . $endpoint_url);
        }

        return $endpoint_url;
    }

    private function edit_api_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(

                [

                    'apipage' => 'edit-api',

                    'api_id' => absint($api_id)
                ],

                (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
            )

            : add_query_arg(

                'api_id',

                absint($api_id),

                site_url('/edit-api')
            );
    }

    private function test_api_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(

                [

                    'apipage' => 'test-api',

                    'api_id' => absint($api_id)
                ],

                (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
            )

            : add_query_arg(

                'api_id',

                absint($api_id),

                site_url('/test-api')
            );
    }

    private function documentation_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(

                [

                    'apipage' => 'api-documentation',

                    'api_id' => absint($api_id)
                ],

                (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
            )

            : add_query_arg(

                'api_id',

                absint($api_id),

                site_url('/api-documentation')
            );
    }

    private function request_history_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(

                [

                    'apipage' => 'request-history',

                    'api_id' => absint($api_id)
                ],

                (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
            )

            : add_query_arg(

                'api_id',

                absint($api_id),

                site_url('/request-history')
            );
    }

    private function current_url(){

        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))
            : wp_parse_url(home_url(), PHP_URL_HOST);
        $uri = isset($_SERVER['REQUEST_URI'])
            ? wp_unslash($_SERVER['REQUEST_URI'])
            : '/';

        return esc_url_raw($scheme . $host . $uri);
    }

    private function debug_missing_key($api){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log(

                '[KEYS PAGE] Missing API Key For API: ' .

                ($api->ID ?? 'missing')
            );
        }
    }

    private function debug_regenerate($message){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log($message);
        }
    }

    private function debug_one_time_notice($key, $exists, $context){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API KEY ONE-TIME] Context: ' . $context);
            error_log('[API KEY ONE-TIME] Exists: ' . ($exists ? 'yes' : 'no'));
        }
    }

    private function schedule_notice_delete($key){

        add_action('shutdown', function() use ($key){

            delete_transient($key);
        });
    }
}
