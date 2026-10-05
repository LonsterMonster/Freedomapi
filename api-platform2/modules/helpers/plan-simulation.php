<?php
if (!defined('ABSPATH')) exit;

/**
 * Administrator-scoped entitlement simulation. Plan values always come from
 * APIPlatform_Membership_Panel; this service stores only a selected plan ID.
 */
class APIPlatform_Plan_Simulation {
    const TOOLS_OPTION = 'apiplatform_enable_plan_testing_tools';
    const ENABLED_META = 'apiplatform_plan_simulation_enabled';
    const PLAN_META = 'apiplatform_plan_simulation_plan';

    public static function tools_enabled(){
        if (defined('APIPLATFORM_DISABLE_PLAN_SIMULATION') && APIPLATFORM_DISABLE_PLAN_SIMULATION) return false;
        return (bool) apply_filters('apiplatform_enable_plan_testing_tools', (bool) get_option(self::TOOLS_OPTION, false));
    }

    public static function allowed_plan_ids(){
        if (!class_exists('APIPlatform_Membership_Panel')) return [];
        return array_keys(APIPlatform_Membership_Panel::get_plan_definitions());
    }

    public static function is_active_for($user_id){
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        if (!self::tools_enabled() || !$user_id || !user_can($user_id, 'manage_options')) return false;
        $plan = sanitize_key((string) get_user_meta($user_id, self::PLAN_META, true));
        return (bool) get_user_meta($user_id, self::ENABLED_META, true) && in_array($plan, self::allowed_plan_ids(), true);
    }

    public static function selected_plan($user_id){
        $user_id = $user_id === null ? get_current_user_id() : absint($user_id);
        $plan = sanitize_key((string) get_user_meta($user_id, self::PLAN_META, true));
        return in_array($plan, self::allowed_plan_ids(), true) ? $plan : '';
    }

    public static function effective_plan($user_id, $actual_plan){
        $actual_plan = sanitize_key((string) $actual_plan);
        return self::is_active_for($user_id) ? self::selected_plan($user_id) : $actual_plan;
    }

    public static function organization_is_simulated_for_current_user($organization_id){
        $user_id = get_current_user_id();
        return $user_id && self::is_active_for($user_id) && class_exists('APIPlatform_Organization_Plans')
            && APIPlatform_Organization_Plans::plan_owner_id($organization_id) === $user_id;
    }

    public static function handle_save(){
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['apiplatform_plan_simulation_save'])) return;
        if (!current_user_can('manage_options')) wp_die(esc_html__('Access denied.', 'apiplatform'));
        check_admin_referer('apiplatform_plan_simulation_save', 'apiplatform_plan_simulation_nonce');

        $tools_enabled = !empty($_POST['apiplatform_enable_plan_testing_tools']);
        update_option(self::TOOLS_OPTION, $tools_enabled ? 1 : 0, false);

        $plan = sanitize_key(wp_unslash($_POST['apiplatform_plan_simulation_plan'] ?? ''));
        $enabled = $tools_enabled && empty($_POST['apiplatform_plan_simulation_disable']) && !empty($_POST['apiplatform_plan_simulation_enabled']) && in_array($plan, self::allowed_plan_ids(), true);
        update_user_meta(get_current_user_id(), self::ENABLED_META, $enabled ? 1 : 0);
        update_user_meta(get_current_user_id(), self::PLAN_META, in_array($plan, self::allowed_plan_ids(), true) ? $plan : '');

        wp_safe_redirect(add_query_arg('apiplatform_plan_simulation_updated', '1', admin_url('admin.php?page=apiplatform-settings&tab=plan-simulation')));
        exit;
    }

    public static function render_admin_control(){
        if (!current_user_can('manage_options') || !class_exists('APIPlatform_Membership_Panel')) return;
        $user_id = get_current_user_id();
        $actual_plan = APIPlatform_Membership_Panel::get_user_plan($user_id);
        $effective_plan = APIPlatform_Membership_Panel::get_effective_user_plan($user_id);
        $selected_plan = self::selected_plan($user_id) ?: $actual_plan;
        $tools_enabled = self::tools_enabled();
        $active = self::is_active_for($user_id);
        $definition = APIPlatform_Membership_Panel::get_plan_definition($effective_plan);
        if (!empty($_GET['apiplatform_plan_simulation_updated'])) echo '<div class="notice notice-success is-dismissible"><p>Plan simulation settings saved.</p></div>';
        ?>
        <form method="post">
            <?php wp_nonce_field('apiplatform_plan_simulation_save', 'apiplatform_plan_simulation_nonce'); ?>
            <input type="hidden" name="apiplatform_plan_simulation_save" value="1">
            <div class="apiplatform-card">
                <h3>Plan Simulation</h3>
                <p>Administrator-only testing. This never changes ARMember membership, WordPress capabilities, organization roles, or ownership.</p>
                <p><label><input type="checkbox" name="apiplatform_enable_plan_testing_tools" value="1" <?php checked($tools_enabled); ?>> Enable Plan Testing Tools</label></p>
                <p><label><input type="checkbox" name="apiplatform_plan_simulation_enabled" value="1" <?php checked($active); ?>> Enable Plan Simulation</label></p>
                <p><label>Simulate Plan <select name="apiplatform_plan_simulation_plan">
                    <?php foreach (APIPlatform_Membership_Panel::get_plan_definitions() as $id => $plan): ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_plan, $id); ?>><?php echo esc_html($plan['label']); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
                <p><strong>Actual Plan:</strong> <?php echo esc_html(APIPlatform_Membership_Panel::get_plan_label($actual_plan)); ?><br>
                <strong><?php echo $active ? 'Effective Test Plan:' : 'Effective Plan:'; ?></strong> <?php echo esc_html(APIPlatform_Membership_Panel::get_plan_label($effective_plan)); ?></p>
                <?php if ($active): ?><p><strong style="color:#f0b849;">PLAN SIMULATION ACTIVE</strong><br>
                    Actual: <?php echo esc_html(APIPlatform_Membership_Panel::get_plan_label($actual_plan)); ?> · Simulating: <?php echo esc_html($definition['label']); ?><br>
                    Limits: <?php echo esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['requests'])); ?> requests · <?php echo esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['apis'])); ?> APIs · <?php echo esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['seats'])); ?> seats</p><?php endif; ?>
                <?php submit_button('Save Plan Simulation', 'primary', 'submit', false); ?>
                <?php if ($active): ?><button class="button" type="submit" name="apiplatform_plan_simulation_disable" value="1">Return to Actual Plan</button><?php endif; ?>
            </div>
        </form>
        <?php
    }

    public static function publisher_notice($organization_id = 0){
        $user_id = get_current_user_id();
        if (!self::is_active_for($user_id)) return '';
        if ($organization_id && !self::organization_is_simulated_for_current_user($organization_id)) return '';
        $actual_plan = APIPlatform_Membership_Panel::get_user_plan($user_id);
        $effective_plan = APIPlatform_Membership_Panel::get_effective_user_plan($user_id);
        $definition = APIPlatform_Membership_Panel::get_plan_definition($effective_plan);
        return '<div class="apiplatform-publisher-alert apiplatform-plan-simulation-notice"><strong>PLAN SIMULATION ACTIVE</strong><br>Actual: ' . esc_html(APIPlatform_Membership_Panel::get_plan_label($actual_plan)) . ' · Simulating: ' . esc_html($definition['label']) . '<br>Limits: ' . esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['requests'])) . ' requests · ' . esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['apis'])) . ' APIs · ' . esc_html(APIPlatform_Membership_Panel::display_limit($definition['limits']['seats'])) . ' seats</div>';
    }
}

add_action('admin_init', ['APIPlatform_Plan_Simulation', 'handle_save']);