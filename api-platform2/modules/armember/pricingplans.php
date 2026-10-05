<?php
add_shortcode('api_pricing_plans', function(){
    if (!is_user_logged_in()) return '<p>Please login to view plans.</p>';
    $current = APIPlatform_Membership_Panel::get_user_plan(get_current_user_id());
    $plans = APIPlatform_Membership_Panel::get_plan_definitions();
    ob_start(); ?>
    <div class="api-pricing-grid">
        <?php foreach($plans as $key => $plan): if ($key === 'admin') continue; ?>
            <div class="api-plan-card <?php echo $key === $current ? 'active' : ''; ?>">
                <h2><?php echo esc_html($plan['label']); ?></h2>
                <div class="price"><?php echo $plan['price'] === '0' ? 'Free' : '$' . esc_html($plan['price']) . '/Month'; ?></div>
                <ul class="features"><li><?php echo esc_html(APIPlatform_Membership_Panel::display_limit($plan['limits']['requests'])); ?> Requests</li><li><?php echo esc_html(APIPlatform_Membership_Panel::display_limit($plan['limits']['apis'])); ?> APIs</li><li><?php echo esc_html(APIPlatform_Membership_Panel::display_limit($plan['limits']['seats'])); ?> Seats</li></ul>
                <div class="plan-action"><?php if ($key === $current): ?><span class="current-plan">Current Plan</span><?php elseif (!empty($plan['setup_id'])): ?><?php echo do_shortcode('[arm_setup id="' . absint($plan['setup_id']) . '"]'); ?><?php else: ?><span>Free Plan</span><?php endif; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
});