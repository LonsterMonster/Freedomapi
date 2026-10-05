<?php

$content = '

<p>

' .

esc_html($used)

.

' / ' .

($limit == INF
    ? '∞'
    : esc_html($limit))

.

'</p>

';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Total Usage',

        'content' => $content
    ]
);
?>