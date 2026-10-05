<?php

/*
|--------------------------------------------------------------------------
| Account Security
|--------------------------------------------------------------------------
*/

echo APIPlatform_Renderer::component(

    'account-security',

    [

        'user' => $user ?? null,

        'profile' => $profile ?? []
    ]
);
?>
