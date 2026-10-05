<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
RATE LIMIT
----------------------------------------
*/
function apiplatform_check_rate_limit($user_id,$api_id){

    if (user_can($user_id,'manage_options') && !(class_exists('APIPlatform_Plan_Simulation') && APIPlatform_Plan_Simulation::is_active_for($user_id))) return ['blocked'=>false];

    $limits = apiplatform_get_plan_limits($user_id);

    if (!APIPlatform_Membership_Panel::is_unlimited($limits['request_limit']) && $limits['type']==='per_api'){
        $usage = (int)get_post_meta($api_id,'api_usage',true);
        if ($usage >= $limits['request_limit']){
            return ['blocked'=>true,'message'=>'API limit reached'];
        }
    }

    if (!APIPlatform_Membership_Panel::is_unlimited($limits['request_limit']) && $limits['type']==='global'){
        $total = apiplatform_get_total_usage($user_id);
        if ($total >= $limits['request_limit']){
            return ['blocked'=>true,'message'=>'Plan limit reached'];
        }
    }

    return ['blocked'=>false];
}

/*
----------------------------------------
FREE REQUESTS
----------------------------------------
*/
function apiplatform_get_free_requests($user_id){

    $limits = apiplatform_get_plan_limits($user_id);

    $limit = (int)$limits['free_requests'];
    $used  = (int)get_user_meta($user_id,'apiplatform_free_used',true);

    return [
        'used'=>$used,
        'remaining'=>max(0,$limit-$used),
        'limit'=>$limit
    ];
}

function apiplatform_use_free_request($user_id){
    $used = (int)get_user_meta($user_id,'apiplatform_free_used',true);
    update_user_meta($user_id,'apiplatform_free_used',$used+1);
}

/*
----------------------------------------
TOTAL USAGE
----------------------------------------
*/
function apiplatform_get_total_usage($user_id){

    $key = 'apiplatform_usage_'.$user_id;

    if (($cached = get_transient($key)) !== false) return $cached;

    $total = 0;

    foreach(get_posts([
        'post_type'=>'user_api',
        'author'=>$user_id,
        'fields'=>'ids'
    ]) as $id){
        $total += (int)get_post_meta($id,'api_usage',true);
    }

    set_transient($key,$total,60);

    return $total;
}

/*
----------------------------------------
USAGE %
----------------------------------------
*/
function apiplatform_get_usage_percent($user_id){

    $limits = apiplatform_get_plan_limits($user_id);

    if (APIPlatform_Membership_Panel::is_unlimited($limits['request_limit'])) return 0;

    $used = apiplatform_get_total_usage($user_id);

    return min(100, ($used / $limits['request_limit']) * 100);
}

/*
----------------------------------------
DAILY USAGE (LAST 7 DAYS)
----------------------------------------
*/
function apiplatform_get_daily_usage($user_id){

    global $wpdb;

    $table = $wpdb->prefix . 'apiplatform_logs';

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT DATE(created_at) as day, COUNT(*) as total
        FROM $table
        WHERE user_id = %d
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY day
        ORDER BY day ASC
    ", $user_id));

    $data = [];

    // fill last 7 days
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = 0;
    }

    foreach($results as $row){
        $data[$row->day] = (int)$row->total;
    }

    return array_values($data);
}

/*
----------------------------------------
TOP APIs
----------------------------------------
*/
function apiplatform_get_top_apis($user_id){

    $apis = get_posts([
        'post_type'=>'user_api',
        'author'=>$user_id,
        'numberposts'=>-1
    ]);

    $data = [];

    foreach($apis as $api){
        $usage = (int)get_post_meta($api->ID,'api_usage',true);

        $data[] = [
            'name' => $api->post_title,
            'usage'=> $usage
        ];
    }

    usort($data, function($a,$b){
        return $b['usage'] - $a['usage'];
    });

    return array_slice($data, 0, 5);
}
