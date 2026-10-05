<?php

$content = '

<p>

<strong>Usage:</strong>

' .

(int)get_post_meta(

    $api->ID,

    'api_usage',

    true

)

.

'</p>

<p>

<strong>Slug:</strong>

' .

esc_html(

    $api->post_name

)

.

'</p>

';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => $api->post_title,

        'content' => $content
    ]
);
?>