<div class="apiplatform-card">

    <h3>Team</h3>

    <p>
        <?php echo count($members); ?>
        /
        <?php echo esc_html($seat_limit); ?>
    </p>

    <?php if (
        count($members) >= $seat_limit
    ): ?>

        <p class="status-warning">
            Upgrade to add more users
        </p>

    <?php endif; ?>

    <?php foreach($members as $uid => $role):

        $u = get_userdata($uid);

    ?>

        <p>

            <?php echo esc_html(
                $u->user_email
            ); ?>

            (<?php echo esc_html($role); ?>)

        </p>

    <?php endforeach; ?>

</div>