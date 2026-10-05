<?php
if (!defined('ABSPATH')) exit;

function apiplatform_execute_logic($api_id, $params){

    $raw = get_post_meta($api_id,'api_logic',true);

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log('LOGIC RAW: redacted for API ID ' . absint($api_id));
    }

    $logic = json_decode($raw, true);

    if (!$logic || empty($logic['steps'])){
        return null;
    }

    foreach($logic['steps'] as $step){

        if ($step['type'] === 'response'){
            return $step['data'];
        }
    }

    return null;
}
