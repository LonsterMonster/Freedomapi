<div class="apiplatform-card">

    <h3>Your Subscription</h3>

    <?php if ($is_admin): ?>

        <p class="status-ok">
            Admin (Unlimited)
        </p>

    <?php else: ?>

        <?php
        echo do_shortcode(
            '[arm_membership]'
        );
        ?>

    <?php endif; ?>

</div>