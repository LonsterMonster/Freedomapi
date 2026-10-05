<div class="apiplatform-card">

    <h3>
        <?php echo esc_html(
            $api->post_title
        ); ?>
    </h3>

    <?php
    include __DIR__ .
        '/create-key-form.php';
    ?>

    <hr>

    <?php if ($keys): ?>

        <?php foreach($keys as $k): ?>

            <?php
            include __DIR__ .
                '/key-item.php';
            ?>

        <?php endforeach; ?>

    <?php else: ?>

        <?php
        include __DIR__ .
            '/empty-state.php';
        ?>

    <?php endif; ?>

</div>