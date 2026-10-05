<?php

/*
|--------------------------------------------------------------------------
| Debug
|--------------------------------------------------------------------------
*/

if (

    defined('WP_DEBUG') &&

    WP_DEBUG
){

    error_log(

        'SETTINGS GENERAL PARTIAL LOADED'
    );

    error_log(

        'Theme Value: ' .

        ($theme ?? 'missing')
    );

    error_log(

        'Notifications Value: ' .

        ($notifications ?? 'missing')
    );
}

/*
|--------------------------------------------------------------------------
| Form Content
|--------------------------------------------------------------------------
*/

$content = '

<form
    method="post"
    class="apiplatform-settings-form"
>

    ' . wp_nonce_field(

        'apiplatform_save_settings',

        'apiplatform_settings_nonce',

        true,

        false
    ) . '

    <div class="apiplatform-setting-row">

        <div>

            <strong>Dark Mode</strong>

        </div>

        <label class="apiplatform-switch">

            <input
                type="checkbox"
                name="theme"
                value="dark"
                ' . checked(

                    $theme,

                    'dark',

                    false
                ) . '
            >

            <span class="apiplatform-slider"></span>

        </label>

    </div>

    <div class="apiplatform-setting-row">

        <div>

            <strong>Notifications</strong>

        </div>

        <label class="apiplatform-switch">

            <input
                type="checkbox"
                name="notifications"
                value="enabled"
                ' . checked(

                    $notifications,

                    'enabled',

                    false
                ) . '
            >

            <span class="apiplatform-slider"></span>

        </label>

    </div>

    <p>

        <button
            type="submit"
            name="apiplatform_save_settings"
            value="1"
            class="apiplatform-button apiplatform-button-primary"
        >

            Save Settings

        </button>

    </p>

</form>
';

/*
|--------------------------------------------------------------------------
| Render Card
|--------------------------------------------------------------------------
*/

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'General Settings',

        'content' => $content
    ]
);
?>
