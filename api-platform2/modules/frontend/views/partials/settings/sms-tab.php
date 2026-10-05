<div class="tab-content" id="tab-sms">

    <h4>Twilio</h4>

    <input
        type="text"
        name="twilio_sid"
        placeholder="SID"
        value="<?php echo esc_attr(
            get_option(
                'apiplatform_twilio_sid'
            )
        ); ?>"
        class="apiplatform-input"
    >

    <input
        type="text"
        name="twilio_token"
        placeholder="Token"
        value="<?php echo esc_attr(
            get_option(
                'apiplatform_twilio_token'
            )
        ); ?>"
        class="apiplatform-input"
    >

    <input
        type="text"
        name="twilio_number"
        placeholder="+123456789"
        value="<?php echo esc_attr(
            get_option(
                'apiplatform_twilio_number'
            )
        ); ?>"
        class="apiplatform-input"
    >

    <p>

        Status →

        <strong class="
            <?php echo
                apiplatform_is_twilio_ready()
                ? 'status-ok'
                : 'status-fail';
            ?>
        ">

            <?php echo
                apiplatform_is_twilio_ready()
                ? 'Connected'
                : 'Missing config';
            ?>

        </strong>

    </p>

    <?php if (!apiplatform_is_twilio_ready()): ?>

        <p class="status-warning">

            ⚠️ SMS will not work until
            Twilio is configured

        </p>

    <?php endif; ?>

</div>