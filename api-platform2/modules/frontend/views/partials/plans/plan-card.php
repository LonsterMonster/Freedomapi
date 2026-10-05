<div class="
    apiplatform-card
    apiplatform-plan-card

    <?php echo
        $current_plan === $plan['slug']
        ? 'active'
        : '';
    ?>
">

    <h3>
        <?php echo esc_html($plan['name']); ?>
    </h3>

    <div class="apiplatform-price">

        $
        <?php echo esc_html($plan['price']); ?>

        <span>/MON</span>

    </div>

    <ul class="apiplatform-feature-list">

        <?php foreach($plan['features'] as $feature): ?>

            <li>
                <?php echo esc_html($feature); ?>
            </li>

        <?php endforeach; ?>

    </ul>

    <?php if (
        $current_plan === $plan['slug']
    ): ?>

        <button class="button" disabled>
            Current Plan
        </button>

    <?php else: ?>

        <a href="#" class="button">

            Upgrade

        </a>

    <?php endif; ?>

</div>