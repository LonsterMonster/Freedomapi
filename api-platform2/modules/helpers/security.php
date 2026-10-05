<?php
if (!defined('ABSPATH')) exit;

function apiplatform_is_admin(){
    return current_user_can('manage_options');
}
/*
----------------------------------------
MISC
----------------------------------------
*/
function apiplatform_is_disabled(){
    return get_option('apiplatform_disabled') == 1;
}

function apiplatform_user_disabled($user_id){
    return get_user_meta($user_id,'apiplatform_disabled',true);
}

/*
----------------------------------------
BAN CHECK (SAFE)
----------------------------------------
*/
function apiplatform_is_banned($user_id){

    // Admin always allowed
    if (user_can($user_id, 'manage_options')) {
        return false;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Check IP ban
    if (get_transient('apiplatform_ban_ip_' . $ip)) {
        return 'ip';
    }

    // Check user ban
    if (get_user_meta($user_id, 'apiplatform_banned', true)) {
        return 'user';
    }

    return false;
}
