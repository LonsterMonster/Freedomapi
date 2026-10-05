<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
LOGGING
----------------------------------------
*/
function apiplatform_log_api_request($user_id, $api_id, $endpoint, $status, $start, array $auth_context = []){
    global $wpdb;
    $table = $wpdb->prefix . 'apiplatform_logs';

    $data = [
        'user_id'=>absint($user_id),
        'api_id'=>absint($api_id),
        'endpoint'=>sanitize_text_field($endpoint),
        'method'=>sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
        'ip'=>sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        'status'=>absint($status),
        'response_time'=>round(microtime(true) - (float) $start, 4),
        'created_at'=>current_time('mysql')
    ];

    $columns = $wpdb->get_col("DESC `{$table}`", 0);
    if (is_array($columns) && in_array('application_id', $columns, true)) {
        $data['application_id'] = !empty($auth_context['application_id']) ? absint($auth_context['application_id']) : null;
        $data['application_key_id'] = !empty($auth_context['application_key_id']) ? absint($auth_context['application_key_id']) : null;
        $data['access_id'] = !empty($auth_context['access_id']) ? absint($auth_context['access_id']) : null;
        $data['auth_type'] = sanitize_key($auth_context['auth_type'] ?? 'not_attempted') ?: 'not_attempted';
    }

    if (is_array($columns) && in_array('auth_type', $columns, true)) {
        $data['auth_type'] = sanitize_key($auth_context['auth_type'] ?? 'not_attempted') ?: 'not_attempted';
    }

    if (is_array($columns) && in_array('auth_transport', $columns, true)) {
        $data['auth_transport'] = sanitize_key($auth_context['auth_transport'] ?? '');
    }

    if (is_array($columns) && in_array('response_validation_status', $columns, true)) {
        $data['response_validation_status'] = sanitize_key($auth_context['response_validation_status'] ?? '');
    }

    if (is_array($columns) && in_array('response_validation_mode', $columns, true)) {
        $data['response_validation_mode'] = sanitize_key($auth_context['response_validation_mode'] ?? '');
    }

    if (is_array($columns) && in_array('response_validation_errors', $columns, true)) {
        $errors = $auth_context['response_validation_errors'] ?? [];
        $data['response_validation_errors'] = is_array($errors) ? wp_json_encode(array_slice($errors, 0, 10)) : '';
    }

    if (class_exists('APIPlatform_Log_Sanitizer')) {
        $request_headers = $auth_context['request_headers'] ?? [];
        $query_params = $auth_context['query_params'] ?? [];
        $request_body = $auth_context['request_body'] ?? '';
        $response_headers = $auth_context['response_headers'] ?? [];
        $response_body = $auth_context['response_body'] ?? '';
        $request_type = $auth_context['request_content_type'] ?? '';
        $response_type = $auth_context['response_content_type'] ?? '';
        if (is_array($columns) && in_array('request_headers', $columns, true)) $data['request_headers'] = wp_json_encode(APIPlatform_Log_Sanitizer::headers($request_headers));
        if (is_array($columns) && in_array('query_params', $columns, true)) $data['query_params'] = wp_json_encode(APIPlatform_Log_Sanitizer::query($query_params));
        if (is_array($columns) && in_array('request_body', $columns, true)) $data['request_body'] = wp_json_encode(APIPlatform_Log_Sanitizer::body($request_body, $request_type, APIPlatform_Log_Sanitizer::MAX_REQUEST_BODY));
        if (is_array($columns) && in_array('user_agent', $columns, true)) $data['user_agent'] = APIPlatform_Log_Sanitizer::user_agent($auth_context['user_agent'] ?? '');
        if (is_array($columns) && in_array('response_headers', $columns, true)) $data['response_headers'] = wp_json_encode(APIPlatform_Log_Sanitizer::headers($response_headers));
        if (is_array($columns) && in_array('response_body', $columns, true)) $data['response_body'] = wp_json_encode(APIPlatform_Log_Sanitizer::body($response_body, $response_type, APIPlatform_Log_Sanitizer::MAX_RESPONSE_BODY));
    }


    if (is_array($columns) && in_array('error_code', $columns, true)) $data['error_code'] = sanitize_key($auth_context['error_code'] ?? '');
    if (is_array($columns) && in_array('error_message', $columns, true)) $data['error_message'] = sanitize_text_field($auth_context['error_message'] ?? '');

    if (is_array($columns) && in_array('request_id', $columns, true)) {
        $data['request_id'] = !empty($auth_context['request_id'])
            ? sanitize_text_field($auth_context['request_id'])
            : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('req_', true));
    }

    if (is_array($columns) && in_array('gateway_version', $columns, true)) {
        $data['gateway_version'] = sanitize_text_field($auth_context['gateway_version'] ?? '');
    }

    if (is_array($columns) && in_array('gateway_endpoint', $columns, true)) {
        $data['gateway_endpoint'] = sanitize_text_field($auth_context['gateway_endpoint'] ?? '');
    }

    if (is_array($columns) && in_array('cache_result', $columns, true)) {
        $data['cache_result'] = sanitize_key($auth_context['cache_result'] ?? '');
    }

    if (is_array($columns) && in_array('response_size', $columns, true)) {
        $data['response_size'] = absint($auth_context['response_size'] ?? 0);
    }

    $wpdb->insert($table, $data);

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('[API STORAGE] Request Log Table: ' . $table);
        error_log('[API STORAGE] Request Log API ID: ' . $data['api_id']);
        error_log('[API STORAGE] Request Log Status: ' . $data['status']);
        error_log('[API STORAGE] Request Auth Type: ' . ($data['auth_type'] ?? 'not_attempted')); 
        error_log('[API STORAGE] Request Log Result: ' . ((int) $status >= 400 ? 'failed' : 'success') . ' (write=' . ($wpdb->insert_id ? 'success' : 'failure') . ')');
    }
}

function apiplatform_log_request_advanced($api_id,$status,$start){
    apiplatform_log_api_request(
        get_post_field('post_author', $api_id),
        $api_id,
        get_post_field('post_name', $api_id),
        $status,
        $start
    );
}

/*
----------------------------------------
CACHE
----------------------------------------
*/
function apiplatform_set_cache($key,$data,$ttl=60){
    set_transient($key,$data,$ttl);
}
function apiplatform_get_cache($key){
    return get_transient($key);
}

/*
----------------------------------------
TRANSACTIONS
----------------------------------------
*/
function apiplatform_add_transaction($user_id,$amount,$type='usage',$desc='',$api_id=0){
    global $wpdb;
    $wpdb->insert($wpdb->prefix.'apiplatform_transactions',[
        'user_id'=>$user_id,
        'api_id'=>$api_id,
        'amount'=>$amount,
        'type'=>$type,
        'description'=>$desc,
        'created_at'=>current_time('mysql')
    ]);
}

function apiplatform_get_transactions($user_id,$limit=20){
    global $wpdb;
    $table = $wpdb->prefix.'apiplatform_transactions';
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id=%d ORDER BY created_at DESC LIMIT %d",
            $user_id,$limit
        )
    );
}




