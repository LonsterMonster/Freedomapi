<?php

$item = $item ?? [];

$api_name = $item['api_name'] ?? '';

$api_id = $item['api_id'] ?? 0;

$api_slug = $item['api_slug'] ?? '';

$endpoint_url = $item['endpoint_url'] ?? '';

$edit_url = $item['edit_url'] ?? '';

$test_url = $item['test_url'] ?? '';

$docs_url = $item['docs_url'] ?? '';

$history_url = $item['history_url'] ?? '';

$keys = $item['keys'] ?? [];

$status = $item['status'] ?? '';

$regenerate_nonce = $item['regenerate_nonce'] ?? '';

$is_active = strtolower($status) === 'active';

$content = '<div class="apiplatform-endpoint-card">';

$content .= '<div class="apiplatform-endpoint-card-header">';

$content .= '<div class="apiplatform-endpoint-title">';

$content .= '<span class="apiplatform-endpoint-label">Endpoint</span>';

$content .= '<code class="apiplatform-endpoint-url">' . esc_html($endpoint_url) . '</code>';

$content .= '</div>';

if ($status){

    $content .= '<span class="' . esc_attr($is_active ? 'status-ok' : 'status-fail') . '">';

    $content .= esc_html($status);

    $content .= '</span>';
}

$content .= '</div>';

$content .= '<div class="apiplatform-endpoint-actions">';

if ($endpoint_url){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Copy Endpoint',

            'html_type' => 'button',

            'class' => 'apiplatform-copy-value',

            'value' => $endpoint_url,

            'aria_label' => 'Copy endpoint for ' . $api_slug,

            'data_attrs' => [

                'copy-value' => $endpoint_url,

                'copy-success' => 'Endpoint copied'
            ]
        ]
    );
}

if ($edit_url){

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Edit API',

            'url' => $edit_url,

            'class' => 'apiplatform-edit-api-link'
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

if ($api_id && $regenerate_nonce){

    $content .= '<form method="post" class="apiplatform-regenerate-key-form">';

    $content .= '<input type="hidden" name="apiplatform_key_action" value="regenerate">';

    $content .= '<input type="hidden" name="api_id" value="' . esc_attr($api_id) . '">';

    $content .= '<input type="hidden" name="apiplatform_regenerate_key_nonce" value="' . esc_attr($regenerate_nonce) . '">';

    $content .= APIPlatform_Renderer::component(

        'button',

        [

            'type' => 'secondary',

            'label' => 'Regenerate Key',

            'html_type' => 'submit',

            'class' => 'apiplatform-regenerate-key-button',

            'aria_label' => 'Regenerate API key for ' . $api_slug
        ]
    );

    $content .= '</form>';
}

$content .= '</div>';

$content .= '<div class="apiplatform-endpoint-key-list">';

foreach($keys as $key){

    $api_key = $key['api_key'] ?? '';

    $key_status = $key['status'] ?? '';

    $copyable = !empty($key['copyable']);

    $api_key_secret = $key['api_key_secret'] ?? '';

    $content .= '<div class="apiplatform-endpoint-key-row">';

    $content .= '<div class="apiplatform-endpoint-key-main">';

    $content .= '<span class="apiplatform-endpoint-label">API Key</span>';

    if ($api_key){

        $content .= '<code class="apiplatform-endpoint-key">' . esc_html($api_key) . '</code>';
    } else {

        $content .= '<span class="apiplatform-key-missing">Missing key</span>';
    }

    $content .= '</div>';

    $content .= '<div class="apiplatform-endpoint-key-actions">';

    if ($key_status && count($keys) > 1){

        $content .= '<span class="' . esc_attr(strtolower($key_status) === 'active' ? 'status-ok' : 'status-fail') . '">';

        $content .= esc_html($key_status);

        $content .= '</span>';
    }

    if ($copyable && $api_key_secret){

        $content .= APIPlatform_Renderer::component(

            'button',

            [

                'type' => 'secondary',

                'label' => 'Copy API Key',

                'html_type' => 'button',

                'class' => 'apiplatform-copy-value',

                'value' => $api_key_secret,

                'aria_label' => 'Copy API key for ' . $api_slug,

                'data_attrs' => [

                    'copy-value' => $api_key_secret,

                    'copy-success' => 'API key copied'
                ]
            ]
        );
    }

    $content .= '</div>';

    $content .= '</div>';
}

$content .= '</div>';

if ($api_name){

    $content .= '<p class="apiplatform-endpoint-meta">' . esc_html($api_name) . '</p>';
}

$content .= '</div>';

echo APIPlatform_Renderer::component(

    'card',

    [

        'class' => 'apiplatform-api-endpoint-card',

        'content' => $content
    ]
);
?>
