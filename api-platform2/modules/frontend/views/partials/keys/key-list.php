<?php

$api_keys = $api_keys ?? [];

$content = '<div class="apiplatform-endpoint-card-list">';

foreach($api_keys as $item){

    $content .= APIPlatform_Renderer::partial(

        'keys/key-item',

        [

            'item' => $item
        ]
    );
}

$content .= '</div>';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'API Keys',

        'class' => 'apiplatform-keys-card',

        'content' => $content
    ]
);
?>
