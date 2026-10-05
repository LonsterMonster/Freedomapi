<?php
if (!defined('ABSPATH')) exit;

function apiplatform_run_automations($user_id, $context = []){

    global $wpdb;

    $table = $wpdb->prefix . 'apiplatform_rules';

    // 🔥 SAFE CHECK
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table){
        return;
    }

    $rules = $wpdb->get_results("SELECT * FROM $table");

    foreach($rules as $rule){

        $match = false;

        switch($rule->condition_key){

            case 'credits_below':
                if (($context['credits'] ?? 0) <= $rule->condition_value){
                    $match = true;
                }
                break;

            case 'status_code':
                if (($context['status'] ?? 0) == $rule->condition_value){
                    $match = true;
                }
                break;

            case 'response_time':
                if (($context['response_time'] ?? 0) > $rule->condition_value){
                    $match = true;
                }
                break;
        }

        if (!$match) continue;

        switch($rule->action_type){

            case 'notify':
                apiplatform_notify($user_id, $rule->action_data, ['email','push']);
                break;

            case 'alert':
                apiplatform_create_alert($user_id, $rule->action_data);
                break;
        }
    }
}
