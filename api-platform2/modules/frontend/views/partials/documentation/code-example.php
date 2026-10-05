<?php

$example = is_array($example ?? null) ? $example : [];
$id = $id ?? uniqid('apiplatform-docs-code-', false);
$title = $example['title'] ?? 'Example';
$language = $example['language'] ?? 'text';
$code = $example['code'] ?? '';

$content = '<div class="apiplatform-docs-example">';
$content .= '<div class="apiplatform-docs-example-header">';
$content .= '<span>' . esc_html($title) . '</span>';
$content .= APIPlatform_Renderer::component('button', [
    'type' => 'secondary',
    'label' => 'Copy',
    'html_type' => 'button',
    'class' => 'apiplatform-copy-value apiplatform-docs-copy',
    'aria_label' => 'Copy ' . $title,
    'data_attrs' => [
        'copy-value' => $code,
        'copy-source' => $id,
        'copy-success' => 'Copied'
    ]
]);
$content .= '</div>';
$content .= '<pre id="' . esc_attr($id) . '" class="apiplatform-docs-code"><code class="language-' . esc_attr($language) . '">' . esc_html($code) . '</code></pre>';
$content .= '</div>';

echo $content;
