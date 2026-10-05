<?php

$api = $api ?? [];

$regenerate_nonce = $regenerate_nonce ?? '';

$test_url = $test_url ?? '';

$docs_url = $docs_url ?? '';

$history_url = $history_url ?? '';

$endpoint_id = 'apiplatform-edit-endpoint-' . absint($api['id'] ?? 0);

$key_id = 'apiplatform-edit-key-' . absint($api['id'] ?? 0);

$content = '<div id="apiplatform-edit-keys" class="apiplatform-edit-api-key-panel">';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label>Endpoint URL</label>';
$content .= '<code id="' . esc_attr($endpoint_id) . '" class="apiplatform-endpoint-url">' . esc_html($api['endpoint_url'] ?? '') . '</code>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label>API Key</label>';
$content .= '<input id="' . esc_attr($key_id) . '" class="apiplatform-input apiplatform-edit-api-key-input" type="text" value="' . esc_attr($api['api_key'] ?? '') . '" readonly>';
$content .= '</div>';

$content .= '<div class="apiplatform-edit-api-actions">';

if (!empty($api['endpoint_url'])){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Copy Endpoint',

            'html_type' => 'button',

            'class' => 'apiplatform-copy-value',

            'value' => $api['endpoint_url'],

            'aria_label' => 'Copy endpoint for ' . ($api['slug'] ?? ''),

            'data_attrs' => [

                'copy-value' => $api['endpoint_url'],

                'copy-source' => $endpoint_id,

                'copy-success' => 'Endpoint copied'
            ]
        ]
    );
}

if ($test_url){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Test API',

            'url' => $test_url,

            'class' => 'apiplatform-test-api-link'
        ]
    );
}

if ($docs_url){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Documentation',

            'url' => $docs_url,

            'class' => 'apiplatform-api-documentation-link'
        ]
    );
}

if ($history_url){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Request History',

            'url' => $history_url,

            'class' => 'apiplatform-request-history-link'
        ]
    );
}

$content .= '<form method="post" class="apiplatform-regenerate-key-form">';
$content .= '<input type="hidden" name="apiplatform_edit_api_action" value="regenerate">';
$content .= '<input type="hidden" name="apiplatform_regenerate_key_nonce" value="' . esc_attr($regenerate_nonce) . '">';

$content .= APIPlatform_Renderer::component(

    'button',

    [

        'type' => 'secondary',

        'label' => 'Regenerate Key',

        'html_type' => 'submit',

        'class' => 'apiplatform-regenerate-key-button'
    ]
);

$content .= '</form>';

$content .= '</div>';

$content .= '</div>';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Endpoint and Key',

        'class' => 'apiplatform-edit-api-card',

        'content' => $content
    ]
);
?>
