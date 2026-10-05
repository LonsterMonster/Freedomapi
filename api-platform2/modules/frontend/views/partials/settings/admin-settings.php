<div class="apiplatform-card">

    <?php
    include __DIR__ . '/tabs.php';
    ?>

    <form method="post">

        <?php
        wp_nonce_field(
            'apiplatform_save_settings',
            'apiplatform_settings_nonce'
        );
        ?>

        <?php
        include __DIR__ .
            '/system-tab.php';
        ?>

        <?php
        include __DIR__ .
            '/notifications-tab.php';
        ?>

        <?php
        include __DIR__ .
            '/sms-tab.php';
        ?>

        <?php
        include __DIR__ .
            '/push-tab.php';
        ?>

        <?php
        include __DIR__ .
            '/save-button.php';
        ?>

    </form>

</div>