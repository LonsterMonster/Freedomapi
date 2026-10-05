<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Edit_API {

    private $response_json_error = '';

    private $submitted_response_json = null;

    public function __construct(){

        add_action(

            'template_redirect',

            [$this, 'maybe_handle_post']
        );

        add_shortcode(

            'api_edit_api_page',

            [$this, 'render']
        );
    }

    public function render(){

        APIPlatform_Frontend_Assets::enqueue_keys();

        if (!is_user_logged_in()) {

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Login required'
                ]
            );
        }

        $user_id = get_current_user_id();

        $api_id = $this->get_requested_api_id();

        $notice = '';

        if (!$api_id){

            return $this->render_page(

                APIPlatform_Renderer::component(

                    'alert',

                    [

                        'type' => 'error',

                        'content' => 'API not found.'
                    ]
                )
            );
        }

        $api = get_post($api_id);

        if (!$this->user_owns_api($api, $user_id)){

            $this->debug_log('[API EDIT] Ownership failed for API ID: ' . $api_id);

            return $this->render_page(

                APIPlatform_Renderer::component(

                    'alert',

                    [

                        'type' => 'error',

                        'content' => 'You cannot edit that API.'
                    ]
                )
            );
        }

        $this->debug_log('[API EDIT] API loaded: ' . $api_id);
        $this->debug_log('[API EDIT] Ownership verified for User ID: ' . $user_id);

        $notice_key = $this->notice_key($user_id, $api_id);
        $notice = $this->consume_notice($user_id, $api_id);
        $notice_exists = $notice !== '';

        $this->debug_one_time_notice($notice_key, $notice_exists, 'edit-api');

        $view_data = $this->prepare_view_data(

            $api,

            $user_id
        );

        $content = $notice;

        $content .= APIPlatform_Renderer::component(

            'grid',

            [

                'columns' => 3,

                'items' => $this->usage_cards($view_data['usage'])
            ]
        );

        $content .= APIPlatform_Renderer::partial(

            'edit-api/edit-form',

            $view_data
        );

        if (class_exists('APIPlatform_Developer_Portal_Publishing')) {

            $content .= APIPlatform_Developer_Portal_Publishing::render_settings($view_data);
        }

        $content .= APIPlatform_Renderer::partial(

            'edit-api/key-management',

            $view_data
        );

        $content .= APIPlatform_Renderer::partial(

            'edit-api/delete-api',

            $view_data
        );

        $content .= apply_filters('freedomapi_api_management_sections', '', $api_id, $user_id);
        $page = $this->render_page($content);

        if ($notice_exists) {

            $this->schedule_notice_delete($notice_key);
        }

        return $page;
    }

    public function maybe_handle_post(){

        if (!is_user_logged_in()) {

            return;
        }

        $action = isset($_POST['apiplatform_edit_api_action'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_edit_api_action']))

            : '';

        if (!in_array($action, ['save', 'regenerate', 'delete'], true)) {

            return;
        }

        $user_id = get_current_user_id();
        $api_id = $this->get_requested_api_id();
        $api = $api_id ? get_post($api_id) : null;

        $permission = ['save' => 'apis.edit', 'regenerate' => 'apis.manage_keys', 'delete' => 'apis.delete'][$action];
        if (!$this->user_can_api($api, $user_id, $permission)) {

            set_transient($this->notice_key($user_id, $api_id), $this->alert('error', 'You cannot edit that API.'), 60);
            wp_safe_redirect($this->current_url());
            exit;
        }

        if ($action === 'delete') {

            $delete_result = $this->handle_delete($api, $user_id);

            if ($delete_result === true) {

                wp_safe_redirect($this->keys_page_url());
                exit;
            }

            set_transient($this->notice_key($user_id, $api_id), $delete_result, 60);
            wp_safe_redirect($this->current_url());
            exit;
        }

        $notice = $action === 'save'

            ? $this->handle_save($api, $user_id)

            : $this->handle_regenerate($api, $user_id);

        set_transient($this->notice_key($user_id, $api_id), $notice, 60);

        wp_safe_redirect($this->current_url());
        exit;
    }

    private function handle_save($api, $user_id){
        if (
            !empty($_POST['apiplatform_portal_settings_present']) &&
            !empty($_POST['apiplatform_portal_only'])
        ){

            return $this->handle_portal_settings_save($api, $user_id);
        }

        $nonce = isset($_POST['apiplatform_edit_api_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_edit_api_nonce']))

            : '';

        if (!wp_verify_nonce($nonce, 'apiplatform_edit_api_' . $api->ID)){

            $this->debug_log('[API EDIT] Save failure: nonce verification failed');

            return $this->alert('error', 'Security check failed. Please try again.');
        }

        if (!$this->user_owns_api($api, $user_id)){

            $this->debug_log('[API EDIT] Save failure: ownership verification failed');

            return $this->alert('error', 'You cannot edit that API.');
        }

        $api_name = isset($_POST['api_name'])

            ? sanitize_text_field(wp_unslash($_POST['api_name']))

            : '';

        $api_slug = isset($_POST['api_slug'])

            ? sanitize_title(wp_unslash($_POST['api_slug']))

            : '';

        $description = isset($_POST['description'])

            ? sanitize_textarea_field(wp_unslash($_POST['description']))

            : '';

        $response_json = isset($_POST['response_json'])

            ? wp_unslash($_POST['response_json'])

            : '';

        $status = isset($_POST['apiplatform_status'])

            ? sanitize_key(wp_unslash($_POST['apiplatform_status']))

            : 'active';

        if (!$api_name){

            $this->debug_log('[API EDIT] Save failure: missing API name');

            return $this->alert('error', 'API name is required.');
        }

        if (!$api_slug){

            $this->debug_log('[API EDIT] Save failure: missing API slug');

            return $this->alert('error', 'API slug is required.');
        }

        if (!in_array($status, ['active', 'inactive', 'archived'], true)){

            $status = 'active';
        }

        $response_data = json_decode($response_json, true);

        if (

            json_last_error() !== JSON_ERROR_NONE ||

            !is_array($response_data)
        ){

            $json_error = json_last_error_msg();

            $this->response_json_error = 'Response JSON is invalid: ' . $json_error;

            $this->submitted_response_json = $response_json;

            $this->debug_log('[API JSON VALIDATION] API ID: ' . $api->ID);
            $this->debug_log('[API JSON VALIDATION] User ID: ' . $user_id);
            $this->debug_log('[API JSON VALIDATION] Validation Error: ' . $json_error);
            $this->debug_log('[API JSON SAVE] Save Failure: invalid response JSON');
            $this->debug_log('[API EDIT] Save failure: invalid response JSON');

            return $this->alert('error', $this->response_json_error);
        }

        if (!$this->slug_is_unique($api->ID, $user_id, $api_slug)){

            $this->debug_log('[API EDIT] Save failure: slug already exists');

            return $this->alert('error', 'You already have an API with that slug.');
        }

        $updated = wp_update_post(

            [

                'ID' => $api->ID,

                'post_title' => $api_name,

                'post_name' => $api_slug,

                'post_content' => $description
            ],

            true
        );

        if (is_wp_error($updated)){

            $this->debug_log('[API EDIT] Save failure: ' . $updated->get_error_message());

            return $this->alert('error', $updated->get_error_message());
        }

        update_post_meta($api->ID, 'apiplatform_status', $status);

        if (class_exists('APIPlatform_Lifecycle_Policy')) {
            if ($status === 'archived') {
                update_post_meta($api->ID, APIPlatform_Lifecycle_Policy::META_STATUS, 'archived');
                update_post_meta($api->ID, APIPlatform_Lifecycle_Policy::META_CHANGED_AT, current_time('mysql'));
                update_post_meta($api->ID, 'apiplatform_portal_visibility', 'private');
                update_post_meta($api->ID, 'apiplatform_portal_updated_at', current_time('mysql'));
                if (class_exists('APIPlatform_Developer_Portal_Query')) {
                    $settings = APIPlatform_Developer_Portal_Query::get_settings($api->ID);
                    $settings['visibility'] = 'private';
                    $settings['updated_at'] = current_time('mysql');
                    APIPlatform_Developer_Portal_Query::sync_registry($api->ID, $settings);
                }
            } elseif ($status === 'inactive') {
                update_post_meta($api->ID, APIPlatform_Lifecycle_Policy::META_STATUS, 'private');
                update_post_meta($api->ID, APIPlatform_Lifecycle_Policy::META_CHANGED_AT, current_time('mysql'));
                update_post_meta($api->ID, 'apiplatform_portal_visibility', 'private');
                update_post_meta($api->ID, 'apiplatform_portal_updated_at', current_time('mysql'));
                if (class_exists('APIPlatform_Developer_Portal_Query')) {
                    $settings = APIPlatform_Developer_Portal_Query::get_settings($api->ID);
                    $settings['visibility'] = 'private';
                    $settings['updated_at'] = current_time('mysql');
                    APIPlatform_Developer_Portal_Query::sync_registry($api->ID, $settings);
                }
            } else {
                APIPlatform_Lifecycle_Policy::ensure_state($api->ID, $user_id);
            }
        }

        $api_json = $response_json;

        update_post_meta($api->ID, 'api_json', $api_json);

        update_post_meta(

            $api->ID,

            'api_logic',

            wp_json_encode([

                'steps' => [

                    [

                        'type' => 'response',

                        'data' => $response_data
                    ]
                ]
            ])
        );

        $this->debug_log('[API JSON VALIDATION] API ID: ' . $api->ID);
        $this->debug_log('[API JSON VALIDATION] User ID: ' . $user_id);
        $this->debug_log('[API JSON VALIDATION] Validation Result: success');
        $this->debug_log('[API JSON SAVE] API ID: ' . $api->ID);
        $this->debug_log('[API JSON SAVE] User ID: ' . $user_id);
        $this->debug_log('[API JSON SAVE] Save Success');

        $portal_notice = '';

        if (
            class_exists('APIPlatform_Developer_Portal_Publishing') &&
            !empty($_POST['apiplatform_portal_settings_present'])
        ){

            $portal_result = APIPlatform_Developer_Portal_Publishing::save_from_post($api, $user_id);

            if (empty($portal_result['ok'])) {

                return $this->alert('error', $portal_result['message'] ?? 'Developer Portal settings could not be saved.');
            }

            $portal_notice = ' ' . ($portal_result['message'] ?? '');
        }

        $this->debug_log('[API EDIT] Save success for API ID: ' . $api->ID);

        return $this->alert('success', 'API updated successfully.' . $portal_notice);
    }

    private function handle_portal_settings_save($api, $user_id){

        $portal_nonce = isset($_POST['apiplatform_portal_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_portal_nonce']))

            : '';

        $edit_nonce = isset($_POST['apiplatform_edit_api_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_edit_api_nonce']))

            : '';

        $nonce_valid = wp_verify_nonce($portal_nonce, 'apiplatform_portal_settings_' . $api->ID) ||
            wp_verify_nonce($edit_nonce, 'apiplatform_edit_api_' . $api->ID);

        if (!$nonce_valid){

            $this->debug_log('[API EDIT] Portal settings save failure: nonce verification failed');

            return $this->alert('error', 'Security check failed. Please try again.');
        }

        if (!$this->user_owns_api($api, $user_id)){

            $this->debug_log('[API EDIT] Portal settings save failure: ownership verification failed');

            return $this->alert('error', 'You cannot edit that API.');
        }

        if (!class_exists('APIPlatform_Developer_Portal_Publishing')){

            return $this->alert('error', 'Developer Portal settings are not available.');
        }

        $portal_result = APIPlatform_Developer_Portal_Publishing::save_from_post($api, $user_id);

        if (empty($portal_result['ok'])) {

            return $this->alert('error', $portal_result['message'] ?? 'Developer Portal settings could not be saved.');
        }

        return $this->alert('success', $portal_result['message'] ?? 'Developer Portal settings saved.');
    }

    private function handle_regenerate($api, $user_id){

        $nonce = isset($_POST['apiplatform_regenerate_key_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_regenerate_key_nonce']))

            : '';

        $this->debug_log('[API KEY REGENERATE] API ID: ' . $api->ID);
        $this->debug_log('[API KEY REGENERATE] User ID: ' . $user_id);

        if (!wp_verify_nonce($nonce, 'apiplatform_regenerate_key_' . $api->ID)){

            $this->debug_log('[API KEY REGENERATE] Failure: nonce verification failed');

            return $this->alert('error', 'Security check failed. Please try again.');
        }

        if (!$this->user_can_api($api, $user_id, 'apis.manage_keys')){

            $this->debug_log('[API KEY REGENERATE] Failure: ownership verification failed');

            return $this->alert('error', 'You cannot regenerate that API key.');
        }

        $old_key = $this->get_primary_api_key($api->ID);

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            $this->debug_log('[API KEY REGENERATE] Failure: secure key storage unavailable');
            return $this->alert('error', 'API key regeneration failed.');
        }

        $new_key = $this->generate_api_key();
        $keys = [apiplatform_create_hashed_key_entry($new_key, 'Default Key')];
        $keys_updated = update_post_meta($api->ID, 'api_keys', $keys);

        if (

            $keys_updated === false ||

            get_post_meta($api->ID, 'api_keys', true) !== $keys
        ){

            $this->debug_log('[API KEY REGENERATE] Failure: storage verification failed');

            return $this->alert('error', 'API key regeneration failed.');
        }

        $display_updated = update_post_meta($api->ID, 'api_key', function_exists('apiplatform_mask_api_key') ? apiplatform_mask_api_key($new_key) : '');

        if ($display_updated === false || !get_post_meta($api->ID, 'api_key', true)) {
            $this->debug_log('[API KEY REGENERATE] Failure: display metadata storage failed');
            return $this->alert('error', 'API key regeneration failed.');
        }

        $this->debug_log('[API KEY REGENERATE] Old key replaced: ' . ($old_key ? 'yes' : 'no'));
        $this->debug_log('[API KEY REGENERATE] New key generated: created');
        $this->debug_log('[API KEY REGENERATE] Success');

        return $this->alert(
            'success',
            function_exists('apiplatform_render_one_time_api_key_notice')
                ? apiplatform_render_one_time_api_key_notice(
                    'API key regenerated successfully.',
                    $new_key,
                    function_exists('apiplatform_public_gateway_url')
                        ? apiplatform_public_gateway_url($api)
                        : (
                            class_exists('APIPlatform_Routes')
                                ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name)
                                : rest_url('platform/v1/api/' . $api->post_name)
                        )
                )
                : 'API key regenerated successfully. Copy this API key now. For security, you won\'t be able to view it again: <code>' . esc_html($new_key) . '</code>'
        );
    }

    private function handle_delete($api, $user_id){

        $nonce = isset($_POST['apiplatform_delete_api_nonce'])

            ? sanitize_text_field(wp_unslash($_POST['apiplatform_delete_api_nonce']))

            : '';

        $confirmed = !empty($_POST['confirm_delete']);

        $this->debug_log('[API DELETE] API ID: ' . $api->ID);
        $this->debug_log('[API DELETE] User ID: ' . $user_id);

        if (!wp_verify_nonce($nonce, 'apiplatform_delete_api_' . $api->ID)){

            $this->debug_log('[API DELETE] Failure: nonce verification failed');

            return $this->alert('error', 'Security check failed. Please try again.');
        }

        if (!$confirmed){

            $this->debug_log('[API DELETE] Failure: confirmation missing');

            return $this->alert('error', 'Confirm deletion before deleting this API.');
        }

        if (!$this->user_can_api($api, $user_id, 'apis.delete')){

            $this->debug_log('[API DELETE] Failure: ownership verification failed');

            return $this->alert('error', 'You cannot delete that API.');
        }

        $deleted = wp_delete_post($api->ID, true);

        if (!$deleted){

            $this->debug_log('[API DELETE] Failure: delete returned false');

            return $this->alert('error', 'API deletion failed.');
        }

        $this->debug_log('[API DELETE] Success');

        return true;
    }

    private function prepare_view_data($api, $user_id){

        $slug = $api->post_name;

        $api_key = $this->get_primary_api_key($api->ID);

        $endpoint_url = function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api)
            : (
                class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $slug)
                    : rest_url('platform/v1/api/' . $slug)
            );

        $response_json = $this->submitted_response_json !== null

            ? $this->submitted_response_json

            : $this->get_response_json($api->ID);

        $data = [

            'api' => [

                'id' => $api->ID,

                'name' => get_the_title($api),

                'slug' => $slug,

                'description' => $api->post_content,

                'status' => get_post_meta($api->ID, 'apiplatform_status', true) ?: 'active',

                'api_key' => $api_key,

                'endpoint_url' => $endpoint_url,

                'request_url' => '',

                'response_json' => $response_json,

                'response_json_error' => $this->response_json_error,

                'created_date' => get_the_date('', $api)
            ],

            'usage' => $this->get_usage($api->ID),

            'edit_nonce' => wp_create_nonce('apiplatform_edit_api_' . $api->ID),

            'regenerate_nonce' => wp_create_nonce('apiplatform_regenerate_key_' . $api->ID),

            'delete_nonce' => wp_create_nonce('apiplatform_delete_api_' . $api->ID),

            'keys_url' => $this->keys_page_url(),

            'test_url' => $this->test_api_url($api->ID),

            'docs_url' => $this->documentation_url($api->ID),

            'history_url' => $this->request_history_url($api->ID)
        ];

        if (class_exists('APIPlatform_Developer_Portal_Publishing')) {

            $data['portal'] = APIPlatform_Developer_Portal_Publishing::settings_for_view($api->ID);
        }

        return $data;
    }

    private function get_usage($api_id){

        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        $last_request = $wpdb->get_var(

            $wpdb->prepare(

                "SELECT created_at FROM $table WHERE api_id = %d ORDER BY created_at DESC LIMIT 1",

                $api_id
            )
        );

        return [

            'total_requests' => (int) get_post_meta($api_id, 'api_usage', true),

            'last_request_date' => $last_request ?: 'Never',

            'status' => get_post_meta($api_id, 'apiplatform_status', true) ?: 'active'
        ];
    }

    private function get_response_json($api_id){

        $json = get_post_meta($api_id, 'api_json', true);

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API JSON LOAD] API ID: ' . $api_id);
            error_log('[API JSON LOAD] Has Stored JSON: ' . ($json ? 'YES' : 'NO'));
        }

        if (!$json){

            return "{\n    \"message\": \"Hello\"\n}";
        }

        $decoded = json_decode($json, true);

        if (

            json_last_error() === JSON_ERROR_NONE &&

            is_array($decoded)
        ){

            return $json;
        }

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log('[API JSON LOAD] Invalid Stored JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    private function usage_cards(array $usage){

        return [

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Total Requests',

                    'value' => number_format((int) $usage['total_requests'])
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Last Request',

                    'value' => $usage['last_request_date']
                ]
            ),

            APIPlatform_Renderer::component(

                'stat',

                [

                    'label' => 'Status',

                    'value' => ucfirst($usage['status'])
                ]
            )
        ];
    }

    private function get_requested_api_id(){

        if (isset($_POST['api_id'])){

            return absint($_POST['api_id']);
        }

        if (isset($_GET['api_id'])){

            return absint($_GET['api_id']);
        }

        return 0;
    }

    private function user_owns_api($api, $user_id){
        return $this->user_can_api($api, $user_id, 'apis.edit');
    }

    private function user_can_api($api, $user_id, $permission){
        return $api &&
            $api->post_type === 'user_api' &&
            (
                class_exists('APIPlatform_Ownership_Service')
                    ? APIPlatform_Ownership_Service::can($user_id, $api->ID, $permission)
                    : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'))
            );
    }

    private function slug_is_unique($api_id, $user_id, $slug){

        $existing = get_posts([

            'post_type' => 'user_api',

            'name' => $slug,

            'numberposts' => 1,

            'post_status' => ['publish', 'draft', 'pending', 'private'],

            'no_found_rows' => true,

            'fields' => 'ids'
        ]);

        return empty($existing) || (int) $existing[0] === (int) $api_id;
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

        do {
            if (

                class_exists('APIPlatform_Core') &&

                method_exists('APIPlatform_Core', 'generate_key')
            ){

                $key = function_exists('apiplatform_generate_api_key_secret')
                    ? apiplatform_generate_api_key_secret()
                    : APIPlatform_Core::generate_key('apk_live_');
            } else {

                $key = function_exists('apiplatform_generate_api_key_secret')
                    ? apiplatform_generate_api_key_secret()
                    : 'apk_live_' . wp_generate_password(48, false, false);
            }
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

    private function keys_page_url(){

        return current_user_can('manage_options')

            ? add_query_arg('apipage', 'keys', (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))

            : site_url('/keys');
    }

    private function test_api_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(['apipage' => 'test-api', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))

            : add_query_arg('api_id', absint($api_id), site_url('/test-api'));
    }

    private function documentation_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(['apipage' => 'api-documentation', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))

            : add_query_arg('api_id', absint($api_id), site_url('/api-documentation'));
    }

    private function request_history_url($api_id){

        return current_user_can('manage_options')

            ? add_query_arg(['apipage' => 'request-history', 'api_id' => absint($api_id)], (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))

            : add_query_arg('api_id', absint($api_id), site_url('/request-history'));
    }

    private function consume_notice($user_id, $api_id){

        $key = $this->notice_key($user_id, $api_id);
        $notice = get_transient($key);

        if ($notice !== false) {

            return (string) $notice;
        }

        return '';
    }

    private function notice_key($user_id, $api_id){

        return 'apiplatform_edit_api_notice_' . absint($user_id) . '_' . absint($api_id);
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

    private function render_page($content){

        return APIPlatform_Renderer::component(

            'section',

            [

                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [

                        'items' => [

                            '<h1>Edit API</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );
    }

    private function alert($type, $content){

        return APIPlatform_Renderer::component(

            'alert',

            [

                'type' => $type,

                'content' => $content
            ]
        );
    }

    private function debug_log($message){

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
