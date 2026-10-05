<?php

$content = '

<p>

API preference controls coming soon.

</p>

<p>

Rate limiting controls coming soon.

</p>

<p>

Webhook configuration coming soon.

</p>
';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'API Preferences',

        'content' => $content
    ]
);
?>