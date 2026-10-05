<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_API_Save {

    private static $booted = false;

    public function __construct(){
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_action('admin_post_apiplatform_builder_save_api', [$this, 'handle_builder_save']);
        add_action('admin_post_apiplatform_save_api', [$this, 'handle_legacy_save']);
        add_action('init', [$this, 'handle_legacy_post_save']);
    }

    public function handle_builder_save(){
        $this->debug_log('[API CREATE] Builder Save Started');

        if (!current_user_can('manage_options')) {
            $this->debug_log('[API CREATE] Creation Failed: builder access denied');
            wp_die(esc_html__('Access denied.', 'apiplatform'));
        }

        check_admin_referer('apiplatform_builder_save_api', 'apiplatform_builder_nonce');

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

        $api_id = isset($_POST['api_id']) ? absint($_POST['api_id']) : 0;
        $api_name = sanitize_text_field(wp_unslash($_POST['api_name'] ?? ''));
        $raw_tree = wp_unslash($_POST['component_tree'] ?? '');
        $decoded_tree = apiplatform_builder_decode_json($raw_tree, ['version' => 1, 'components' => []]);
        $component_tree = apiplatform_builder_normalize_component_tree($decoded_tree);

        if ($api_name === '') {
            $api_name = __('Untitled API', 'apiplatform');
        }

        $this->debug_log('[API CREATE] API Name: ' . $api_name);
        $this->debug_log('[API CREATE] User ID: ' . get_current_user_id());

        $api_id = $this->upsert_api_post($api_id, $api_name);

        if (is_wp_error($api_id) || !$api_id) {
            $this->debug_log('[API CREATE] Creation Failed: builder post save failed');
            wp_die(esc_html__('Could not save API.', 'apiplatform'));
        }

        $this->debug_log('[API CREATE] Post ID: ' . $api_id);
        $this->debug_log('[API CREATE] Generated Slug: ' . get_post_field('post_name', $api_id));

        APIPlatform_Component_Registry::instance()->load_components_from_directory(APIPLATFORM_BUILDER_PATH . 'components/');

        $renderer = new APIPlatform_Response_Renderer();
        $response_data = $renderer->render_response($component_tree, []);

        if ($response_data === null) {
            $response_data = [];
        }

        $this->save_legacy_compatible_logic($api_id, $response_data);
        update_post_meta($api_id, 'api_builder_components', wp_json_encode($component_tree));
        $this->debug_storage($api_id, 'api_builder_components', wp_json_encode($component_tree));
        update_post_meta($api_id, 'api_builder_schema', 'components_v1');
        $this->debug_storage($api_id, 'api_builder_schema', 'components_v1');

        if (!$this->ensure_default_api_key($api_id)) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

        $this->debug_log('[API CREATE] Creation Successful');

        wp_safe_redirect(admin_url('admin.php?page=apiplatform-builder&api_id=' . absint($api_id) . '&updated=1'));
        exit;
    }

    public function handle_legacy_save(){
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            wp_die(esc_html__('Access denied.', 'apiplatform'));
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

        check_admin_referer('apiplatform_legacy_save_api', 'apiplatform_save_api_nonce');

        $this->save_legacy_response_from_post();
    }

    public function handle_legacy_post_save(){
        if (empty($_POST['save_api']) || !empty($_POST['action'])) {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }

        $nonce = isset($_POST['apiplatform_save_api_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['apiplatform_save_api_nonce']))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'apiplatform_legacy_save_api')) {
            return;
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            $this->debug_log('[API CREATE] Creation Failed: secure key storage unavailable');
            return;
        }

        $this->save_legacy_response_from_post();
    }

    private function save_legacy_response_from_post(){
        $this->debug_log('[API CREATE] Legacy Save Started');

        $api_name = sanitize_text_field(wp_unslash($_POST['api_name'] ?? ''));
        $raw_response = wp_unslash($_POST['response'] ?? '');
        $response = json_decode($raw_response, true);

        if ($api_name === '') {
            $api_name = __('Untitled API', 'apiplatform');
        }

        $this->debug_log('[API CREATE] API Name: ' . $api_name);
        $this->debug_log('[API CREATE] User ID: ' . get_current_user_id());

        if (!is_array($response)) {
            $this->debug_log('[API CREATE] Creation Failed: legacy response JSON invalid');
            wp_die(esc_html__('Response must be valid JSON.', 'apiplatform'));
        }

        $api_id = $this->upsert_api_post(0, $api_name);

        if (is_wp_error($api_id) || !$api_id) {
            $this->debug_log('[API CREATE] Creation Failed: legacy post save failed');
            wp_die(esc_html__('Could not save API.', 'apiplatform'));
        }

        $this->debug_log('[API CREATE] Post ID: ' . $api_id);
        $this->debug_log('[API CREATE] Generated Slug: ' . get_post_field('post_name', $api_id));

        $this->save_legacy_compatible_logic($api_id, $response);
        if (!$this->ensure_default_api_key($api_id)) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

        $this->debug_log('[API CREATE] Creation Successful');

        $redirect = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg(['api_saved' => 1, 'api_id' => absint($api_id)], $redirect));
        exit;
    }

    private function upsert_api_post($api_id, $api_name){
        $post_data = [
            'post_title'  => $api_name,
            'post_type'   => 'user_api',
            'post_status' => 'publish',
        ];

        if ($api_id) {
            $post = get_post($api_id);

            if (!$post || $post->post_type !== 'user_api') {
                $this->debug_log('[API CREATE] Creation Failed: invalid API post');
                return new WP_Error('invalid_api', __('Invalid API.', 'apiplatform'));
            }

            $can_edit = class_exists('APIPlatform_Ownership_Service')
                ? APIPlatform_Ownership_Service::can(get_current_user_id(), $api_id, 'apis.edit')
                : current_user_can('edit_post', $api_id);
            if (!$can_edit) {
                $this->debug_log('[API CREATE] Creation Failed: update access denied');
                return new WP_Error('access_denied', __('Access denied.', 'apiplatform'));
            }

            $post_data['ID'] = $api_id;
            return wp_update_post($post_data, true);
        }

        $post_data['post_author'] = get_current_user_id();
        $api_id = wp_insert_post($post_data, true);
        if (!is_wp_error($api_id) && $api_id && class_exists('APIPlatform_Ownership_Service')) {
            APIPlatform_Ownership_Service::assign($api_id, 'personal', get_current_user_id(), get_current_user_id());
        }
        return $api_id;
    }

    private function save_legacy_compatible_logic($api_id, array $response_data){
        $logic = [
            'steps' => [
                [
                    'type' => 'response',
                    'data' => $response_data,
                ],
            ],
        ];

        update_post_meta($api_id, 'api_logic', wp_json_encode($logic));
        $this->debug_storage($api_id, 'api_logic', wp_json_encode($logic));
    }

    private function ensure_default_api_key($api_id){
        $keys = get_post_meta($api_id, 'api_keys', true);
        if (is_array($keys) && !empty($keys)) {
            $this->debug_log('[API STORAGE] Existing api_keys found for Post ID: ' . $api_id);
            return true;
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            $this->debug_log('[API STORAGE] Secure key storage unavailable for Post ID: ' . $api_id);
            return false;
        }

        do {
            $key = function_exists('apiplatform_generate_api_key_secret')
                ? apiplatform_generate_api_key_secret()
                : (class_exists('APIPlatform_Core') && method_exists('APIPlatform_Core', 'generate_key')
                    ? APIPlatform_Core::generate_key('apk_live_')
                    : 'apk_live_' . wp_generate_password(48, false, false));
        } while ($this->api_key_exists($key));

        $this->debug_log('[API CREATE] Generated API Key: created');

        $display_key = function_exists('apiplatform_mask_api_key')
            ? apiplatform_mask_api_key($key)
            : '';

        $keys = [apiplatform_create_hashed_key_entry($key, __('Default Key', 'apiplatform'))];
        $keys_updated = update_post_meta($api_id, 'api_keys', $keys);

        if ($keys_updated === false || get_post_meta($api_id, 'api_keys', true) !== $keys) {
            $this->debug_log('[API STORAGE] Secure key storage failed for Post ID: ' . $api_id);
            return false;
        }

        $display_updated = update_post_meta($api_id, 'api_key', $display_key);
        $this->debug_storage($api_id, 'api_key', $display_key);

        if ($display_updated === false || get_post_meta($api_id, 'api_key', true) !== $display_key) {
            $this->debug_log('[API STORAGE] Key display metadata storage failed for Post ID: ' . $api_id);
            return false;
        }

        $this->debug_storage($api_id, 'api_keys', $keys);
        return true;
    }

    private function debug_log($message){

        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log($message);
        }
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
            if (function_exists('apiplatform_validate_api_key') && apiplatform_validate_api_key($api_id, $key)) {
                return true;
            }
        }

        return false;
    }

    private function debug_storage($post_id, $meta_key, $value){

        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API STORAGE] Post ID: ' . $post_id);
            error_log('[API STORAGE] Meta Key: ' . $meta_key);
            $stored_value = in_array($meta_key, ['api_key', 'api_keys'], true)
                ? 'redacted'
                : print_r($value, true);
            error_log('[API STORAGE] Saved Value: ' . $stored_value);
            error_log('[API STORAGE] Storage Result: ' . (get_post_meta($post_id, $meta_key, true) === $value ? 'success' : 'verify manually'));
        }
    }
}
