<?php

$api = is_array($api ?? null) ? $api : [];
$actions = is_array($actions ?? null) ? $actions : [];
$authentication = is_array($authentication ?? null) ? $authentication : [];
$parameters = is_array($parameters ?? null) ? $parameters : [];
$headers = is_array($headers ?? null) ? $headers : [];
$body_example = is_array($body_example ?? null) ? $body_example : [];
$code_examples = is_array($code_examples ?? null) ? $code_examples : [];
$example_response = is_array($example_response ?? null) ? $example_response : [];
$errors = is_array($errors ?? null) ? $errors : [];
$rate_limit = is_array($rate_limit ?? null) ? $rate_limit : [];
$schema_generated = !empty($schema_generated);

$endpoint_id = 'apiplatform-doc-endpoint-' . absint($api['id'] ?? 0);
$auth_id = 'apiplatform-doc-auth-' . absint($api['id'] ?? 0);
$response_id = 'apiplatform-doc-response-' . absint($api['id'] ?? 0);
$body_id = 'apiplatform-doc-body-' . absint($api['id'] ?? 0);

$content = '<div class="apiplatform-api-documentation">';

$content .= '<header class="apiplatform-api-doc-header">';
$content .= '<div>';
$content .= '<h2>' . esc_html($api['name'] ?? 'API') . '</h2>';
$content .= '<p>' . esc_html(($api['description'] ?? '') !== '' ? $api['description'] : 'No API description configured.') . '</p>';
$content .= '</div>';
$content .= '<div class="apiplatform-api-doc-badges">';
$content .= '<span>Status: <strong>' . esc_html(ucfirst($api['status'] ?? 'active')) . '</strong></span>';
$content .= '<span>Slug: <code>' . esc_html($api['slug'] ?? '') . '</code></span>';
$content .= '<span>Method: <strong>' . esc_html(implode(', ', $api['methods'] ?? ['GET'])) . '</strong></span>';
$content .= '<span>Authentication: <code>Authorization: Bearer</code></span>';
$content .= '</div>';
$content .= '</header>';

if ($schema_generated) {
    $content .= '<section class="apiplatform-api-doc-section"><p><strong>Auto-generated:</strong> Endpoint tables, examples, and validation notes are generated from the canonical endpoint schema for this API version.</p></section>';
}

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Endpoint</h3>';
$content .= '<code id="' . esc_attr($endpoint_id) . '" class="apiplatform-doc-endpoint">' . esc_html($api['endpoint_url'] ?? '') . '</code>';
$content .= '<div class="apiplatform-api-doc-actions">';
$content .= APIPlatform_Renderer::component('button', [
    'type' => 'secondary',
    'label' => 'Copy Endpoint',
    'html_type' => 'button',
    'class' => 'apiplatform-copy-value',
    'data_attrs' => [
        'copy-value' => $api['endpoint_url'] ?? '',
        'copy-source' => $endpoint_id,
        'copy-success' => 'Copied!'
    ]
]);
if (!empty($actions['tester_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => 'Open API Tester',
        'url' => $actions['tester_url'],
        'class' => 'apiplatform-open-api-tester'
    ]);
}
if (!empty($actions['edit_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => 'Edit API',
        'url' => $actions['edit_url'],
        'class' => 'apiplatform-edit-api-link'
    ]);
}
if (!empty($actions['history_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => 'Request History',
        'url' => $actions['history_url'],
        'class' => 'apiplatform-request-history-link'
    ]);
}
$content .= '</div>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Quick Start</h3>';
$content .= '<p>Send requests to the canonical endpoint using <code>Authorization: Bearer YOUR_API_KEY</code>. <code>X-API-Key</code> is an alternative; query-string authentication is compatibility-only. Examples below use <code>' . esc_html($schema_generated ? 'YOUR_APPLICATION_KEY' : 'YOUR_API_KEY') . '</code> as a placeholder.</p>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Authentication</h3>';
$content .= '<div class="apiplatform-api-doc-code-line">';
$content .= '<code id="' . esc_attr($auth_id) . '">' . esc_html($authentication['header'] ?? 'Authorization: Bearer YOUR_API_KEY') . '</code>';
$content .= APIPlatform_Renderer::component('button', [
    'type' => 'secondary',
    'label' => 'Copy Authentication Header',
    'html_type' => 'button',
    'class' => 'apiplatform-copy-value',
    'data_attrs' => [
        'copy-value' => $authentication['header'] ?? 'Authorization: Bearer YOUR_API_KEY',
        'copy-source' => $auth_id,
        'copy-success' => 'Copied!'
    ]
]);
$content .= '</div>';
$content .= '<p>Bearer authentication is also supported for compatibility:</p>';
$content .= '<pre><code>' . esc_html($authentication['bearer'] ?? 'Authorization: Bearer YOUR_API_KEY') . '</code></pre>';
$content .= '<h4>Legacy Authentication</h4>';
$content .= '<pre><code>' . esc_html($authentication['legacy_url'] ?? '') . '</code></pre>';
$content .= '<p><strong>Security warning:</strong> Bearer and X-API-Key headers keep credentials out of URLs. Query-string keys can appear in browser history, proxy logs, analytics, and server logs and are compatibility-only.</p>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Request Parameters</h3>';
if ($parameters) {
    $content .= '<div class="apiplatform-api-doc-table-wrap"><table class="apiplatform-api-doc-table">';
    $content .= '<thead><tr><th>Name</th><th>Location</th><th>Type</th><th>Required</th><th>Default</th><th>Description</th><th>Example</th></tr></thead><tbody>';
    foreach ($parameters as $parameter) {
        $content .= '<tr>';
        $content .= '<td><code>' . esc_html($parameter['name'] ?? '') . '</code></td>';
        $content .= '<td>' . esc_html($parameter['location'] ?? '') . '</td>';
        $content .= '<td>' . esc_html($parameter['type'] ?? '') . '</td>';
        $content .= '<td>' . (!empty($parameter['required']) ? 'Yes' : 'No') . '</td>';
        $content .= '<td>' . esc_html($parameter['default'] ?? '') . '</td>';
        $content .= '<td>' . esc_html($parameter['description'] ?? '') . '</td>';
        $content .= '<td>' . esc_html($parameter['example'] ?? '') . '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div>';
} else {
    $content .= '<p>This API does not currently define any documented request parameters.</p>';
}
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Required Headers</h3>';
if ($headers) {
    $content .= '<div class="apiplatform-api-doc-table-wrap"><table class="apiplatform-api-doc-table">';
    $content .= '<thead><tr><th>Name</th><th>Value</th><th>Description</th></tr></thead><tbody>';
    foreach ($headers as $header) {
        if (!is_array($header)) {
            continue;
        }
        $content .= '<tr>';
        $content .= '<td><code>' . esc_html($header['name'] ?? '') . '</code></td>';
        $content .= '<td><code>' . esc_html($header['value'] ?? '') . '</code></td>';
        $content .= '<td>' . esc_html($header['description'] ?? '') . '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table></div>';
} else {
    $content .= '<p>No additional required headers are documented beyond <code>Authorization: Bearer</code> (or <code>X-API-Key</code>) and <code>Accept</code>.</p>';
}
$content .= '</section>';

if (!empty($body_example['enabled'])) {
    $content .= '<section class="apiplatform-api-doc-section">';
    $content .= '<h3>Request Body</h3>';
    $content .= '<p>Expected content type: <code>' . esc_html($body_example['content_type'] ?? 'application/json') . '</code></p>';
    $content .= '<p>' . esc_html($body_example['source'] ?? '') . '</p>';
    $content .= APIPlatform_Renderer::partial('documentation/code-example', [
        'id' => $body_id,
        'example' => [
            'title' => 'Example JSON request',
            'language' => 'json',
            'code' => $body_example['example'] ?? '{}'
        ]
    ]);
    $content .= '</section>';
} else {
    $content .= '<section class="apiplatform-api-doc-section">';
    $content .= '<h3>Request Body</h3>';
    $content .= '<p>' . esc_html($body_example['source'] ?? 'No request body required.') . '</p>';
    $content .= '</section>';
}

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Code Examples</h3>';
$content .= '<div class="apiplatform-doc-tabs">';
$content .= '<div class="apiplatform-doc-tab-list" role="tablist">';
$first = true;
foreach ($code_examples as $key => $example) {
    $content .= '<button type="button" class="apiplatform-doc-tab' . ($first ? ' is-active' : '') . '" data-doc-tab="' . esc_attr($key) . '">' . esc_html($example['title'] ?? $key) . '</button>';
    $first = false;
}
$content .= '</div>';
$first = true;
foreach ($code_examples as $key => $example) {
    $content .= '<div class="apiplatform-doc-tab-panel' . ($first ? ' is-active' : '') . '" data-doc-panel="' . esc_attr($key) . '">';
    $content .= APIPlatform_Renderer::partial('documentation/code-example', [
        'id' => 'apiplatform-doc-code-' . esc_attr($key) . '-' . absint($api['id'] ?? 0),
        'example' => $example
    ]);
    $content .= '</div>';
    $first = false;
}
$content .= '</div>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Example Response</h3>';
$content .= '<p>' . esc_html($example_response['label'] ?? 'Example response') . '</p>';
$content .= APIPlatform_Renderer::partial('documentation/code-example', [
    'id' => $response_id,
    'example' => [
        'title' => 'Example JSON response',
        'language' => 'json',
        'code' => $example_response['code'] ?? '{}'
    ]
]);
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Errors</h3>';
$content .= '<div class="apiplatform-api-doc-table-wrap"><table class="apiplatform-api-doc-table">';
$content .= '<thead><tr><th>Status</th><th>Code</th><th>Meaning</th><th>Resolution</th><th>Example</th></tr></thead><tbody>';
foreach ($errors as $error) {
    $example = wp_json_encode([
        'code' => $error['code'] ?? 'error',
        'message' => $error['meaning'] ?? 'Request failed.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $content .= '<tr>';
    $content .= '<td>' . esc_html((string) ($error['status'] ?? '')) . '</td>';
    $content .= '<td><code>' . esc_html($error['code'] ?? '') . '</code></td>';
    $content .= '<td>' . esc_html($error['meaning'] ?? '') . '</td>';
    $content .= '<td>' . esc_html($error['resolution'] ?? '') . '</td>';
    $content .= '<td><pre><code>' . esc_html($example) . '</code></pre></td>';
    $content .= '</tr>';
}
$content .= '</tbody></table></div>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>Rate Limits</h3>';
$content .= '<p>' . esc_html($rate_limit['summary'] ?? 'No custom rate-limit metadata is configured.') . '</p>';
if (!empty($rate_limit['scope'])) {
    $content .= '<p>Scope: <code>' . esc_html($rate_limit['scope']) . '</code></p>';
}
if (!empty($rate_limit['window'])) {
    $content .= '<p>Window: ' . esc_html($rate_limit['window']) . '</p>';
}
$content .= '<p>Exceeded requests return <code>' . esc_html($rate_limit['status'] ?? '429 rate_limit_exceeded') . '</code>.</p>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section">';
$content .= '<h3>API Status</h3>';
$content .= '<p>When disabled, this endpoint returns HTTP 403 with the <code>api_disabled</code> error code.</p>';
$content .= '</section>';

$content .= '<section class="apiplatform-api-doc-section apiplatform-api-doc-actions">';
if (!empty($actions['tester_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'primary',
        'label' => 'Open in API Tester',
        'url' => $actions['tester_url'],
        'class' => 'apiplatform-open-api-tester'
    ]);
}
if (!empty($actions['keys_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => 'Back to Keys',
        'url' => $actions['keys_url'],
        'class' => 'apiplatform-back-to-keys'
    ]);
}
if (!empty($actions['history_url'])) {
    $content .= APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => 'Request History',
        'url' => $actions['history_url'],
        'class' => 'apiplatform-request-history-link'
    ]);
}
$content .= '</section>';

$content .= '</div>';

echo APIPlatform_Renderer::component('card', [
    'title' => 'Developer Documentation',
    'class' => 'apiplatform-api-documentation-card',
    'content' => $content
]);
