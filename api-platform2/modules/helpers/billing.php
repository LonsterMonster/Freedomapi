<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
BILLING CALCULATION
----------------------------------------
*/
function apiplatform_calculate_bill($user_id){

    if (user_can($user_id,'manage_options')) return 0;

    global $wpdb;

    $table = $wpdb->prefix.'apiplatform_transactions';

    // sum all API usage transactions this month
    $month_start = date('Y-m-01 00:00:00');

    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(amount) FROM $table 
         WHERE user_id=%d AND type='api_request' AND created_at >= %s",
        $user_id,
        $month_start
    ));

    $total = abs((int)$total);

    $cost_per_1k = 0.50;

    return round(($total / 1000) * $cost_per_1k, 2);
}

/*
----------------------------------------
TRACK BILLING USAGE (REQUIRED)
----------------------------------------
*/
function apiplatform_track_billing_usage($user_id){

    $month = date('Ym');
    $key   = "apiplatform_usage_{$month}";

    $usage = (int)get_user_meta($user_id,$key,true);
    update_user_meta($user_id,$key,$usage + 1);
}
