<?php

$endpoint = is_array($endpoint ?? null) ? $endpoint : [];
$id = $endpoint['id'] ?? '';
$method = strtoupper($endpoint['method'] ?? 'GET');
$title = $endpoint['title'] ?? '';
$path = $endpoint['path'] ?? '';
$description = $endpoint['description'] ?? '';
$parameters = is_array($endpoint['parameters'] ?? null) ? $endpoint['parameters'] : [];
$request = $endpoint['request'] ?? ($endpoint['example'] ?? '');
$response = $endpoint['response'] ?? '';
$request_language = $endpoint['request_language'] ?? 'json';
$response_language = $endpoint['response_language'] ?? 'json';

$method_class = strtolower($method);

$content = '<article class="apiplatform-docs-endpoint" id="' . esc_attr($id) . '">';
$content .= '<div class="apiplatform-docs-endpoint-heading">';
$content .= '<div>';
$content .= '<h3><span class="apiplatform-docs-method apiplatform-docs-method-' . esc_attr($method_class) . '">[' . esc_html($method) . ']</span> ' . esc_html($title) . '</h3>';
$content .= '<code>' . esc_html($path) . '</code>';
$content .= '</div>';
$content .= '</div>';

if ($description) {
    $content .= '<p class="apiplatform-docs-endpoint-description">' . esc_html($description) . '</p>';
}

if ($parameters) {
    $content .= '<div class="apiplatform-docs-params">';
    $content .= '<h4>Parameters</h4>';
    $content .= '<div class="apiplatform-docs-param-table-wrap">';
    $content .= '<table class="apiplatform-docs-param-table">';
    $content .= '<thead><tr>';
    $content .= '<th scope="col">Name</th>';
    $content .= '<th scope="col">Type</th>';
    $content .= '<th scope="col">Required</th>';
    $content .= '<th scope="col">Description</th>';
    $content .= '</tr></thead>';
    $content .= '<tbody>';
    foreach ($parameters as $parameter) {
        $required = !empty($parameter['required']);
        $content .= '<tr>';
        $content .= '<td><code>' . esc_html($parameter['name'] ?? '') . '</code></td>';
        $content .= '<td><span class="apiplatform-docs-param-type">' . esc_html($parameter['type'] ?? 'string') . '</span></td>';
        $content .= '<td>' . ($required ? '<strong class="apiplatform-docs-param-required">Yes</strong>' : '<span class="apiplatform-docs-param-optional">No</span>') . '</td>';
        $content .= '<td>' . esc_html($parameter['description'] ?? '') . '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
    $content .= '</div>';
}

if ($request) {
    $content .= APIPlatform_Renderer::partial('documentation/code-example', [
        'id' => $id . '-request',
        'example' => [
            'title' => 'Example request',
            'language' => $request_language,
            'code' => $request
        ]
    ]);
}

if ($response) {
    $content .= APIPlatform_Renderer::partial('documentation/code-example', [
        'id' => $id . '-response',
        'example' => [
            'title' => 'Example response',
            'language' => $response_language,
            'code' => $response
        ]
    ]);
}

$content .= '</article>';

echo APIPlatform_Renderer::component('card', [
    'class' => 'apiplatform-docs-card apiplatform-docs-endpoint-card',
    'content' => $content
]);
