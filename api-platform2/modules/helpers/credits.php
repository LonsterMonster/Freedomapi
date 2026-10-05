<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
CREDITS SYSTEM
----------------------------------------
*/
function apiplatform_use_gamipress(){
    return get_option('apiplatform_use_gamipress') == 1;
}

function apiplatform_get_credits($user_id){

    if (apiplatform_use_gamipress() && function_exists('gamipress_get_user_points')){
        return (int) gamipress_get_user_points($user_id,'credits');
    }

    return (int)get_user_meta($user_id,'apiplatform_wallet_balance',true);
}

function apiplatform_has_credits($user_id,$cost){
    return apiplatform_get_credits($user_id) >= $cost;
}

function apiplatform_subtract_credits($user_id,$amount){

    if (apiplatform_use_gamipress() && function_exists('gamipress_deduct_points_from_user')){
        gamipress_deduct_points_from_user($user_id,$amount,'credits');
        return;
    }

    $balance = apiplatform_get_credits($user_id);
    update_user_meta($user_id,'apiplatform_wallet_balance',max(0,$balance-$amount));
}

/*
----------------------------------------
LOW CREDIT ALERT
----------------------------------------
*/
function apiplatform_check_low_credits($user_id){

    if (apiplatform_get_credits($user_id) > 10) return;

    if (get_user_meta($user_id,'apiplatform_low_credit_email',true)) return;

    $user = get_userdata($user_id);

    wp_mail(
        $user->user_email,
        'Low Credits Warning',
        'You are running low on credits. Buy more: ' . site_url('/buy-credits')
    );

    update_user_meta($user_id,'apiplatform_low_credit_email',1);
}
