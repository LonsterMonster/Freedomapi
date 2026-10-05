<?php
if (!defined('ABSPATH')) exit;

// The membership panel owns plan definitions. This compatibility helper owns
// only ARMember/user plan detection and legacy return-shape adaptation.
if (!class_exists('APIPlatform_Membership_Panel')) require_once dirname(__DIR__) . '/armember/class-membership-panel.php';

function apiplatform_get_user_plan($user_id = null){
    $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
    if (!$user_id) return 'free';
    if (user_can($user_id, 'manage_options')) return 'admin';
    $plans = get_user_meta($user_id, 'arm_user_plan_ids', true);
    if (!$plans || !is_array($plans)) return 'free';
    $plan_id = absint($plans[0] ?? 0);
    // ARMember membership mapping only; no plan limits live here.
    $map = [2 => 'pro', 5 => 'pro', 3 => 'premium', 6 => 'premium'];
    return $map[$plan_id] ?? 'free';
}

function apiplatform_get_plan_limits($user_id){
    $plan = APIPlatform_Membership_Panel::get_effective_user_plan($user_id);
    $definition = APIPlatform_Membership_Panel::get_plan_definition($plan);
    $limits = $definition['limits'];
    $unlimited = APIPlatform_Membership_Panel::is_unlimited($limits['requests']);
    return [
        'type' => $definition['usage_type'],
        'request_limit' => $limits['requests'],
        'free_requests' => $limits['free_requests'],
        'credits' => $limits['credits'],
        'cost_per_request' => $limits['cost_per_request'],
        'seats' => $limits['seats'],
        'api_limit' => $limits['apis'],
        'unlimited' => $unlimited,
    ];
}

function apiplatform_get_plan_status($user_id){
    if (apiplatform_get_user_plan($user_id) === 'admin') return 'admin';
    $next = (int) get_user_meta($user_id, 'apiplatform_next_billing', true);
    $now = time(); $grace = 3 * DAY_IN_SECONDS;
    if ($now <= $next) return 'active';
    if ($now <= ($next + $grace)) return 'grace';
    return 'expired';
}

add_shortcode('apiplatform_current_plan', function(){ return '<div id="currentPlan" style="display:none;" class="arm_no_plan">' . esc_html(apiplatform_get_user_plan()) . '</div>'; });