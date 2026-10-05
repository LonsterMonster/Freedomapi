<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Create {

    private $notice = '';

    private $form = [];

    public function __construct(){

        add_shortcode(

            'api_create_page',

            [$this, 'render']
        );

        add_action(

            'template_redirect',

            [$this, 'maybe_handle_create']
        );
    }

    public function render(){

        /*
        |--------------------------------------------------------------------------
        | Assets
        |--------------------------------------------------------------------------
        */

        APIPlatform_Frontend_Assets::enqueue_create();
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

        $form = [

            'api_name' => '',

            'api_slug' => '',

            'description' => '',

            'response_json' => "{\n    \"message\": \"Hello\"\n}",

            'response_json_error' => '',

            'owner_type' => sanitize_key(get_user_meta($user_id, 'apiplatform_selected_owner_type', true) ?: 'personal'),

            'owner_id' => absint(get_user_meta($user_id, 'apiplatform_selected_owner_id', true) ?: $user_id),

            'owner_options' => $this->owner_options($user_id)
        ];

        $requested_owner = $this->requested_owner_from_query($user_id);
        if ($requested_owner) {
            $form['owner_type'] = $requested_owner['owner_type'];
            $form['owner_id'] = $requested_owner['owner_id'];
        }

        if (!empty($this->form)) {

            $form = $this->form;
        }

        /*
        |--------------------------------------------------------------------------
        | Success Notice
        |--------------------------------------------------------------------------
        */

        $notice = $this->notice;

        $success_notice_key = $this->success_notice_key($user_id);
        $success_notice = get_transient($success_notice_key);

        $this->debug_one_time_notice(
            $success_notice_key,
            $success_notice !== false,
            'create'
        );

        if ($success_notice) {

            $notice = APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'success',

                    'content' => $success_notice
                ]
            );
        }

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

                '[API CREATE] Create Page User ID: ' .

                $user_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = $notice;

        /*
        |--------------------------------------------------------------------------
        | Create Form
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'create/create-form',

            [

                'form' => $form
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

                            '<h1>Create API</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );

        if ($success_notice !== false) {

            $this->schedule_notice_delete($success_notice_key);
        }

        return $page;
    }

    public function maybe_handle_create(){

        if (!isset($_POST['apiplatform_create_api'])) {
            return;
        }

        if (!is_user_logged_in()) {
            $this->notice = APIPlatform_Renderer::component(
                'alert',
                [
                    'type' => 'error',
                    'content' => 'Login required'
                ]
            );
            return;
        }

        $user_id = get_current_user_id();
        $result = $this->handle_create($user_id);

        if (!$result['success']) {
            $this->form = $result['form'];
            $this->notice = APIPlatform_Renderer::component(
                'alert',
                [
                    'type' => 'error',
                    'content' => $result['message']
                ]
            );
            return;
        }

        set_transient(
            $this->success_notice_key($user_id),
            function_exists('apiplatform_render_one_time_api_key_notice')
                ? apiplatform_render_one_time_api_key_notice('API created successfully.', $result['api_key'], $result['endpoint_url'] ?? '')
                : 'API created successfully. Copy this API key now. For security, you won\'t be able to view it again: <code>' . esc_html($result['api_key']) . '</code>',
            60
        );

        wp_safe_redirect($this->clean_redirect_url($result['api_id'] ?? 0));
        exit;
    }

    private function handle_create($user_id){

        $this->debug_log(

            '[API CREATE] User ID: ' .

            $user_id
        );

        $form = [

            'api_name' => isset($_POST['api_name'])

                ? sanitize_text_field(

                    wp_unslash($_POST['api_name'])
                )

                : '',

            'api_slug' => isset($_POST['api_slug'])

                ? sanitize_title(

                    wp_unslash($_POST['api_slug'])
                )

                : '',

            'description' => isset($_POST['description'])

                ? sanitize_textarea_field(

                    wp_unslash($_POST['description'])
                )

                : '',

            'response_json' => isset($_POST['response_json'])

                ? wp_unslash($_POST['response_json'])

                : '',

            'owner_type' => isset($_POST['owner_type'])

                ? sanitize_key(wp_unslash($_POST['owner_type']))

                : 'personal',

            'owner_id' => isset($_POST['owner_id'])

                ? absint($_POST['owner_id'])

                : $user_id,

            'response_json_error' => ''
        ];
        $form['owner_options'] = $this->owner_options($user_id);
        if (isset($_POST['owner_context'])) {
            $owner_context = explode(':', sanitize_text_field(wp_unslash($_POST['owner_context'])), 2);
            $form['owner_type'] = sanitize_key($owner_context[0] ?? 'personal');
            $form['owner_id'] = absint($owner_context[1] ?? $user_id);
        }

        if (

            !isset($_POST['apiplatform_create_api_nonce']) ||

            !wp_verify_nonce(

                sanitize_text_field(

                    wp_unslash($_POST['apiplatform_create_api_nonce'])
                ),

                'apiplatform_create_api'
            )
        ){

            $this->debug_log(

                '[API CREATE] Creation Failed: nonce validation'
            );

            return [

                'success' => false,

                'message' => 'Security check failed.',

                'form' => $form,

                'api_key' => ''
            ];
        }

        $owner = $this->resolve_owner($user_id, $form['owner_type'], $form['owner_id']);
        if (is_wp_error($owner)) {
            return [

                'success' => false,

                'message' => $this->owner_error_message($owner),

                'form' => $form,

                'api_key' => ''
            ];
        }
        $form['owner_type'] = $owner['owner_type'];
        $form['owner_id'] = $owner['owner_id'];

        if (!$form['api_name']){

            $this->debug_log(

                '[API CREATE] Creation Failed: missing API name'
            );

            return [

                'success' => false,

                'message' => 'API name is required.',

                'form' => $form,

                'api_key' => ''
            ];
        }

        if (!$form['api_slug']){

            $form['api_slug'] = sanitize_title(

                $form['api_name']
            );
        }

        $this->debug_log(

            '[API CREATE] API Name: ' .

            $form['api_name']
        );

        $this->debug_log(

            '[API CREATE] Generated Slug: ' .

            $form['api_slug']
        );

        if (!$form['api_slug']){

            $this->debug_log(

                '[API CREATE] Creation Failed: missing slug'
            );

            return [

                'success' => false,

                'message' => 'API slug is required.',

                'form' => $form,

                'api_key' => ''
            ];
        }

        if ($this->user_slug_exists($user_id, $form['api_slug'])){

            $this->debug_log(

                '[API CREATE] Creation Failed: duplicate slug ' .

                $form['api_slug']
            );

            return [

                'success' => false,

                'message' => 'You already have an API with that slug.',

                'form' => $form,

                'api_key' => ''
            ];
        }

        if (function_exists('apiplatform_get_plan_limits')) {
            $limits = apiplatform_get_plan_limits($user_id);
            $api_limit = $limits['api_limit'] ?? INF;

            if (!APIPlatform_Membership_Panel::is_unlimited($api_limit)) {
                $api_count = count(get_posts([
                    'post_type' => 'user_api',
                    'author' => $user_id,
                    'post_status' => ['publish', 'draft', 'pending', 'private'],
                    'fields' => 'ids',
                    'numberposts' => -1,
                    'no_found_rows' => true,
                ]));

                if ($api_count >= (int) $api_limit) {
                    return [
                        'success' => false,
                        'message' => 'You have reached your API creation limit.',
                        'form' => $form,
                        'api_key' => ''
                    ];
                }
            }
        }

        $response_data = json_decode(

            $form['response_json'],

            true
        );

        if (

            json_last_error() !== JSON_ERROR_NONE ||

            !is_array($response_data)
        ){

            $json_error = json_last_error_msg();

            $form['response_json_error'] = 'Response JSON is invalid: ' . $json_error;

            $this->debug_log(

                '[API JSON VALIDATION] API ID: pending'
            );

            $this->debug_log(

                '[API JSON VALIDATION] User ID: ' .

                $user_id
            );

            $this->debug_log(

                '[API JSON VALIDATION] Validation Error: ' .

                $json_error
            );

            $this->debug_log(

                '[API JSON SAVE] Save Failure: invalid response JSON'
            );

            return [

                'success' => false,

                'message' => $form['response_json_error'],

                'form' => $form,

                'api_key' => ''
            ];
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            $this->debug_log('[API CREATE] Creation Failed: secure key storage unavailable');
            return [
                'success' => false,
                'message' => 'Unable to securely create API key.',
                'form' => $form,
                'api_key' => ''
            ];
        }

        $api_key = $this->generate_api_key();

        $this->debug_log(

            '[API CREATE] Generated API Key: created'
        );

        $created_at = current_time('mysql');

        $api_id = wp_insert_post(

            [

                'post_title' => $form['api_name'],

                'post_name' => $form['api_slug'],

                'post_content' => $form['description'],

                'post_type' => 'user_api',

                'post_status' => 'publish',

                'post_author' => $user_id
            ],

            true
        );

        if (is_wp_error($api_id)){

            $this->debug_log(

                '[API CREATE] Creation Failed: ' .

                $api_id->get_error_message()
            );

            return [

                'success' => false,

                'message' => $api_id->get_error_message(),

                'form' => $form,

                'api_key' => ''
            ];
        }

        $display_key = function_exists('apiplatform_mask_api_key') ? apiplatform_mask_api_key($api_key) : '';
        $display_updated = update_post_meta(

            $api_id,

            'api_key',

            $display_key
        );

        $this->debug_storage(

            $api_id,

            'api_key',

            $api_key
        );

        $default_keys = [apiplatform_create_hashed_key_entry($api_key, 'Default Key')];

        $keys_updated = update_post_meta(

            $api_id,

            'api_keys',

            $default_keys
        );

        if (
            $display_updated === false ||
            $keys_updated === false ||
            get_post_meta($api_id, 'api_keys', true) !== $default_keys
        ) {
            $this->debug_log('[API CREATE] Creation Failed: secure key storage failed');
            return [
                'success' => false,
                'message' => 'Unable to securely create API key.',
                'form' => $form,
                'api_key' => '',
                'api_id' => $api_id
            ];
        }

        $this->debug_storage(

            $api_id,

            'api_keys',

            $default_keys
        );

        update_post_meta(

            $api_id,

            'apiplatform_created_at',

            $created_at
        );

        $this->debug_storage($api_id, 'apiplatform_created_at', $created_at);
        if (class_exists('APIPlatform_Ownership_Service')) {
            APIPlatform_Ownership_Service::assign($api_id, $owner['owner_type'], $owner['owner_id'], $user_id);
        } else {
            update_post_meta($api_id, 'apiplatform_owner_type', 'personal');
            update_post_meta($api_id, 'apiplatform_owner_id', $user_id);
            update_post_meta($api_id, 'apiplatform_created_by_user_id', $user_id);
        }

        update_post_meta(

            $api_id,

            'apiplatform_status',

            'active'
        );

        $this->debug_storage($api_id, 'apiplatform_status', 'active');

        update_post_meta($api_id, 'apiplatform_lifecycle_status', 'private');
        update_post_meta($api_id, 'apiplatform_lifecycle_changed_at', current_time('mysql'));

        $api_json = $form['response_json'];

        update_post_meta(

            $api_id,

            'api_json',

            $api_json
        );

        $this->debug_storage($api_id, 'api_json', $api_json);

        $this->debug_log('[API JSON VALIDATION] API ID: ' . $api_id);
        $this->debug_log('[API JSON VALIDATION] User ID: ' . $user_id);
        $this->debug_log('[API JSON VALIDATION] Validation Result: success');
        $this->debug_log('[API JSON SAVE] API ID: ' . $api_id);
        $this->debug_log('[API JSON SAVE] User ID: ' . $user_id);
        $this->debug_log('[API JSON SAVE] Save Success');

        $api_logic = wp_json_encode([

            'steps' => [

                [

                    'type' => 'response',

                    'data' => $response_data
                ]
            ]
        ]);

        update_post_meta(

            $api_id,

            'api_logic',

            $api_logic
        );

        $this->debug_storage(

            $api_id,

            'api_logic',

            $api_logic
        );

        $this->debug_log(

            '[API CREATE] Post ID: ' .

            $api_id
        );

        $this->debug_log(

            '[API CREATE] Creation Successful'
        );

        return [

            'success' => true,

            'message' => 'API created successfully.',

            'form' => $form,

            'api_key' => $api_key,

            'api_id' => $api_id,

            'endpoint_url' => function_exists('apiplatform_public_gateway_url')
                ? apiplatform_public_gateway_url($api_id)
                : (
                    class_exists('APIPlatform_Routes')
                        ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', get_current_user_id()), get_post_field('post_name', $api_id))
                        : rest_url('platform/v1/api/' . get_post_field('post_name', $api_id))
                )
        ];
    }

    private function success_notice_key($user_id){

        return 'apiplatform_api_created_notice_' . absint($user_id);
    }

    private function clean_redirect_url($api_id){

        $url = wp_get_referer();

        if (!$url) {
            $url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        }

        $url = remove_query_arg(
            [
                'apiplatform_create_api',
                'apiplatform_create_api_nonce',
                'api_name',
                'api_slug',
                'description',
                'response_json'
            ],
            $url
        );

        return add_query_arg(
            [
                'apiplatform_created' => 1,
                'api_id' => absint($api_id)
            ],
            $url
        );
    }

    private function user_slug_exists($user_id, $slug){

        $existing = get_posts([

            'post_type' => 'user_api',

            'name' => $slug,

            'numberposts' => 1,

            'post_status' => ['publish', 'draft', 'pending', 'private'],

            'no_found_rows' => true,

            'fields' => 'ids'
        ]);

        return !empty($existing);
    }

    private function owner_options($user_id){
        $options = [
            ['owner_type' => 'personal', 'owner_id' => absint($user_id), 'label' => 'Personal Workspace'],
        ];

        if (class_exists('APIPlatform_Organization_Service') && class_exists('APIPlatform_Organization_Permissions')) {
            foreach (APIPlatform_Organization_Service::list_for_user($user_id) as $organization) {
                if (APIPlatform_Organization_Permissions::can($user_id, $organization['id'], 'apis.create')) {
                    $options[] = [
                        'owner_type' => 'organization',
                        'owner_id' => absint($organization['id']),
                        'label' => $organization['name'],
                    ];
                }
            }
        }

        return $options;
    }

    private function requested_owner_from_query($user_id){
        $owner_type = sanitize_key(wp_unslash($_GET['owner_type'] ?? ''));
        $owner_id = absint($_GET['owner_id'] ?? 0);

        if (isset($_GET['owner_context'])) {
            $owner_context = explode(':', sanitize_text_field(wp_unslash($_GET['owner_context'])), 2);
            $owner_type = sanitize_key($owner_context[0] ?? $owner_type);
            $owner_id = absint($owner_context[1] ?? $owner_id);
        }

        if (!$owner_type && !$owner_id) {
            return null;
        }

        $owner = $this->resolve_owner($user_id, $owner_type, $owner_id);
        return is_wp_error($owner) ? null : $owner;
    }

    private function owner_error_message($error){
        if (!is_wp_error($error) || $error->get_error_code() !== 'organization_api_limit_reached') return is_wp_error($error) ? $error->get_error_message() : 'The API could not be created. Please try again.';
        $data = (array) $error->get_error_data('organization_api_limit_reached');
        $organization_id = absint($data['organization_id'] ?? 0);
        $organization = $organization_id ? APIPlatform_Organization_Service::find($organization_id) : null;
        $usage = max(0, absint($data['usage'] ?? 0));
        $limit = APIPlatform_Membership_Panel::display_limit($data['limit'] ?? 0, true);
        $plan_label = APIPlatform_Membership_Panel::get_plan_label($data['plan'] ?? 'free');
        $definition = APIPlatform_Error_Codes::get_by_key('organization_api_limit_reached');
        $name = $organization['name'] ?? 'This organization';
        $message = '<strong>' . esc_html($definition['title']) . '</strong><br>' . esc_html($name . ' is using ' . $usage . ' of ' . $limit . ' APIs on the ' . $plan_label . '.');
        if (class_exists('APIPlatform_Plan_Simulation') && $organization_id && APIPlatform_Plan_Simulation::organization_is_simulated_for_current_user($organization_id)) $message .= '<br>' . esc_html('This limit is active while testing the ' . $plan_label . '. Actual membership remains ' . APIPlatform_Membership_Panel::get_plan_label(APIPlatform_Membership_Panel::get_user_plan(get_current_user_id())) . '.');
        return $message . '<br>' . esc_html($definition['resolution']) . '<br><small>Error code: ' . esc_html('FAPI-' . $definition['code']) . '</small>';
    }

    private function resolve_owner($user_id, $owner_type, $owner_id){
        $owner_type = sanitize_key($owner_type) === 'organization' ? 'organization' : 'personal';
        $owner_id = absint($owner_id);

        if ($owner_type === 'personal') {
            return ['owner_type' => 'personal', 'owner_id' => absint($user_id)];
        }

        if (!class_exists('APIPlatform_Organization_Permissions') || !APIPlatform_Organization_Permissions::can($user_id, $owner_id, 'apis.create')) {
            return new WP_Error('invalid_owner', 'You cannot create APIs for that organization.');
        }
        if (class_exists('APIPlatform_Organization_Entitlements')) {
            $api_check = APIPlatform_Organization_Entitlements::can_consume($owner_id, 'apis', $user_id);
            if (is_wp_error($api_check)) return $api_check;
        }

        return ['owner_type' => 'organization', 'owner_id' => $owner_id];
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
                    : 'apk_live_' . wp_generate_password(

                    32,

                    false,

                    false
                );
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

    private function debug_storage($post_id, $meta_key, $value){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

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
