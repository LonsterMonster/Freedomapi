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

        'ACCOUNT SECURITY PARTIAL LOADED'
    );
}

/*
|--------------------------------------------------------------------------
| User
|--------------------------------------------------------------------------
*/

$username = $user->user_login ?? '';

$email    = $user->user_email ?? '';

/*
|--------------------------------------------------------------------------
| Card Content
|--------------------------------------------------------------------------
*/

$content = '

<p>

<strong>Username:</strong>

' . esc_html($username) . '

</p>

<p>

<strong>Email:</strong>

' . esc_html($email) . '

</p>

<hr>

<p>

Password management tools coming soon.

</p>

<p>

Two-factor authentication support coming soon.

</p>

<p>

Active session management coming soon.

</p>
';

/*
|--------------------------------------------------------------------------
| Render Card
|--------------------------------------------------------------------------
*/

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Account Security',

        'content' => $content
    ]
);
?>