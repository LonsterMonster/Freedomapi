<?php

$filters = is_array($filters ?? null) ? $filters : [];
$apis = is_array($apis ?? null) ? $apis : [];
$logs = is_array($logs ?? null) ? $logs : [];
$stats = is_array($stats ?? null) ? $stats : [];
$charts = is_array($charts ?? null) ? $charts : [];
$trends = is_array($trends ?? null) ? $trends : [];
$card_actions = is_array($card_actions ?? null) ? $card_actions : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$export_urls = is_array($export_urls ?? null) ? $export_urls : [];
$supports_details = is_array($supports_details ?? null) ? $supports_details : [];

if (!function_exists('apiplatform_request_history_chart')) {
function apiplatform_request_history_chart($title, array $rows, $label_key, $value_key, $suffix = ''){
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int) ($row[$value_key] ?? 0));
    }

    $html = '<div class="apiplatform-request-history-chart"><h3 class="apiplatform-request-history-chart-title">' . esc_html($title) . '</h3>';

    if (!$rows) {
        return $html . '<p>No data found.</p></div>';
    }

    foreach ($rows as $index => $row) {
        $value = (int) ($row[$value_key] ?? 0);
        $width = $max ? max(4, (int) round(($value / $max) * 100)) : 4;
        $filter_url = (string) ($row['filter_url'] ?? '');
        $tooltip = (string) ($row['tooltip'] ?? '');
        $label = (string) ($row[$label_key] ?? '');
        $tag = $filter_url !== '' ? 'a' : 'div';
        $attrs = $filter_url !== ''
            ? ' href="' . esc_url($filter_url) . '" data-history-filter-url="' . esc_url($filter_url) . '" aria-label="' . esc_attr('Filter Request History by ' . $label) . '"'
            : '';
        $tooltip_id = 'apiplatform-request-history-tooltip-' . sanitize_title($title) . '-' . absint($index);

        if ($tooltip !== '') {
            $attrs .= ' aria-describedby="' . esc_attr($tooltip_id) . '"';
        }

        $html .= '<' . $tag . $attrs . ' class="apiplatform-request-history-bar' . ($filter_url !== '' ? ' is-clickable' : '') . '">';
        if ($label_key === 'api') {
            $html .= apiplatform_request_history_api_identity($label, $row['initials'] ?? '', $row['tone'] ?? '');
        } else {
            $html .= '<span class="apiplatform-request-history-chart-label">' . esc_html($label) . '</span>';
        }
        $html .= '<progress max="100" value="' . esc_attr((string) $width) . '"></progress>';
        $html .= '<strong class="apiplatform-request-history-chart-meta">' . esc_html(number_format($value) . $suffix) . '</strong>';
        if ($tooltip !== '') {
            $html .= '<span id="' . esc_attr($tooltip_id) . '" class="apiplatform-request-history-chart-tooltip" role="tooltip">' . nl2br(esc_html($tooltip)) . '</span>';
        }
        $html .= '</' . $tag . '>';
    }

    return $html . '</div>';
}
}

if (!function_exists('apiplatform_request_history_api_identity')) {
function apiplatform_request_history_api_identity($name, $initials = '', $tone = ''){
    $initials = $initials !== '' ? $initials : substr((string) $name, 0, 2);
    $tone = sanitize_html_class($tone ?: 'blue');

    return '<span class="apiplatform-api-identity">' .
        '<span class="apiplatform-api-avatar apiplatform-api-avatar--' . esc_attr($tone) . '" aria-hidden="true">' . esc_html(strtoupper(substr((string) $initials, 0, 3))) . '</span>' .
        '<span class="apiplatform-api-name" title="' . esc_attr($name) . '">' . esc_html($name) . '</span>' .
        '</span>';
}
}

if (!function_exists('apiplatform_request_history_transport_label')) {
function apiplatform_request_history_transport_label($value){
    $labels = ['authorization_bearer' => 'Authorization Bearer', 'x_api_key' => 'X-API-Key', 'query' => 'Query Parameter', 'application' => 'Application', 'public' => 'Public'];
    return $labels[$value] ?? ($value === 'Not recorded' ? 'Not recorded' : 'Unknown');
}
}

if (!function_exists('apiplatform_request_history_copy')) {
function apiplatform_request_history_copy($label, $value, $success){
    return APIPlatform_Renderer::component('button', [
        'type' => 'secondary',
        'label' => $label,
        'html_type' => 'button',
        'class' => 'apiplatform-copy-value',
        'data_attrs' => [
            'copy-value' => $value,
            'copy-success' => $success,
        ],
    ]);
}
}

$content = '<div class="apiplatform-request-history">';

$content .= '<form method="get" class="apiplatform-request-history-filters">';
if (current_user_can('manage_options')) {
    $content .= '<input type="hidden" name="apipage" value="request-history">';
}
$content .= '<div class="apiplatform-filter-grid">';
$content .= '<label>API<select name="api_id"><option value="0">All APIs</option>';
foreach ($apis as $api) {
    $content .= '<option value="' . esc_attr($api['id']) . '"' . selected((int) ($filters['api_id'] ?? 0), (int) $api['id'], false) . '>' . esc_html($api['name']) . '</option>';
}
$content .= '</select></label>';
$content .= '<label>Status<input type="number" name="status" value="' . esc_attr($filters['status'] ?? '') . '" placeholder="200, 403, 500"></label>';
$content .= '<label>Method<select name="method">';
foreach (['' => 'All Methods', 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'] as $value => $label) {
    $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['method'] ?? '', $value, false) . '>' . esc_html($label) . '</option>';
}
$content .= '</select></label>';
$content .= '<label>Result<select name="result">';
foreach (['' => 'All Results', 'success' => 'Success', 'failed' => 'Failed'] as $value => $label) {
    $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['result'] ?? '', $value, false) . '>' . esc_html($label) . '</option>';
}
$content .= '</select></label>';
$auth_filter_enabled = !empty($supports_details['auth_type']);
$content .= '<label>Authentication';
$content .= '<select name="auth_type"' . disabled(!$auth_filter_enabled, true, false) . '>';
if (!$auth_filter_enabled) {
    $content .= '<option value="">Not captured by logger</option>';
} else {
    foreach (['' => 'All Types', 'current' => 'Current Key', 'legacy' => 'Legacy Compatibility', 'application' => 'Application Key'] as $value => $label) {
        $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['auth_type'] ?? '', $value, false) . '>' . esc_html($label) . '</option>';
    }
}
$content .= '</select>';
if (!$auth_filter_enabled) {
    $content .= '<small>Authentication type is unavailable until structured auth metadata is logged.</small>';
}
$content .= '</label>';
    $content .= '<label>Auth Transport<select name="auth_transport">';
    foreach (['' => 'All Transports', 'authorization_bearer' => 'Authorization Bearer', 'x_api_key' => 'X-API-Key', 'query' => 'Query Parameter', 'application' => 'Application', 'public' => 'Public'] as $value => $label) {
        $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['auth_transport'] ?? '', $value, false) . '>' . esc_html($label) . '</option>';
    }
    $content .= '</select></label>';
$content .= '<label>Date Range<select name="range">';
foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days', 'custom' => 'Custom', 'all' => 'All time'] as $value => $label) {
    $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['range'] ?? '30', $value, false) . '>' . esc_html($label) . '</option>';
}
$content .= '</select></label>';
$content .= '<label>From<input type="date" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '"></label>';
$content .= '<label>To<input type="date" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '"></label>';
$content .= '<label>Search<input type="search" name="s" value="' . esc_attr($filters['search'] ?? '') . '" placeholder="API, slug, status, request ID"></label>';
$content .= '<label>Sort<select name="order">';
foreach (['newest' => 'Newest', 'oldest' => 'Oldest'] as $value => $label) {
    $content .= '<option value="' . esc_attr($value) . '"' . selected($filters['order'] ?? 'newest', $value, false) . '>' . esc_html($label) . '</option>';
}
$content .= '</select></label>';
$content .= '<label>Rows<select name="per_page">';
foreach ([25, 50, 100] as $per_page) {
    $content .= '<option value="' . esc_attr($per_page) . '"' . selected((int) ($filters['per_page'] ?? 25), $per_page, false) . '>' . esc_html((string) $per_page) . '</option>';
}
$content .= '</select></label>';
$content .= '</div>';
$content .= '<div class="apiplatform-request-history-actions">';
$content .= '<button type="submit" class="apiplatform-button apiplatform-button-primary">Apply Filters</button>';
if (!empty($export_urls['csv'])) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($export_urls['csv']) . '">Export CSV</a>';
}
if (!empty($export_urls['json'])) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($export_urls['json']) . '">Export JSON</a>';
}
$content .= '</div>';
$content .= '</form>';

$most_used_api = is_array($stats['most_used_api'] ?? null) ? $stats['most_used_api'] : [];
$cards = [
    ['key' => 'total', 'label' => 'Total Requests', 'value' => number_format((int) ($stats['total'] ?? 0))],
    ['key' => 'successful', 'label' => 'Successful', 'value' => number_format((int) ($stats['successful'] ?? 0))],
    ['key' => 'failed', 'label' => 'Failed', 'value' => number_format((int) ($stats['failed'] ?? 0))],
    ['key' => 'avg_time_ms', 'label' => 'Average Time', 'value' => number_format((int) ($stats['avg_time_ms'] ?? 0)) . ' ms'],
    ['key' => 'status_429', 'label' => '429 Responses', 'value' => number_format((int) ($stats['status_429'] ?? 0))],
    ['key' => 'status_401', 'label' => '401 Responses', 'value' => number_format((int) ($stats['status_401'] ?? 0))],
    ['key' => 'status_403', 'label' => '403 Responses', 'value' => number_format((int) ($stats['status_403'] ?? 0))],
    ['key' => 'status_500', 'label' => '500 Responses', 'value' => number_format((int) ($stats['status_500'] ?? 0))],
    ['key' => 'most_used_api', 'label' => 'Most Used API', 'value' => $most_used_api ?: 'None'],
    ['key' => 'last_request', 'label' => 'Last Request', 'value' => $stats['last_request'] ?? 'Never'],
    ['key' => 'today', 'label' => 'Today', 'value' => number_format((int) ($stats['today'] ?? 0))],
];

$stat_items = [];
foreach ($cards as $card) {
    $action = $card_actions[$card['label']] ?? [];
    $trend = $trends[$card['key']] ?? null;
    $inner =
        '<small class="apiplatform-request-history-stat-label">' . esc_html($card['label']) . '</small>' .
        '<strong class="apiplatform-request-history-stat-value">';

    if ($card['key'] === 'most_used_api' && is_array($card['value'])) {
        $inner .= apiplatform_request_history_api_identity($card['value']['name'] ?? 'Unknown API', $card['value']['initials'] ?? '', $card['value']['tone'] ?? '');
    } else {
        $inner .= esc_html((string) $card['value']);
    }

    $inner .= '</strong>';

    if (is_array($trend)) {
        $trend_class = sanitize_html_class($trend['class'] ?? 'neutral');
        $inner .= '<span class="apiplatform-request-history-trend apiplatform-request-history-trend--' . esc_attr($trend_class) . '">' . esc_html($trend['label'] ?? 'Not enough data') . '</span>';
    }

    if (!empty($action['url'])) {
        $stat_items[] =
            '<a class="apiplatform-request-history-stat is-clickable" href="' . esc_url($action['url']) . '" data-history-filter-url="' . esc_url($action['url']) . '" aria-label="' . esc_attr($action['aria'] ?? ('Filter by ' . $card['label'])) . '">' .
            $inner .
            '</a>';
    } else {
        $stat_items[] =
            '<div class="apiplatform-request-history-stat">' .
            $inner .
            '</div>';
    }
}
$content .= '<div class="apiplatform-request-history-stats">';
$content .= APIPlatform_Renderer::component('grid', ['columns' => 3, 'items' => $stat_items]);
$content .= '</div>';

$content .= '<div class="apiplatform-request-history-charts">';
$content .= apiplatform_request_history_chart('Requests Over Time', $charts['requests_over_time'] ?? [], 'day', 'total');
$content .= apiplatform_request_history_chart('Status Breakdown', $charts['status_breakdown'] ?? [], 'status', 'total');
$content .= apiplatform_request_history_chart('Top APIs', $charts['top_apis'] ?? [], 'api', 'total');
$content .= apiplatform_request_history_chart('Average Response Time', $charts['avg_response_time'] ?? [], 'day', 'avg_time', ' ms');
$content .= '</div>';

if (!$logs) {
    $empty = 'No requests yet.';
    if (($filters['result'] ?? '') === 'failed') {
        $empty = 'No failed requests match these filters.';
    } elseif (($filters['range'] ?? '') !== 'all') {
        $empty = 'No requests in the selected date range.';
    }
    $content .= APIPlatform_Renderer::component('alert', ['type' => 'info', 'content' => $empty]);
} else {
    $content .= '<div class="apiplatform-request-history-table-wrap"><table class="apiplatform-request-history-table">';
    $content .= '<thead><tr><th></th><th>Date / Time</th><th>API</th><th>Slug</th><th>Method</th><th>Status</th><th>Time</th><th>Result</th><th>Client IP</th><th>Auth Type</th><th>Auth Transport</th><th>Response Contract</th><th>Rate Limit</th><th>Request ID</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $details_id = 'apiplatform-request-history-details-' . absint($log['id']);
        $result_class = strtolower($log['result']) === 'success' ? 'status-ok' : 'status-fail';
        $content .= '<tr>';
        $content .= '<td><button type="button" class="apiplatform-request-history-toggle" data-history-toggle="' . esc_attr($details_id) . '" aria-controls="' . esc_attr($details_id) . '" aria-expanded="false">Inspect</button></td>';
        $content .= '<td>' . esc_html($log['created_at']) . '</td>';
        $content .= '<td class="apiplatform-request-history-api-cell">' . apiplatform_request_history_api_identity($log['api_name'], $log['api_initials'] ?? '', $log['api_tone'] ?? '') . '</td>';
        $content .= '<td><code>' . esc_html($log['api_slug']) . '</code></td>';
        $content .= '<td><code>' . esc_html($log['method']) . '</code></td>';
        $content .= '<td><span class="apiplatform-http-status ' . esc_attr($log['status_class'] ?? 'apiplatform-http-status--unknown') . '">' . esc_html((string) $log['status']) . '</span></td>';
        $content .= '<td>' . esc_html((string) $log['response_time_ms']) . ' ms</td>';
        $content .= '<td><span class="' . esc_attr($result_class) . '">' . esc_html($log['result']) . '</span></td>';
        $content .= '<td>' . esc_html($log['client_ip_masked']) . '</td>';
        $content .= '<td>' . esc_html($log['auth_type']) . '</td>';
        $content .= '<td>' . esc_html(apiplatform_request_history_transport_label($log['auth_transport'] ?? 'Not recorded')) . '</td>';
        $content .= '<td>' . esc_html($log['rate_limit_result']) . '</td>';
        $content .= '<td>' . esc_html(($log['response_validation_status_label'] ?? 'Not Recorded') . ' / ' . ($log['response_validation_mode_label'] ?? 'Not Recorded')) . '</td>';
        $content .= '<td><code id="apiplatform-request-id-' . esc_attr($log['id']) . '">' . esc_html($log['request_id']) . '</code></td>';
        $content .= '</tr>';
        $content .= '<tr id="' . esc_attr($details_id) . '" class="apiplatform-request-history-details-row">';
        $content .= '<td colspan="14">';
        $content .= '<div class="apiplatform-request-history-details apiplatform-request-history-inspector">';
        $content .= '<div><h3>Authentication</h3>';
        $content .= '<p>Auth Type: <code>' . esc_html($log['auth_type']) . '</code></p>';
        $content .= '<p>Auth Transport: <code>' . esc_html(apiplatform_request_history_transport_label($log['auth_transport'] ?? 'Not recorded')) . '</code></p>';
        $content .= '<p>Response Contract: <code>' . esc_html(($log['response_validation_status_label'] ?? 'Not Recorded') . ' / ' . ($log['response_validation_mode_label'] ?? 'Not Recorded')) . '</code></p>';
        if (!empty($log['response_validation_errors']) && is_array($log['response_validation_errors'])) {
            $content .= '<p>Issues:</p><ul>';
            foreach (array_slice($log['response_validation_errors'], 0, 10) as $issue) {
                $issue_text = ($issue['path'] ?? '') . ' — ' . ($issue['reason'] ?? 'mismatch');
                if (!empty($issue['expected'])) $issue_text .= ', expected ' . sanitize_text_field($issue['expected']);
                if (!empty($issue['received'])) $issue_text .= ', received ' . sanitize_text_field($issue['received']);
                $content .= '<li><code>' . esc_html($issue_text) . '</code></li>';
            }
            $content .= '</ul>';
        }
        $content .= '</div>';
        $content .= '<div><h3>Request</h3>';
        $content .= '<p>Method: <code>' . esc_html($log['method']) . '</code></p>';
        $content .= '<p>Endpoint: <code id="apiplatform-request-endpoint-' . esc_attr($log['id']) . '">' . esc_html($log['endpoint_url']) . '</code></p>';
        $content .= apiplatform_request_history_copy('Copy Endpoint', $log['endpoint_url'], 'Endpoint copied');
        $content .= '<h4>Headers</h4><pre><code>' . esc_html($log['request_headers']) . '</code></pre>';
        $content .= '<h4>Query Parameters</h4><pre><code>' . esc_html($log['query_params']) . '</code></pre>';
        $content .= '<h4>JSON Body</h4><pre id="apiplatform-request-json-' . esc_attr($log['id']) . '"><code>' . esc_html($log['request_body']) . '</code></pre>';
        $content .= apiplatform_request_history_copy('Copy Request JSON', $log['request_body'], 'Copied!');
        $content .= '<h4>User Agent</h4><pre id="apiplatform-user-agent-' . esc_attr($log['id']) . '"><code>' . esc_html($log['user_agent']) . '</code></pre>';
        $content .= apiplatform_request_history_copy('Copy User Agent', $log['user_agent'], 'Copied!');
        $content .= '</div>';
        $content .= '<div><h3>Response</h3>';
        $content .= '<p>Status: <code>' . esc_html((string) $log['status']) . '</code></p>';
        $content .= '<p>Execution time: <code>' . esc_html((string) $log['response_time_ms']) . ' ms</code></p>';
        $content .= '<h4>Headers</h4><pre><code>' . esc_html($log['response_headers']) . '</code></pre>';
        $content .= '<h4>JSON Body</h4><pre id="apiplatform-response-json-' . esc_attr($log['id']) . '"><code>' . esc_html($log['response_body']) . '</code></pre>';
        $content .= apiplatform_request_history_copy('Copy Response JSON', $log['response_body'], 'Copied!');
        if ($log['error_code']) {
            $error_json = wp_json_encode(['code' => $log['error_code'], 'message' => $log['error_message']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $content .= '<h4>Error</h4><pre id="apiplatform-error-json-' . esc_attr($log['id']) . '"><code>' . esc_html($error_json) . '</code></pre>';
            $content .= apiplatform_request_history_copy('Copy Error JSON', $error_json, 'Copied!');
        }
        $content .= '<h4>Request ID</h4><p><code>' . esc_html($log['request_id']) . '</code></p>';
        $content .= apiplatform_request_history_copy('Copy Request ID', $log['request_id'], 'Copied!');
        $content .= '</div>';
        $content .= '</div>';
        $content .= '</td></tr>';
    }
    $content .= '</tbody></table></div>';
}

$content .= '<div class="apiplatform-request-history-pagination">';
$content .= '<span>Page ' . esc_html((string) ($pagination['current'] ?? 1)) . ' of ' . esc_html((string) ($pagination['pages'] ?? 1)) . ' - ' . esc_html(number_format((int) ($pagination['total'] ?? 0))) . ' requests</span>';
if (!empty($pagination['prev_url'])) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($pagination['prev_url']) . '">Previous</a>';
}
if (!empty($pagination['next_url'])) {
    $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($pagination['next_url']) . '">Next</a>';
}
$content .= '</div>';

$content .= '</div>';

echo APIPlatform_Renderer::component('card', [
    'title' => 'Request Log Explorer',
    'class' => 'apiplatform-request-history-card',
    'content' => $content
]);
