<?php

/*
|--------------------------------------------------------------------------
| Profile Form Data
|--------------------------------------------------------------------------
*/

$profile = $profile ?? [];

$display_name = $profile['display_name'] ?? '';

$email = $profile['user_email'] ?? '';

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

        'PROFILE USER CARD PARTIAL LOADED'
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
    class="apiplatform-profile-form"
>

    ' . wp_nonce_field(

        'apiplatform_save_profile',

        'apiplatform_profile_nonce',

        true,

        false
    ) . '

    <div class="apiplatform-form-row">

        <label for="apiplatform_display_name">
            Name
        </label>

        <input
            type="text"
            id="apiplatform_display_name"
            name="display_name"
            value="' . esc_attr($display_name) . '"
            class="apiplatform-input"
        >

    </div>

    <div class="apiplatform-form-row">

        <label for="apiplatform_user_email">
            Email
        </label>

        <input
            type="email"
            id="apiplatform_user_email"
            name="user_email"
            value="' . esc_attr($email) . '"
            class="apiplatform-input"
        >

    </div>

    <p>

        <button
            type="submit"
            name="apiplatform_save_profile"
            value="1"
            class="apiplatform-button apiplatform-button-primary"
        >

            Save Profile

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

        'title' => 'Profile',

        'content' => $content
    ]
);
?>
