<?php

$api = $api ?? [];
$methods = is_array($methods ?? null) ? $methods : ['GET'];
$keys_url = $keys_url ?? '';
$edit_url = $edit_url ?? '';
$docs_url = $docs_url ?? '';
$history_url = $history_url ?? '';

$api_id = absint($api['id'] ?? 0);
$endpoint_url = $api['endpoint_url'] ?? '';
$api_name = $api['name'] ?? '';

$content = '<div class="apiplatform-api-tester" data-api-id="' . esc_attr($api_id) . '">';

$content .= '<div class="apiplatform-api-tester-meta">';
$content .= '<div><span class="apiplatform-endpoint-label">API</span><strong>' . esc_html($api_name) . '</strong></div>';
$content .= '<div><span class="apiplatform-endpoint-label">Endpoint</span><code id="apiplatform-tester-endpoint">' . esc_html($endpoint_url) . '</code></div>';
$content .= '</div>';

$content .= '<form id="apiplatform-api-tester-form" class="apiplatform-api-tester-form">';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-tester-method">Method</label>';
$content .= '<select id="apiplatform-tester-method" name="method">';
foreach ($methods as $method) {
    $content .= '<option value="' . esc_attr($method) . '">' . esc_html($method) . '</option>';
}
$content .= '</select>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row apiplatform-api-key-row">';
$content .= '<label for="apiplatform-tester-api-key">API Key</label>';
$content .= '<div class="apiplatform-api-key-control">';
$content .= '<input id="apiplatform-tester-api-key" class="apiplatform-input" type="password" autocomplete="off" placeholder="Paste legacy or application API key for this test">';
$content .= '<button type="button" class="apiplatform-button apiplatform-button-secondary" id="apiplatform-toggle-test-key" aria-label="Show or hide API key">Show</button>';
$content .= '</div>';
$content .= '<p class="apiplatform-helper-text">Application keys are visible only when created or regenerated. Paste the stored plaintext key here to test scoped application access.</p>';
$content .= '</div>';

$content .= '<div class="apiplatform-api-tester-list" id="apiplatform-query-params">';
$content .= '<div class="apiplatform-api-tester-list-header">';
$content .= '<h3>Query Parameters</h3>';
$content .= '<button type="button" class="apiplatform-button apiplatform-button-secondary" data-add-row="query">Add Parameter</button>';
$content .= '</div>';
$content .= '<div class="apiplatform-api-tester-rows" data-rows="query"></div>';
$content .= '</div>';

$content .= '<div class="apiplatform-api-tester-list" id="apiplatform-request-headers">';
$content .= '<div class="apiplatform-api-tester-list-header">';
$content .= '<h3>Headers</h3>';
$content .= '<button type="button" class="apiplatform-button apiplatform-button-secondary" data-add-row="header">Add Header</button>';
$content .= '</div>';
$content .= '<div class="apiplatform-api-tester-rows" data-rows="header"></div>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-tester-body">Request Body</label>';
$content .= '<textarea id="apiplatform-tester-body" class="apiplatform-input" rows="8" placeholder="{&#10;  &quot;example&quot;: true&#10;}"></textarea>';
$content .= '</div>';

$content .= '<div class="apiplatform-api-tester-actions">';
$content .= '<button type="submit" class="apiplatform-button apiplatform-button-primary" id="apiplatform-send-test-request">Send Request</button>';
$content .= '</div>';

$content .= '<div class="apiplatform-api-tester-error" id="apiplatform-api-tester-error" role="alert"></div>';
$content .= '</form>';

$content .= '<div class="apiplatform-api-tester-result" id="apiplatform-api-tester-result">';
$content .= '<div class="apiplatform-api-tester-result-summary">';
$content .= '<span>Status: <strong id="apiplatform-test-status">Not sent</strong></span>';
$content .= '<span>Response time: <strong id="apiplatform-test-time">-</strong></span>';
$content .= '</div>';
$content .= '<h3>Response Headers</h3>';
$content .= '<pre id="apiplatform-test-headers">{}</pre>';
$content .= '<h3>Response Body</h3>';
$content .= '<pre id="apiplatform-test-body">Send a request to see the response.</pre>';
$content .= '<div class="apiplatform-api-tester-actions">';
$content .= APIPlatform_Renderer::component('button', [
    'type' => 'secondary',
    'label' => 'Copy Response',
    'html_type' => 'button',
    'class' => 'apiplatform-copy-value apiplatform-copy-response',
    'data_attrs' => [
        'copy-value' => '',
        'copy-success' => 'Copied!'
    ]
]);
$content .= APIPlatform_Renderer::component('button', [
    'type' => 'secondary',
    'label' => 'Copy curl',
    'html_type' => 'button',
    'class' => 'apiplatform-copy-value apiplatform-copy-curl',
    'data_attrs' => [
        'copy-value' => '',
        'copy-success' => 'Copied!'
    ]
]);
$content .= '</div>';
$content .= '</div>';

$content .= '<div class="apiplatform-api-tester-footer">';
if ($edit_url) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($edit_url) . '">Edit API</a>';
}
if ($docs_url) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($docs_url) . '">Documentation</a>';
}
if ($history_url) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($history_url) . '">Request History</a>';
}
if ($keys_url) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($keys_url) . '">Back to Keys</a>';
}
$content .= '</div>';

$content .= '</div>';

echo APIPlatform_Renderer::component('card', [
    'title' => 'API Tester',
    'class' => 'apiplatform-api-tester-card',
    'content' => $content
]);
