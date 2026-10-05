<div class="apiplatform-card">

    <h3>Available Plans</h3>

    <?php if ($is_admin): ?>

        <div class="apiplatform-card">

            <h3>Current Plan</h3>

            <p class="status-ok">
                Admin (Unlimited)
            </p>

        </div>

    <?php else: ?>

        <?php
        echo do_shortcode(
            '[arm_setup id="1"]'
        );
        ?>

    <?php endif; ?>

</div>