<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Request_History {

    private $per_page_options = [25, 50, 100];

    public function __construct(){
        add_action('template_redirect', [$this, 'maybe_export']);
        add_shortcode('api_request_history_page', [$this, 'render']);
    }

    public function render(){
        if (!is_user_logged_in()) {
            return APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'Login required to view request history.'
            ]);
        }

        APIPlatform_Frontend_Assets::enqueue_request_history();

        if (!$this->table_exists()) {
            return $this->render_page(APIPlatform_Renderer::component('alert', [
                'type' => 'info',
                'content' => 'No request history table is available yet.'
            ]));
        }

        $user_id = get_current_user_id();
        $filters = $this->filters();
        $apis = $this->available_apis($user_id);

        if (!$this->requested_api_allowed($filters['api_id'], $apis)) {
            return $this->render_page(APIPlatform_Renderer::component('alert', [
                'type' => 'error',
                'content' => 'You cannot view request history for that API.'
            ]));
        }

        $columns = $this->table_columns();
        $query = $this->query_parts($filters, $apis, $columns, false);
        $rows = $this->logs($query, $filters);
        $total = $this->total_count($query);
        $stats = $this->stats($query, $apis);
        $charts = $this->decorate_charts($this->charts($query, $apis), $filters, (int) ($stats['total'] ?? 0));
        $trends = $this->trends($filters, $apis, $columns, $stats);

        $content = APIPlatform_Renderer::partial('request-history', [
            'filters' => $filters,
            'apis' => $apis,
            'logs' => $rows,
            'total' => $total,
            'stats' => $stats,
            'charts' => $charts,
            'trends' => $trends,
            'card_actions' => $this->card_actions($filters, $stats),
            'pagination' => $this->pagination($filters, $total),
            'export_urls' => $this->export_urls($filters),
            'supports_details' => $this->detail_support($columns),
        ]);

        return $this->render_page($content);
    }

    public function maybe_export(){
        if (!is_user_logged_in() || empty($_GET['apiplatform_request_history_export'])) {
            return;
        }

        $format = sanitize_key(wp_unslash($_GET['apiplatform_request_history_export']));

        if (!in_array($format, ['csv', 'json'], true)) {
            return;
        }

        $nonce = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
            : '';

        if (!wp_verify_nonce($nonce, 'apiplatform_request_history_export')) {
            wp_die('Request history export failed security validation.');
        }

        if (!$this->table_exists()) {
            wp_die('No request history table is available.');
        }

        $user_id = get_current_user_id();
        $filters = $this->filters();
        $apis = $this->available_apis($user_id);

        if (!$this->requested_api_allowed($filters['api_id'], $apis)) {
            wp_die('You cannot export request history for that API.');
        }

        $query = $this->query_parts($filters, $apis, $this->table_columns(), false);
        $rows = $this->logs($query, [
            'per_page' => 5000,
            'page' => 1,
            'order' => $filters['order'],
        ]);
        $export = array_map(function($row){
            return [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'api_name' => $row['api_name'],
                'api_slug' => $row['api_slug'],
                'method' => $row['method'],
                'endpoint' => $row['endpoint'],
                'status' => $row['status'],
                'result' => $row['result'],
                'response_time_ms' => $row['response_time_ms'],
                'client_ip' => $row['client_ip_masked'],
                'authentication_type' => $row['auth_type'],
                'authentication_transport' => $row['auth_transport'] ?? '',
                'response_contract_status' => $row['response_validation_status'] ?? 'not_recorded',
                'response_contract_mode' => $row['response_validation_mode'] ?? 'not_recorded',
                'response_contract_errors' => $row['response_validation_errors'] ?? [],
                'request_headers' => $row['request_headers'] ?? '{}',
                'query_parameters' => $row['query_params'] ?? '{}',
                'request_body' => $row['request_body'] ?? '{}',
                'user_agent' => $row['user_agent'] ?? 'Not provided',
                'response_headers' => $row['response_headers'] ?? '{}',
                'response_body' => $row['response_body'] ?? '{}',
                'rate_limit_result' => $row['rate_limit_result'],
            ];
        }, $rows);

        if ($format === 'json') {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="request-history.json"');
            echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="request-history.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, array_keys($export[0] ?? [
            'id' => '',
            'created_at' => '',
            'api_name' => '',
            'api_slug' => '',
            'method' => '',
            'endpoint' => '',
            'status' => '',
            'result' => '',
            'response_time_ms' => '',
            'client_ip' => '',
            'authentication_type' => '',
            'authentication_transport' => '',
            'response_contract_status' => 'not_recorded',
            'response_contract_mode' => 'not_recorded',
            'response_contract_errors' => [],
            'request_headers' => '{}',
            'query_parameters' => '{}',
            'request_body' => '{}',
            'user_agent' => 'Not provided',
            'response_headers' => '{}',
            'response_body' => '{}',
            'rate_limit_result' => '',
        ]));

        foreach ($export as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    private function filters(){
        $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 25;

        if (!in_array($per_page, $this->per_page_options, true)) {
            $per_page = 25;
        }

        $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '30';

        if (!in_array($range, ['today', '7', '30', '90', 'custom', 'all'], true)) {
            $range = '30';
        }

        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'newest';
        $order = $order === 'oldest' ? 'oldest' : 'newest';

        $success = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';

        if (!in_array($success, ['', 'success', 'failed'], true)) {
            $success = '';
        }

        $auth_transport = isset($_GET['auth_transport']) ? sanitize_key(wp_unslash($_GET['auth_transport'])) : '';
        if (!in_array($auth_transport, ['', 'authorization_bearer', 'x_api_key', 'query', 'application', 'public'], true)) {
            $auth_transport = '';
        }

        $status = isset($_GET['status']) ? absint($_GET['status']) : 0;

        if ($status < 100 || $status > 599) {
            $status = 0;
        }

        $method = isset($_GET['method']) ? strtoupper(sanitize_key(wp_unslash($_GET['method']))) : '';

        if (!in_array($method, ['', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $method = '';
        }

        $auth_type = isset($_GET['auth_type']) ? sanitize_key(wp_unslash($_GET['auth_type'])) : '';

        if (!in_array($auth_type, ['', 'current', 'legacy', 'legacy_promoted', 'application'], true)) {
            $auth_type = '';
        }

        return [
            'api_id' => isset($_GET['api_id']) ? absint($_GET['api_id']) : 0,
            'status' => $status,
            'method' => $method,
            'result' => $success,
            'auth_type' => $auth_type,
            'auth_transport' => $auth_transport,
            'range' => $range,
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'order' => $order,
            'page' => max(1, isset($_GET['history_page']) ? absint($_GET['history_page']) : 1),
            'per_page' => $per_page,
        ];
    }

    private function available_apis($user_id){
        $args = [
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
            'no_found_rows' => true,
        ];

        if (!current_user_can('manage_options') && class_exists('APIPlatform_Organization_Permissions')) {
            $ids = APIPlatform_Organization_Permissions::user_api_ids($user_id, 'logs.view');
            $args['post__in'] = $ids ?: [0];
        } elseif (!current_user_can('manage_options')) {
            $args['author'] = $user_id;
        }

        $posts = get_posts($args);
        $apis = [];

        foreach ($posts as $api) {
            $apis[(int) $api->ID] = [
                'id' => (int) $api->ID,
                'name' => get_the_title($api),
                'slug' => $api->post_name,
                'endpoint_url' => function_exists('apiplatform_public_gateway_url') ? apiplatform_public_gateway_url($api) : (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $api->post_author), $api->post_name) : rest_url('platform/v1/api/' . $api->post_name)),
                'initials' => $this->api_initials(get_the_title($api)),
                'tone' => $this->api_tone((string) $api->ID),
            ];
        }

        return $apis;
    }

    private function requested_api_allowed($api_id, array $apis){
        return !$api_id || isset($apis[(int) $api_id]);
    }

    private function query_parts(array $filters, array $apis, array $columns, $for_chart){
        global $wpdb;

        $where = [];
        $args = [];
        $api_ids = array_map('intval', array_keys($apis));

        if ($filters['api_id']) {
            $where[] = 'api_id = %d';
            $args[] = $filters['api_id'];
        } elseif (!current_user_can('manage_options') && $api_ids) {
            $where[] = 'api_id IN (' . implode(',', array_fill(0, count($api_ids), '%d')) . ')';
            $args = array_merge($args, $api_ids);
        } elseif (!current_user_can('manage_options')) {
            $where[] = '1 = 0';
        }

        if ($filters['status']) {
            $where[] = 'status = %d';
            $args[] = $filters['status'];
        }

        if ($filters['method'] !== '') {
            $where[] = 'method = %s';
            $args[] = $filters['method'];
        }

        if ($filters['result'] === 'success') {
            $where[] = 'status >= 200 AND status < 400';
        } elseif ($filters['result'] === 'failed') {
            $where[] = 'status >= 400';
        }

        if (($filters['auth_transport'] ?? '') !== '' && in_array('auth_transport', $columns, true)) {
            $where[] = 'auth_transport = %s';
            $args[] = $filters['auth_transport'];
        }

        if ($filters['auth_type'] !== '') {
            if (in_array('auth_type', $columns, true)) {
                $where[] = 'auth_type = %s';
                $args[] = $filters['auth_type'];
            }
        }

        $date = $this->date_filter($filters);

        if ($date['from']) {
            $where[] = 'created_at >= %s';
            $args[] = $date['from'];
        }

        if ($date['to']) {
            $where[] = 'created_at <= %s';
            $args[] = $date['to'];
        }

        if ($filters['search'] !== '') {
            $search_where = [
                'endpoint LIKE %s',
                'method LIKE %s',
                'CAST(status AS CHAR) LIKE %s',
                'CAST(id AS CHAR) LIKE %s',
            ];
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $search_args = [$like, $like, $like, $like];

            if (current_user_can('manage_options')) {
                $search_where[] = 'ip LIKE %s';
                $search_args[] = $like;
            }

            if (preg_match('/^req_(\d+)$/i', $filters['search'], $matches)) {
                $search_where[] = 'id = %d';
                $search_args[] = absint($matches[1]);
            }

            if (in_array('user_agent', $columns, true)) {
                $search_where[] = 'user_agent LIKE %s';
                $search_args[] = $like;
            }

            if (in_array('error_code', $columns, true)) {
                $search_where[] = 'error_code LIKE %s';
                $search_args[] = $like;
            }

            $matching_api_ids = $this->matching_api_ids($filters['search'], $apis);

            if ($matching_api_ids) {
                $search_where[] = 'api_id IN (' . implode(',', array_fill(0, count($matching_api_ids), '%d')) . ')';
                $search_args = array_merge($search_args, $matching_api_ids);
            }

            $where[] = '(' . implode(' OR ', $search_where) . ')';
            $args = array_merge($args, $search_args);
        }

        return [
            'where_sql' => $where ? 'WHERE ' . implode(' AND ', $where) : '',
            'args' => $args,
            'order' => $filters['order'] === 'oldest' ? 'ASC' : 'DESC',
        ];
    }

    private function date_filter(array $filters){
        if ($filters['range'] === 'all') {
            return ['from' => '', 'to' => ''];
        }

        if ($filters['range'] === 'custom') {
            return [
                'from' => $this->valid_date($filters['date_from']) ? $filters['date_from'] . ' 00:00:00' : '',
                'to' => $this->valid_date($filters['date_to']) ? $filters['date_to'] . ' 23:59:59' : '',
            ];
        }

        if ($filters['range'] === 'today') {
            return [
                'from' => current_time('Y-m-d') . ' 00:00:00',
                'to' => current_time('Y-m-d') . ' 23:59:59',
            ];
        }

        $days = absint($filters['range']);

        return [
            'from' => gmdate('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS)),
            'to' => '',
        ];
    }

    private function valid_date($value){
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    private function matching_api_ids($search, array $apis){
        $ids = [];

        foreach ($apis as $api) {
            if (
                stripos($api['name'], $search) !== false ||
                stripos($api['slug'], $search) !== false
            ) {
                $ids[] = (int) $api['id'];
            }
        }

        return $ids;
    }

    private function logs(array $query, array $filters){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $limit = absint($filters['per_page']);
        $offset = (max(1, absint($filters['page'])) - 1) * $limit;
        $sql = "SELECT * FROM `{$table}` {$query['where_sql']} ORDER BY created_at {$query['order']}, id {$query['order']} LIMIT %d OFFSET %d";
        $args = array_merge($query['args'], [$limit, $offset]);
        $rows = $wpdb->get_results($this->prepared_sql($sql, $args));
        $apis = $this->apis_by_ids(array_map(function($row){ return (int) $row->api_id; }, $rows));
        $normalized = [];

        foreach ($rows as $row) {
            $api = $apis[(int) $row->api_id] ?? [
                'name' => $row->endpoint ? ucwords(str_replace('-', ' ', $row->endpoint)) : 'Unknown API',
                'slug' => (string) $row->endpoint,
                'initials' => $this->api_initials($row->endpoint ? ucwords(str_replace('-', ' ', $row->endpoint)) : 'Unknown API'),
                'tone' => $this->api_tone((string) $row->endpoint),
            ];
            $status = (int) $row->status;

            $normalized[] = [
                'id' => (int) $row->id,
                'request_id' => 'req_' . (int) $row->id,
                'created_at' => (string) $row->created_at,
                'api_id' => (int) $row->api_id,
                'api_name' => $api['name'],
                'api_slug' => $api['slug'],
                'api_initials' => $api['initials'],
                'api_tone' => $api['tone'],
                'method' => (string) $row->method,
                'endpoint' => (string) $row->endpoint,
                'endpoint_url' => !empty($api['endpoint_url']) ? $api['endpoint_url'] : (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url('', (string) $row->endpoint) : rest_url('platform/v1/api/' . (string) $row->endpoint)),
                'status' => $status,
                'status_class' => $this->status_class($status),
                'result' => $status >= 200 && $status < 400 ? 'Success' : 'Failed',
                'response_time' => (float) $row->response_time,
                'response_time_ms' => (int) round(((float) $row->response_time) * 1000),
                'client_ip_masked' => $this->mask_ip((string) $row->ip),
                'auth_type' => $this->read_optional($row, 'auth_type', 'Not captured'),
                'auth_transport' => $this->normalize_auth_transport($this->read_optional($row, 'auth_transport', '')),
                'response_validation_status' => $this->normalize_response_validation_status($this->read_optional($row, 'response_validation_status', '')),
                'response_validation_mode' => $this->normalize_response_validation_mode($this->read_optional($row, 'response_validation_mode', '')),
                'response_validation_status_label' => $this->response_validation_status_label($this->normalize_response_validation_status($this->read_optional($row, 'response_validation_status', ''))),
                'response_validation_mode_label' => $this->response_validation_mode_label($this->normalize_response_validation_mode($this->read_optional($row, 'response_validation_mode', ''))),
                'response_validation_errors' => $this->safe_validation_errors($this->read_optional($row, 'response_validation_errors', '')),
                'rate_limit_result' => $status === 429 ? 'Rate limited' : 'Not rate limited',
                'user_agent' => $this->redact($this->read_optional($row, 'user_agent', 'Not captured')),
                'request_headers' => $this->safe_json($this->read_optional($row, 'request_headers', [])),
                'query_params' => $this->safe_json($this->read_optional($row, 'query_params', [])),
                'request_body' => $this->safe_json($this->read_optional($row, 'request_body', [])),
                'response_headers' => $this->safe_json($this->read_optional($row, 'response_headers', [])),
                'response_body' => $this->safe_json($this->read_optional($row, 'response_body', [])),
                'error_code' => $this->read_optional($row, 'error_code', $this->error_code_for_status($status)),
                'error_message' => $this->read_optional($row, 'error_message', $status >= 400 ? 'Request failed.' : ''),
            ];
        }

        return $normalized;
    }

    private function total_count(array $query){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT COUNT(*) FROM `{$table}` {$query['where_sql']}";

        return (int) $wpdb->get_var($this->prepared_sql($sql, $query['args']));
    }

    private function stats(array $query, array $apis){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status >= 200 AND status < 400 THEN 1 ELSE 0 END) AS successful,
            SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed,
            AVG(response_time) AS avg_time,
            SUM(CASE WHEN status = 429 THEN 1 ELSE 0 END) AS status_429,
            SUM(CASE WHEN status = 401 THEN 1 ELSE 0 END) AS status_401,
            SUM(CASE WHEN status = 403 THEN 1 ELSE 0 END) AS status_403,
            SUM(CASE WHEN status = 500 THEN 1 ELSE 0 END) AS status_500,
            MAX(created_at) AS last_request
            FROM `{$table}` {$query['where_sql']}";
        $row = $wpdb->get_row($this->prepared_sql($sql, $query['args']), ARRAY_A) ?: [];
        $today_query = $query;
        $today_query['where_sql'] .= ($today_query['where_sql'] ? ' AND ' : 'WHERE ') . 'created_at >= %s';
        $today_query['args'][] = current_time('Y-m-d') . ' 00:00:00';
        $today = $this->total_count($today_query);
        $top_api = $this->top_api($query, $apis);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'successful' => (int) ($row['successful'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'avg_time_ms' => (int) round(((float) ($row['avg_time'] ?? 0)) * 1000),
            'status_429' => (int) ($row['status_429'] ?? 0),
            'status_401' => (int) ($row['status_401'] ?? 0),
            'status_403' => (int) ($row['status_403'] ?? 0),
            'status_500' => (int) ($row['status_500'] ?? 0),
            'most_used_api' => $top_api,
            'last_request' => $row['last_request'] ?: 'Never',
            'today' => $today,
        ];
    }

    private function top_api(array $query, array $apis){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT api_id, endpoint, COUNT(*) AS total FROM `{$table}` {$query['where_sql']} GROUP BY api_id, endpoint ORDER BY total DESC LIMIT 1";
        $row = $wpdb->get_row($this->prepared_sql($sql, $query['args']));

        if (!$row) {
            return 'None';
        }

        $fallback = ucwords(str_replace('-', ' ', (string) $row->endpoint));
        $api = $apis[(int) $row->api_id] ?? [
            'id' => (int) $row->api_id,
            'name' => $fallback,
            'slug' => (string) $row->endpoint,
            'initials' => $this->api_initials($fallback),
            'tone' => $this->api_tone((string) $row->endpoint),
        ];

        return [
            'id' => (int) ($api['id'] ?? $row->api_id),
            'name' => $api['name'],
            'slug' => $api['slug'] ?? (string) $row->endpoint,
            'initials' => $api['initials'] ?? $this->api_initials($api['name']),
            'tone' => $api['tone'] ?? $this->api_tone((string) ($api['slug'] ?? $row->endpoint)),
        ];
    }

    private function charts(array $query, array $apis){
        return [
            'requests_over_time' => $this->chart_results($query, 'DATE(created_at)', 'COUNT(*)', 'day', 'total', 'label_value ASC', 14),
            'status_breakdown' => $this->chart_results($query, 'status', 'COUNT(*)', 'status', 'total', 'metric_value DESC', 8),
            'avg_response_time' => $this->chart_results($query, 'DATE(created_at)', 'AVG(response_time)', 'day', 'avg_time', 'label_value ASC', 14, 1000),
            'top_apis' => $this->top_api_chart($query, $apis),
        ];
    }

    private function chart_results(array $query, $group_expr, $value_expr, $label_key, $value_key, $order, $limit, $multiplier = 1){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT {$group_expr} AS label_value, {$value_expr} AS metric_value FROM `{$table}` {$query['where_sql']} GROUP BY label_value ORDER BY {$order} LIMIT %d";
        $rows = $wpdb->get_results($this->prepared_sql($sql, array_merge($query['args'], [absint($limit)])));
        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                $label_key => (string) $row->label_value,
                $value_key => (int) round(((float) $row->metric_value) * $multiplier),
            ];
        }

        return $data;
    }

    private function top_api_chart(array $query, array $apis){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT api_id, endpoint, COUNT(*) AS total FROM `{$table}` {$query['where_sql']} GROUP BY api_id, endpoint ORDER BY total DESC LIMIT 5";
        $rows = $wpdb->get_results($this->prepared_sql($sql, $query['args']));
        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                'api_id' => (int) $row->api_id,
                'api' => $apis[(int) $row->api_id]['name'] ?? ucwords(str_replace('-', ' ', (string) $row->endpoint)),
                'initials' => $apis[(int) $row->api_id]['initials'] ?? $this->api_initials(ucwords(str_replace('-', ' ', (string) $row->endpoint))),
                'tone' => $apis[(int) $row->api_id]['tone'] ?? $this->api_tone((string) $row->endpoint),
                'total' => (int) $row->total,
            ];
        }

        return $data;
    }

    private function decorate_charts(array $charts, array $filters, $filtered_total){
        $total = max(1, (int) $filtered_total);

        foreach ($charts['requests_over_time'] ?? [] as $index => $row) {
            $day = $this->chart_day($row['day'] ?? '');
            $charts['requests_over_time'][$index]['filter_url'] = $day ? $this->history_url(array_merge($filters, [
                'range' => 'custom',
                'date_from' => $day,
                'date_to' => $day,
                'history_page' => null,
            ])) : '';
            $charts['requests_over_time'][$index]['tooltip'] = trim(($day ?: 'Unknown date') . "\n" . number_format((int) ($row['total'] ?? 0)) . ' requests');
        }

        foreach ($charts['status_breakdown'] ?? [] as $index => $row) {
            $status = absint($row['status'] ?? 0);
            $count = (int) ($row['total'] ?? 0);
            $charts['status_breakdown'][$index]['filter_url'] = $status ? $this->history_url(array_merge($filters, [
                'status' => $status,
                'result' => '',
                'history_page' => null,
            ])) : '';
            $charts['status_breakdown'][$index]['tooltip'] = 'Status ' . $status . "\n" . number_format($count) . ' requests' . "\n" . number_format(($count / $total) * 100, 1) . '% of filtered requests';
        }

        foreach ($charts['top_apis'] ?? [] as $index => $row) {
            $api_id = absint($row['api_id'] ?? 0);
            $count = (int) ($row['total'] ?? 0);
            $charts['top_apis'][$index]['filter_url'] = $api_id ? $this->history_url(array_merge($filters, [
                'api_id' => $api_id,
                'history_page' => null,
            ])) : '';
            $charts['top_apis'][$index]['tooltip'] = ($row['api'] ?? 'Unknown API') . "\n" . number_format($count) . ' requests' . "\n" . number_format(($count / $total) * 100, 1) . '% of filtered requests';
        }

        foreach ($charts['avg_response_time'] ?? [] as $index => $row) {
            $day = $this->chart_day($row['day'] ?? '');
            $charts['avg_response_time'][$index]['filter_url'] = $day ? $this->history_url(array_merge($filters, [
                'range' => 'custom',
                'date_from' => $day,
                'date_to' => $day,
                'history_page' => null,
            ])) : '';
            $charts['avg_response_time'][$index]['tooltip'] = trim(($day ?: 'Unknown date') . "\n" . number_format((int) ($row['avg_time'] ?? 0)) . ' ms average');
        }

        return $charts;
    }

    private function chart_day($value){
        $value = (string) $value;

        return $this->valid_date($value) ? $value : '';
    }

    private function card_actions(array $filters, array $stats){
        $most_used_api = is_array($stats['most_used_api'] ?? null) ? $stats['most_used_api'] : [];
        $actions = [
            'Successful' => [
                'url' => $this->history_url(array_merge($filters, ['result' => 'success', 'status' => null, 'history_page' => null])),
                'aria' => 'Filter requests to successful HTTP status codes',
            ],
            'Failed' => [
                'url' => $this->history_url(array_merge($filters, ['result' => 'failed', 'status' => null, 'history_page' => null])),
                'aria' => 'Filter requests to failed HTTP status codes',
            ],
            '401 Responses' => [
                'url' => $this->history_url(array_merge($filters, ['status' => 401, 'result' => '', 'history_page' => null])),
                'aria' => 'Filter requests to status 401',
            ],
            '403 Responses' => [
                'url' => $this->history_url(array_merge($filters, ['status' => 403, 'result' => '', 'history_page' => null])),
                'aria' => 'Filter requests to status 403',
            ],
            '429 Responses' => [
                'url' => $this->history_url(array_merge($filters, ['status' => 429, 'result' => '', 'history_page' => null])),
                'aria' => 'Filter requests to status 429',
            ],
            '500 Responses' => [
                'url' => $this->history_url(array_merge($filters, ['status' => 500, 'result' => '', 'history_page' => null])),
                'aria' => 'Filter requests to status 500',
            ],
            'Today' => [
                'url' => $this->history_url(array_merge($filters, ['range' => 'today', 'date_from' => '', 'date_to' => '', 'history_page' => null])),
                'aria' => 'Filter requests to today',
            ],
        ];

        if (!empty($most_used_api['id'])) {
            $actions['Most Used API'] = [
                'url' => $this->history_url(array_merge($filters, ['api_id' => absint($most_used_api['id']), 'history_page' => null])),
                'aria' => 'Filter requests to ' . $most_used_api['name'],
            ];
        }

        return $actions;
    }

    private function trends(array $filters, array $apis, array $columns, array $current_stats){
        $period = $this->comparison_period($filters);

        $today_current = $this->period_stats($filters, $apis, $columns, current_time('Y-m-d'), current_time('Y-m-d'));
        $yesterday = gmdate('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
        $today_previous = $this->period_stats($filters, $apis, $columns, $yesterday, $yesterday);

        $trends = [
            'today' => $this->trend_item((int) ($today_current['total'] ?? 0), (int) ($today_previous['total'] ?? 0), 'higher_good'),
        ];

        if (!$period) {
            return array_merge([
                'total' => ['label' => 'Not enough data', 'class' => 'neutral'],
                'successful' => ['label' => 'Not enough data', 'class' => 'neutral'],
                'failed' => ['label' => 'Not enough data', 'class' => 'neutral'],
                'avg_time_ms' => ['label' => 'Not enough data', 'class' => 'neutral'],
            ], $trends);
        }

        $previous = $this->period_stats($filters, $apis, $columns, $period['previous_from'], $period['previous_to']);

        return array_merge([
            'total' => $this->trend_item((int) ($current_stats['total'] ?? 0), (int) ($previous['total'] ?? 0), 'higher_good'),
            'successful' => $this->trend_item((int) ($current_stats['successful'] ?? 0), (int) ($previous['successful'] ?? 0), 'higher_good'),
            'failed' => $this->trend_item((int) ($current_stats['failed'] ?? 0), (int) ($previous['failed'] ?? 0), 'lower_good'),
            'avg_time_ms' => $this->trend_item((int) ($current_stats['avg_time_ms'] ?? 0), (int) round(((float) ($previous['avg_time'] ?? 0)) * 1000), 'lower_good'),
        ], $trends);
    }

    private function period_stats(array $filters, array $apis, array $columns, $from, $to){
        global $wpdb;

        $period_filters = array_merge($filters, [
            'range' => 'custom',
            'date_from' => $from,
            'date_to' => $to,
            'page' => 1,
        ]);
        $query = $this->query_parts($period_filters, $apis, $columns, false);
        $table = $wpdb->prefix . 'apiplatform_logs';
        $sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status >= 200 AND status < 400 THEN 1 ELSE 0 END) AS successful,
            SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed,
            AVG(response_time) AS avg_time
            FROM `{$table}` {$query['where_sql']}";

        return $wpdb->get_row($this->prepared_sql($sql, $query['args']), ARRAY_A) ?: [];
    }

    private function comparison_period(array $filters){
        $timestamp = current_time('timestamp');

        if ($filters['range'] === 'today') {
            $today = current_time('Y-m-d');
            $yesterday = gmdate('Y-m-d', $timestamp - DAY_IN_SECONDS);

            return [
                'previous_from' => $yesterday,
                'previous_to' => $yesterday,
                'current_from' => $today,
                'current_to' => $today,
            ];
        }

        if (in_array($filters['range'], ['7', '30', '90'], true)) {
            $days = absint($filters['range']);

            return [
                'previous_from' => gmdate('Y-m-d', $timestamp - ($days * 2 * DAY_IN_SECONDS)),
                'previous_to' => gmdate('Y-m-d', $timestamp - ($days * DAY_IN_SECONDS)),
                'current_from' => gmdate('Y-m-d', $timestamp - ($days * DAY_IN_SECONDS)),
                'current_to' => current_time('Y-m-d'),
            ];
        }

        if (
            $filters['range'] === 'custom' &&
            $this->valid_date($filters['date_from']) &&
            $this->valid_date($filters['date_to'])
        ) {
            $from = strtotime($filters['date_from'] . ' 00:00:00');
            $to = strtotime($filters['date_to'] . ' 00:00:00');

            if (!$from || !$to || $to < $from) {
                return null;
            }

            $days = (int) floor(($to - $from) / DAY_IN_SECONDS) + 1;

            return [
                'previous_from' => gmdate('Y-m-d', $from - ($days * DAY_IN_SECONDS)),
                'previous_to' => gmdate('Y-m-d', $from - DAY_IN_SECONDS),
                'current_from' => $filters['date_from'],
                'current_to' => $filters['date_to'],
            ];
        }

        return null;
    }

    private function trend_item($current, $previous, $direction){
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous <= 0 && $current <= 0) {
            return ['label' => 'Not enough data', 'class' => 'neutral'];
        }

        if ($previous <= 0 && $current > 0) {
            return ['label' => 'New activity', 'class' => $direction === 'lower_good' ? 'negative' : 'positive'];
        }

        $change = (($current - $previous) / $previous) * 100;

        if (abs($change) < 0.1) {
            return ['label' => 'No change', 'class' => 'neutral'];
        }

        $is_up = $change > 0;
        $positive = $direction === 'higher_good' ? $is_up : !$is_up;

        return [
            'label' => ($is_up ? 'Up ' : 'Down ') . number_format(abs($change), 1) . '% vs previous period',
            'class' => $positive ? 'positive' : 'negative',
        ];
    }

    private function status_class($status){
        $status = (int) $status;

        if ($status >= 200 && $status <= 299) {
            return 'apiplatform-http-status--2xx';
        }

        if ($status >= 300 && $status <= 399) {
            return 'apiplatform-http-status--3xx';
        }

        if (in_array($status, [400, 401, 403, 404, 429], true)) {
            return 'apiplatform-http-status--' . $status;
        }

        if ($status >= 500 && $status <= 599) {
            return 'apiplatform-http-status--5xx';
        }

        return 'apiplatform-http-status--unknown';
    }

    private function api_initials($name){
        $name = trim(wp_strip_all_tags((string) $name));

        if ($name === '') {
            return 'API';
        }

        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }

        $word = (string) ($words[0] ?? $name);
        $lower = strtolower($word);
        $length = strlen($lower);

        for ($size = 2; $size <= (int) floor($length / 2); $size++) {
            $chunk = substr($lower, 0, $size);

            if ($chunk !== '' && str_repeat($chunk, (int) ($length / $size)) === $lower) {
                return strtoupper(substr($chunk, 0, 1) . substr($chunk, 0, 1));
            }
        }

        return strtoupper(substr($word, 0, 2));
    }

    private function api_tone($seed){
        $tones = ['blue', 'green', 'amber', 'rose', 'violet'];
        $index = absint(crc32((string) $seed)) % count($tones);

        return $tones[$index];
    }

    private function pagination(array $filters, $total){
        $pages = max(1, (int) ceil($total / max(1, $filters['per_page'])));

        return [
            'current' => min($filters['page'], $pages),
            'pages' => $pages,
            'per_page' => $filters['per_page'],
            'total' => $total,
            'prev_url' => $filters['page'] > 1 ? $this->history_url(array_merge($filters, ['history_page' => $filters['page'] - 1])) : '',
            'next_url' => $filters['page'] < $pages ? $this->history_url(array_merge($filters, ['history_page' => $filters['page'] + 1])) : '',
        ];
    }

    private function export_urls(array $filters){
        return [
            'csv' => wp_nonce_url($this->history_url(array_merge($filters, ['apiplatform_request_history_export' => 'csv', 'history_page' => null])), 'apiplatform_request_history_export'),
            'json' => wp_nonce_url($this->history_url(array_merge($filters, ['apiplatform_request_history_export' => 'json', 'history_page' => null])), 'apiplatform_request_history_export'),
        ];
    }

    private function history_url(array $args = []){
        $base = current_user_can('manage_options')
            ? add_query_arg('apipage', 'request-history', (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')))
            : site_url('/request-history');
        $clean = [];

        foreach ($args as $key => $value) {
            if (in_array($key, ['api_id', 'status'], true) && !$value) {
                continue;
            }

            if ($value !== '' && $value !== null && !in_array($key, ['page'], true)) {
                $clean[$key] = $value;
            }
        }

        return add_query_arg($clean, $base);
    }

    private function apis_by_ids(array $ids){
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

        if (!$ids) {
            return [];
        }

        $posts = get_posts([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'post__in' => $ids,
            'numberposts' => count($ids),
            'no_found_rows' => true,
        ]);
        $apis = [];

        foreach ($posts as $post) {
            $apis[(int) $post->ID] = [
                'id' => (int) $post->ID,
                'name' => get_the_title($post),
                'slug' => $post->post_name,
                'initials' => $this->api_initials(get_the_title($post)),
                'tone' => $this->api_tone((string) $post->ID),
            ];
        }

        return $apis;
    }

    private function table_exists(){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function prepared_sql($sql, array $args){
        global $wpdb;

        return $args ? $wpdb->prepare($sql, $args) : $sql;
    }

    private function table_columns(){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

        return is_array($columns) ? $columns : [];
    }

    private function detail_support(array $columns){
        return [
            'request_headers' => in_array('request_headers', $columns, true),
            'query_params' => in_array('query_params', $columns, true),
            'request_body' => in_array('request_body', $columns, true),
            'response_headers' => in_array('response_headers', $columns, true),
            'response_body' => in_array('response_body', $columns, true),
            'user_agent' => in_array('user_agent', $columns, true),
            'auth_type' => in_array('auth_type', $columns, true),
            'auth_transport' => in_array('auth_transport', $columns, true),
            'response_validation_status' => in_array('response_validation_status', $columns, true),
            'response_validation_mode' => in_array('response_validation_mode', $columns, true),
            'response_validation_errors' => in_array('response_validation_errors', $columns, true),
        ];
    }

    private function normalize_auth_transport($value){
        $value = sanitize_key((string) $value);
        return in_array($value, ['authorization_bearer', 'x_api_key', 'query', 'application', 'public'], true) ? $value : ($value === '' ? 'Not recorded' : 'Unknown');
    }

    private function response_validation_status_label($value){
        return ['valid' => 'Valid', 'mismatch' => 'Mismatch', 'no_schema' => 'No Schema', 'not_checked' => 'Skipped', 'not_recorded' => 'Not Recorded'][$value] ?? 'Not Recorded';
    }

    private function response_validation_mode_label($value){
        return ['off' => 'Off', 'observe' => 'Observe', 'enforce' => 'Enforce', 'not_recorded' => 'Not Recorded'][$value] ?? 'Not Recorded';
    }
    private function normalize_response_validation_status($value){
        $value = sanitize_key((string) $value);
        return in_array($value, ['valid', 'mismatch', 'no_schema', 'not_checked'], true) ? $value : 'not_recorded';
    }

    private function normalize_response_validation_mode($value){
        $value = sanitize_key((string) $value);
        return in_array($value, ['off', 'observe', 'enforce'], true) ? $value : 'not_recorded';
    }

    private function safe_validation_errors($value){
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) return [];
        $safe = [];
        foreach (array_slice($decoded, 0, 10) as $error) {
            if (!is_array($error)) continue;
            $safe[] = ['path' => sanitize_text_field($error['path'] ?? ''), 'reason' => sanitize_key($error['reason'] ?? ''), 'expected' => sanitize_key($error['expected'] ?? ''), 'received' => sanitize_key($error['received'] ?? '')];
        }
        return $safe;
    }

    private function read_optional($row, $property, $fallback){
        return property_exists($row, $property) && $row->{$property} !== null && $row->{$property} !== ''
            ? $row->{$property}
            : $fallback;
    }

    private function safe_json($value){
        if (empty($value)) {
            return wp_json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : ['value' => $value];
        }

        return wp_json_encode($this->redact_recursive($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function redact_recursive($value){
        if (!is_array($value)) {
            return $this->redact((string) $value);
        }

        $safe = [];

        foreach ($value as $key => $item) {
            $key_text = strtolower((string) $key);

            if (preg_match('/api[_-]?key|authorization|cookie|nonce|session|token|password|secret|hash|key/i', $key_text)) {
                $safe[$key] = '[REDACTED]';
                continue;
            }

            $safe[$key] = $this->redact_recursive($item);
        }

        return $safe;
    }

    private function redact($value){
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (preg_match('/apk_live_[A-Za-z0-9_-]+|Bearer\s+[A-Za-z0-9._~+\/=-]+|api_key=([^&\s]+)/i', $value)) {
            return '[REDACTED]';
        }

        return sanitize_text_field($value);
    }

    private function mask_ip($ip){
        if ($ip === '') {
            return '---';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[2] = 'x';
            $parts[3] = 'x';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return preg_replace('/:[0-9a-f]{1,4}$/i', ':xxxx', $ip);
        }

        return '---';
    }

    private function error_code_for_status($status){
        $map = [
            400 => 'invalid_request',
            401 => 'authentication_required',
            403 => 'forbidden',
            404 => 'api_not_found',
            429 => 'rate_limit_exceeded',
            500 => 'internal_server_error',
        ];

        return $map[(int) $status] ?? ((int) $status >= 400 ? 'request_failed' : '');
    }

    private function render_page($content){
        return APIPlatform_Renderer::component('section', [
            'class' => 'apiplatform-request-history-section',
            'content' => APIPlatform_Renderer::component('stack', [
                'items' => [
                    '<h1>Request History</h1>',
                    $content
                ]
            ])
        ]);
    }
}



