<div class="apiplatform-card">

    <h3>Webhook</h3>

    <input
        type="text"
        name="webhook_url"
        value="<?php echo esc_attr(
            get_user_meta(
                $user_id,
                'apiplatform_webhook_url',
                true
            )
        ); ?>"
        placeholder="https://your-site.com/webhook"
        class="apiplatform-input"
    >

</div>