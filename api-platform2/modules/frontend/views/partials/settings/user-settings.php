<div class="apiplatform-card">

    <form method="post">

        <?php
        wp_nonce_field(
            'apiplatform_save_settings',
            'apiplatform_settings_nonce'
        );
        ?>

        <?php
        include __DIR__ .
            '/webhook-form.php';
        ?>

        <div class="apiplatform-card">

            <h4>Phone (SMS)</h4>

            <input
                type="text"
                name="phone_number"
                value="<?php echo esc_attr(
                    get_user_meta(
                        $user_id,
                        'phone_number',
                        true
                    )
                ); ?>"
                placeholder="+123456789"
                class="apiplatform-input"
            >

        </div>

        <div class="apiplatform-card">

            <h4>Push Token (Firebase)</h4>

            <input
                type="text"
                name="firebase_token"
                value="<?php echo esc_attr(
                    get_user_meta(
                        $user_id,
                        'firebase_token',
                        true
                    )
                ); ?>"
                placeholder="Device Token"
                class="apiplatform-input"
            >

        </div>

        <?php
        include __DIR__ .
            '/notification-preferences.php';
        ?>

        <?php
        include __DIR__ .
            '/save-button.php';
        ?>

    </form>

</div>