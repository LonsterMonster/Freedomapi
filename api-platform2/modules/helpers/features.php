<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'freedom_api_register_feature_cpt');
add_action('init', 'freedom_api_register_feature_meta');

function freedom_api_register_feature_cpt(){

    register_post_type('freedom_feature', [

        'label' => 'Freedom Features',
        'labels' => [
            'name' => 'Freedom Features',
            'singular_name' => 'Freedom Feature',
            'add_new_item' => 'Add New Freedom Feature',
            'edit_item' => 'Edit Freedom Feature'
        ],
        'public' => true,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'rewrite' => false,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'capability_type' => 'post'
    ]);

    freedom_api_feature_debug('[FEATURE SYSTEM] freedom_feature CPT registered');
}

function freedom_api_register_feature_meta(){

    $fields = [
        'feature_slug' => 'sanitize_title',
        'feature_icon' => 'sanitize_text_field',
        'feature_plan' => 'sanitize_key',
        'feature_status' => 'sanitize_key',
        'feature_description' => 'sanitize_textarea_field',
        'feature_release_notes' => 'sanitize_textarea_field',
        'feature_enabled' => 'rest_sanitize_boolean'
    ];

    foreach($fields as $key => $sanitize_callback){

        register_post_meta('freedom_feature', $key, [
            'single' => true,
            'type' => $key === 'feature_enabled' ? 'boolean' : 'string',
            'show_in_rest' => true,
            'sanitize_callback' => $sanitize_callback,
            'auth_callback' => function(){
                return current_user_can('edit_posts');
            }
        ]);
    }

    freedom_api_feature_debug('[FEATURE SYSTEM] freedom_feature meta registered');
}

function freedom_api_get_features(){

    $features = get_posts([
        'post_type' => 'freedom_feature',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => -1,
        'orderby' => 'menu_order title',
        'order' => 'ASC',
        'no_found_rows' => true
    ]);

    freedom_api_feature_debug('[FEATURE SYSTEM] feature queried count: ' . count($features));

    return array_map('freedom_api_prepare_feature', $features);
}

function freedom_api_get_available_features($user_id){

    $available = [];

    foreach(freedom_api_get_features() as $feature){

        if (freedom_api_has_feature($user_id, $feature['slug'])){
            $available[] = $feature;
        }
    }

    return $available;
}

function freedom_api_has_feature($user_id, $feature_slug){

    $feature = freedom_api_get_feature_by_slug($feature_slug);

    if (!$feature){
        freedom_api_feature_debug('[FEATURE SYSTEM] feature locked missing: ' . $feature_slug);
        return false;
    }

    if (!$feature['enabled']){
        freedom_api_feature_debug('[FEATURE SYSTEM] feature locked disabled: ' . $feature_slug);
        return false;
    }

    $plan_allowed = freedom_api_user_plan_allows_feature($user_id, $feature['plan']);

    freedom_api_feature_debug('[FEATURE SYSTEM] plan validation result for ' . $feature_slug . ': ' . ($plan_allowed ? 'allowed' : 'denied'));

    if (!$plan_allowed){
        freedom_api_feature_debug('[FEATURE SYSTEM] feature locked plan: ' . $feature_slug);
        return false;
    }

    $drip_unlocked = freedom_api_drip_allows_feature($user_id, $feature['post_id']);

    if ($drip_unlocked){
        freedom_api_feature_debug('[FEATURE SYSTEM] feature unlocked: ' . $feature_slug);
        return true;
    }

    freedom_api_feature_debug('[FEATURE SYSTEM] feature locked drip: ' . $feature_slug);

    return false;
}

function freedom_api_prepare_feature($post){

    $slug = get_post_meta($post->ID, 'feature_slug', true);

    if (!$slug){
        $slug = $post->post_name;
    }

    return [
        'post_id' => $post->ID,
        'title' => get_the_title($post),
        'slug' => $slug,
        'icon' => get_post_meta($post->ID, 'feature_icon', true) ?: 'Feature',
        'plan' => get_post_meta($post->ID, 'feature_plan', true) ?: 'free',
        'status' => get_post_meta($post->ID, 'feature_status', true) ?: 'coming_soon',
        'description' => get_post_meta($post->ID, 'feature_description', true) ?: wp_strip_all_tags($post->post_content),
        'release_notes' => get_post_meta($post->ID, 'feature_release_notes', true),
        'enabled' => (bool) get_post_meta($post->ID, 'feature_enabled', true)
    ];
}

function freedom_api_get_feature_by_slug($feature_slug){

    foreach(freedom_api_get_features() as $feature){
        if ($feature['slug'] === $feature_slug){
            return $feature;
        }
    }

    return null;
}

function freedom_api_user_plan_allows_feature($user_id, $required_plan){

    if (user_can($user_id, 'manage_options')){
        return true;
    }

    $required_plan = $required_plan ?: 'free';

    if ($required_plan === 'free'){
        return true;
    }

    $plan = function_exists('apiplatform_get_user_plan')
        ? apiplatform_get_user_plan()
        : 'free';

    $rank = [
        'free' => 0,
        'pro' => 10,
        'premium' => 20,
        'admin' => 99
    ];

    return ($rank[$plan] ?? 0) >= ($rank[$required_plan] ?? 0);
}

function freedom_api_drip_allows_feature($user_id, $post_id){

    if (user_can($user_id, 'manage_options')){
        freedom_api_feature_debug('[FEATURE SYSTEM] drip content detected admin bypass');
        return true;
    }

    if (!freedom_api_armember_drip_available()){
        freedom_api_feature_debug('[FEATURE SYSTEM] drip content detected: no ARMember drip API found');
        return get_post_status($post_id) === 'publish';
    }

    freedom_api_feature_debug('[FEATURE SYSTEM] drip content detected');

    $checks = [
        'arm_check_content_hasaccess',
        'arm_check_post_hasaccess',
        'arm_check_user_post_access'
    ];

    foreach($checks as $function){
        if (function_exists($function)){
            return (bool) call_user_func($function, $post_id, $user_id);
        }
    }

    return apply_filters('freedom_api_armember_drip_feature_unlocked', get_post_status($post_id) === 'publish', $user_id, $post_id);
}

function freedom_api_armember_drip_available(){

    return function_exists('arm_check_content_hasaccess') ||
        function_exists('arm_check_post_hasaccess') ||
        function_exists('arm_check_user_post_access') ||
        has_filter('freedom_api_armember_drip_feature_unlocked') ||
        class_exists('ARM_drip_rules') ||
        class_exists('ARM_drip_content');
}

function freedom_api_feature_debug($message){

    if (defined('WP_DEBUG') && WP_DEBUG){
        error_log($message);
    }
}
