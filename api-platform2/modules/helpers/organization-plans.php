<?php
if (!defined('ABSPATH')) exit;

/**
 * Compatibility resolver only. APIPlatform_Membership_Panel is the sole owner
 * of FreedomAPI plan definitions, limits, features, and unlimited semantics.
 */
class APIPlatform_Organization_Plans {
    public static function definitions(){ return APIPlatform_Membership_Panel::get_plan_definitions(); }
    public static function plan_ids(){ return array_keys(self::definitions()); }
    public static function default_plan_id(){ return 'free'; }
    public static function statuses(){ return ['active', 'grace', 'past_due', 'suspended', 'canceled']; }
    public static function plan_owner_id($organization_id){
        $organization = APIPlatform_Organization_Service::find($organization_id);
        if (!$organization) return 0;
        $creator_id = absint($organization['created_by_user_id'] ?? 0);
        $creator = $creator_id ? APIPlatform_Organization_Membership_Service::member($organization_id, $creator_id) : null;
        if ($creator && ($creator['role'] ?? '') === 'owner') return $creator_id;
        foreach (APIPlatform_Organization_Membership_Service::list_members($organization_id) as $member) {
            if (($member['role'] ?? '') === 'owner' && ($member['status'] ?? '') === 'active') return absint($member['user_id']);
        }
        return 0;
    }
    public static function get_plan_id($organization_id){ return APIPlatform_Membership_Panel::get_effective_user_plan(self::plan_owner_id($organization_id)); }
    public static function get_plan($organization_id){
        $plan = APIPlatform_Membership_Panel::get_plan_definition(self::get_plan_id($organization_id));
        $plan['id'] = self::get_plan_id($organization_id);
        $plan['status'] = self::get_plan_status($organization_id);
        return $plan;
    }
    public static function get_plan_name($organization_id){ return APIPlatform_Membership_Panel::get_plan_label(self::get_plan_id($organization_id)); }
    public static function get_plan_status($organization_id){ return apiplatform_get_plan_status(self::plan_owner_id($organization_id)); }
    public static function get_entitlement($organization_id, $key){ return APIPlatform_Membership_Panel::get_plan_limit(self::get_plan_id($organization_id), self::membership_key($key)); }
    public static function has_feature($organization_id, $feature){ return (bool) self::get_entitlement($organization_id, $feature); }
    public static function get_limit($organization_id, $key){ return self::get_entitlement($organization_id, $key); }
    private static function membership_key($key){ return ['members.max' => 'seats', 'apis.max' => 'apis', 'api_requests.monthly' => 'requests'][(string) $key] ?? sanitize_key($key); }
    public static function usage($organization_id, $resource){
        if ($resource === 'members') return APIPlatform_Organization_Seats::used_count($organization_id);
        if ($resource === 'apis' && class_exists('APIPlatform_Ownership_Service')) return count(APIPlatform_Ownership_Service::api_ids_for_owner('organization', $organization_id));
        return 0;
    }
    public static function status($organization_id, $resource){
        $limit = self::get_limit($organization_id, sanitize_key($resource) . '.max'); $usage = self::usage($organization_id, $resource); $unlimited = APIPlatform_Membership_Panel::is_unlimited($limit);
        return ['key' => sanitize_key($resource), 'usage' => $usage, 'limit' => $limit, 'remaining' => $unlimited ? null : max(0, absint($limit) - $usage), 'over_limit' => !$unlimited && $usage > absint($limit), 'within_limit' => $unlimited || $usage <= absint($limit)];
    }
    public static function within_limit($organization_id, $resource){ return self::status($organization_id, $resource)['within_limit']; }
    public static function over_limit($organization_id, $resource){ return self::status($organization_id, $resource)['over_limit']; }
    public static function remaining($organization_id, $resource){ return self::status($organization_id, $resource)['remaining']; }
    public static function can_consume($organization_id, $resource, $actor_user_id = 0){
        $organization = APIPlatform_Organization_Service::find($organization_id);
        if (!$organization || ($organization['status'] ?? '') !== APIPlatform_Organization_Service::STATUS_ACTIVE) return APIPlatform_Error_Codes::wp_error('organization_not_active');
        $state = self::status($organization_id, $resource);
        if (APIPlatform_Membership_Panel::is_unlimited($state['limit']) || $state['usage'] < absint($state['limit'])) return true;
        $resource = sanitize_key($resource); $code = $resource === 'members' ? 'organization_seat_limit_reached' : 'organization_' . $resource . '_limit_reached';
        $error = APIPlatform_Error_Codes::wp_error($code, ['organization_id' => absint($organization_id), 'resource' => $resource, 'usage' => (int) $state['usage'], 'limit' => $state['limit'], 'plan' => self::get_plan_id($organization_id)]);
        APIPlatform_Organization_Service::record_event($organization_id, $actor_user_id, $resource === 'members' ? 'member_invitation_blocked' : 'api_creation_blocked', $resource, 0, $error->get_error_message(), ['usage' => $state['usage'], 'limit' => $state['limit']]);
        return $error;
    }
    // Retained to avoid fatal calls from older admin markup. Organization plans
    // are now owner-membership-derived and cannot be submitted or overridden.
    public static function set_plan($organization_id, $plan_id, $plan_status, $actor_user_id){ return new WP_Error('organization_plan_owner_managed', 'Organization plans are derived from the canonical organization owner membership.'); }
    public static function limit_label($limit){ return APIPlatform_Membership_Panel::display_limit($limit, true); }
}
class APIPlatform_Organization_Entitlements extends APIPlatform_Organization_Plans {}