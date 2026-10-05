<?php

$saved = get_user_meta(
    $user_id,
    'notify_channels',
    true
);

if (!is_array($saved)) {
    $saved = [];
}

?>

<div class="apiplatform-card">

    <h4>Notification Preferences</h4>

    <label>

        <input
            type="checkbox"
            name="notify_channels[]"
            value="email"

            <?php checked(
                in_array('email', $saved)
            ); ?>
        >

        Email

    </label>

    <br>

    <label>

        <input
            type="checkbox"
            name="notify_channels[]"
            value="sms"

            <?php checked(
                in_array('sms', $saved)
            ); ?>
        >

        SMS

    </label>

    <br>

    <label>

        <input
            type="checkbox"
            name="notify_channels[]"
            value="push"

            <?php checked(
                in_array('push', $saved)
            ); ?>
        >

        Push

    </label>

    <br>

    <label>

        <input
            type="checkbox"
            name="notify_channels[]"
            value="internal"

            <?php checked(
                in_array('internal', $saved)
            ); ?>
        >

        In-App

    </label>

</div>