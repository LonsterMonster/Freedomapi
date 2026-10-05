<?php
if (!defined('ABSPATH')) exit;

/** Canonical FreedomAPI plan definitions and plan-limit accessors. */
class APIPlatform_Membership_Panel {
    const UNLIMITED = 'Unlimited';

    public function __construct(){ add_shortcode('api_membership_panel', [$this, 'render']); }

    public static function get_plan_definitions(){
        return [
            'free' => ['label' => 'Free Plan', 'usage_type' => 'per_api', 'price' => '0', 'setup_id' => '', 'limits' => ['requests' => 100, 'apis' => 3, 'seats' => 1, 'free_requests' => 100, 'credits' => 0, 'cost_per_request' => 1, 'sdk_generation' => true, 'advanced_analytics' => false, 'audit_logs' => false, 'request_history_days' => 7, 'custom_domains' => false, 'organization_branding' => false]],
            'pro' => ['label' => 'Pro Plan', 'usage_type' => 'per_api', 'price' => '10', 'setup_id' => 2, 'limits' => ['requests' => 1000, 'apis' => 10, 'seats' => 3, 'free_requests' => 0, 'credits' => 100, 'cost_per_request' => 1, 'sdk_generation' => true, 'advanced_analytics' => false, 'audit_logs' => false, 'request_history_days' => 30, 'custom_domains' => false, 'organization_branding' => false]],
            'premium' => ['label' => 'Premium Plan', 'usage_type' => 'global', 'price' => '49', 'setup_id' => 3, 'limits' => ['requests' => 10000, 'apis' => 25, 'seats' => 10, 'free_requests' => 0, 'credits' => 500, 'cost_per_request' => 1, 'sdk_generation' => true, 'advanced_analytics' => true, 'audit_logs' => true, 'request_history_days' => 90, 'custom_domains' => false, 'organization_branding' => true]],
            'admin' => ['label' => 'Admin Access', 'usage_type' => 'unlimited', 'price' => '', 'setup_id' => '', 'limits' => ['requests' => self::UNLIMITED, 'apis' => self::UNLIMITED, 'seats' => self::UNLIMITED, 'free_requests' => self::UNLIMITED, 'credits' => self::UNLIMITED, 'cost_per_request' => 0, 'sdk_generation' => true, 'advanced_analytics' => true, 'audit_logs' => true, 'request_history_days' => 365, 'custom_domains' => true, 'organization_branding' => true]],
        ];
    }
    public static function get_plan_definition($plan){ $plans = self::get_plan_definitions(); $plan = sanitize_key($plan); return $plans[$plan] ?? $plans['free']; }
    public static function get_plan_label($plan){ return self::get_plan_definition($plan)['label']; }
    public static function get_plan_limit($plan, $key){ return self::get_plan_definition($plan)['limits'][sanitize_key($key)] ?? null; }
    public static function get_user_plan($user_id = null){ return function_exists('apiplatform_get_user_plan') ? apiplatform_get_user_plan($user_id) : 'free'; }
    public static function get_effective_user_plan($user_id = null){
        $actual_plan = self::get_user_plan($user_id);
        return class_exists('APIPlatform_Plan_Simulation')
            ? APIPlatform_Plan_Simulation::effective_plan($user_id, $actual_plan)
            : $actual_plan;
    }
    public static function get_user_limit($user_id, $key){ return self::get_plan_limit(self::get_effective_user_plan($user_id), $key); }
    public static function is_unlimited($value){ return $value === self::UNLIMITED; }
    public static function display_limit($value, $fraction = false){ return self::is_unlimited($value) ? ($fraction ? '∞' : self::UNLIMITED) : (string) max(0, absint($value)); }
    /** Canonical organization seat policy: active members plus valid pending invitations reserve seats. */
    public static function seat_policy(){ return ['key' => 'active_members_plus_valid_pending_invitations', 'member_statuses' => ['active'], 'invitation_status' => 'pending', 'label' => 'Active members plus valid pending invitations reserve seats.']; }
    public static function seat_policy_label(){ return self::seat_policy()['label']; }
    public static function seat_usage($active_members, $valid_pending_invitations){ return max(0, absint($active_members)) + max(0, absint($valid_pending_invitations)); }

    public function render(){
        if (!is_user_logged_in()) return '<p>Please login to view your plan.</p>';
        $plan = self::get_user_plan(get_current_user_id());
        $definition = self::get_plan_definition($plan);
        ob_start(); ?>
        <div class="container api-membership-table"><ul class="responsive-table">
          <li class="table-header"><div class="col col-1">Plan</div><div class="col col-2">Requests</div><div class="col col-3">APIs</div><div class="col col-3">Seats</div><div class="col col-4">Status</div><div class="col col-4">Action</div></li>
          <li class="table-row"><div class="col col-1" data-label="Plan"><?php echo esc_html($definition['label']); ?></div><div class="col col-2" data-label="Requests"><?php echo esc_html(self::display_limit($definition['limits']['requests'])); ?></div><div class="col col-3" data-label="APIs"><?php echo esc_html(self::display_limit($definition['limits']['apis'])); ?></div><div class="col col-3" data-label="Seats"><?php echo esc_html(self::display_limit($definition['limits']['seats'])); ?></div><div class="col col-4" data-label="Status"><?php echo $plan === 'admin' ? 'Admin' : 'Active'; ?></div><div class="col col-4" data-label="Action"><?php if ($plan !== 'admin'): ?><a href="<?php echo esc_url(site_url('/pricing')); ?>" class="button">Change Plan</a><?php else: ?>Included<?php endif; ?></div></li>
        </ul></div>
        <?php return ob_get_clean();
    }
}
