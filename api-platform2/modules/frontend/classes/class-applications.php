<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Applications {

    public function __construct(){
        add_action('template_redirect', [$this, 'maybe_handle_post']);
        add_shortcode('api_applications_page', [$this, 'render_applications']);
        add_shortcode('api_create_application_page', [$this, 'render_create']);
        add_shortcode('api_application_page', [$this, 'render_application']);
    }

    public function maybe_handle_post(){
        if (empty($_POST['apiplatform_application_action']) || !is_user_logged_in()) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['apiplatform_application_action']));
        $user_id = get_current_user_id();
        $app_id = absint($_POST['application_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['apiplatform_application_nonce'] ?? ''));

        if (!wp_verify_nonce($nonce, 'apiplatform_application_action_' . $app_id . '_' . $action)) {
            wp_die('Security check failed.');
        }

        $message = 'Action completed.';
        $target_app_id = $app_id;

        if ($action === 'create') {
            $result = APIPlatform_Applications_Service::create_application($user_id, [
                'name' => wp_unslash($_POST['application_name'] ?? ''),
                'description' => wp_unslash($_POST['application_description'] ?? ''),
                'environment' => wp_unslash($_POST['application_environment'] ?? ''),
                'website_url' => wp_unslash($_POST['application_website_url'] ?? ''),
            ]);
            $message = $result['message'] ?? 'Application created.';
            $target_app_id = !empty($result['id']) ? absint($result['id']) : 0;
        } elseif ($action === 'update') {
            $result = APIPlatform_Applications_Service::update_application($app_id, $user_id, [
                'name' => wp_unslash($_POST['application_name'] ?? ''),
                'description' => wp_unslash($_POST['application_description'] ?? ''),
                'environment' => wp_unslash($_POST['application_environment'] ?? ''),
                'website_url' => wp_unslash($_POST['application_website_url'] ?? ''),
                'status' => wp_unslash($_POST['application_status'] ?? ''),
            ]);
            $message = $result['message'] ?? 'Application updated.';
        } elseif ($action === 'create-key') {
            $result = APIPlatform_Applications_Service::create_key($app_id, $user_id, wp_unslash($_POST['application_key_name'] ?? 'Application Key'));
            $message = $this->key_notice($result);
        } elseif ($action === 'regenerate-key') {
            if (empty($_POST['confirm_regenerate'])) {
                $message = 'Confirm regeneration before replacing this credential.';
            } else {
                $result = APIPlatform_Applications_Service::regenerate_key(absint($_POST['key_id'] ?? 0), $user_id);
                $message = $this->key_notice($result);
            }
        } elseif ($action === 'revoke-key') {
            $result = APIPlatform_Applications_Service::revoke_key(absint($_POST['key_id'] ?? 0), $user_id);
            $message = $result['message'] ?? 'Application key revoked.';
        } elseif ($action === 'connect-api') {
            $result = APIPlatform_Applications_Service::connect_api($app_id, $user_id, absint($_POST['api_id'] ?? 0));
            $message = $result['message'] ?? 'API access updated.';
        }

        set_transient($this->notice_key($user_id, $target_app_id), $message, 60);
        wp_safe_redirect($target_app_id ? $this->application_url($target_app_id) : $this->page_url('applications'));
        exit;
    }

    public function render_applications(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $filters = $this->filters();
        $apps = APIPlatform_Applications_Service::list_applications(get_current_user_id(), $filters);
        $content = '<div class="apiplatform-publisher-page"><div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Developer</p><h1>Applications</h1><p>Separate credentials and usage between websites, bots, development environments, and internal tools.</p></div>' . $this->button('Create Application', $this->page_url('create-application'), 'primary') . '</div>';
        $content .= $this->filters_form($filters);
        $content .= '<p class="apiplatform-publisher-muted">Showing ' . esc_html(number_format(count($apps))) . ' application' . (count($apps) === 1 ? '' : 's') . '.</p>';

        if (!$apps) {
            $content .= $this->empty_state('No applications yet', 'Create an application to generate isolated credentials for a website, bot, mobile app, or development environment.');
        } else {
            $content .= '<div class="apiplatform-publisher-api-grid">';
            foreach ($apps as $app) {
                $content .= $this->application_card($app);
            }
            $content .= '</div>';
        }

        return $this->section($content . '</div>');
    }

    public function render_create(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $content = '<div class="apiplatform-publisher-page"><div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Developer Applications</p><h1>Create Application</h1><p>Name the consumer that will own scoped API keys.</p></div></div>';
        $content .= '<section class="apiplatform-publisher-panel"><h2>Application Details</h2><form method="post" class="apiplatform-publisher-editor-form">' . $this->hidden_fields('create', 0);
        $content .= $this->field_input('Application Name', 'application_name', '');
        $content .= $this->field_select('Environment', 'application_environment', 'development', APIPlatform_Applications_Service::environments());
        $content .= $this->field_textarea('Description', 'application_description', '', 4);
        $content .= $this->field_input('Website URL', 'application_website_url', '', 'url');
        $content .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Create Application</button></div></form></section></div>';

        return $this->section($content);
    }

    public function render_application(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        APIPlatform_Frontend_Assets::enqueue_keys();
        $user_id = get_current_user_id();
        $app_id = absint($_GET['application_id'] ?? 0);
        $app = APIPlatform_Applications_Service::owned_application($app_id, $user_id);

        if (!$app) {
            return $this->section($this->alert('error', 'You cannot manage that application.'));
        }

        $notice = get_transient($this->notice_key($user_id, $app_id));
        $keys = APIPlatform_Applications_Service::application_keys($app_id, $user_id, true);
        $access = APIPlatform_Applications_Service::application_access($app_id, $user_id);
        $metrics = $this->metrics($app_id, $user_id);
        $content = '<div class="apiplatform-publisher-page apiplatform-application-hub">';

        if ($notice) {
            $content .= $this->alert(strpos($notice, 'could not') !== false || strpos($notice, 'cannot') !== false ? 'error' : 'success', $notice);
            delete_transient($this->notice_key($user_id, $app_id));
        }

        $content .= '<div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Application</p><h1>' . esc_html($app['name']) . '</h1><p>' . esc_html($app['description'] ?: $app['slug']) . '</p></div><div class="apiplatform-publisher-hero-actions">' . $this->button('All Applications', $this->page_url('applications'), 'secondary') . '</div></div>';
        $content .= $this->metric_grid([
            ['label' => 'Environment', 'value' => $this->environment_label($app['environment']), 'meta' => 'Descriptive'],
            ['label' => 'Status', 'value' => ucfirst($app['status']), 'meta' => 'Controls authentication'],
            ['label' => 'Identifier', 'value' => $app['slug'], 'meta' => 'Safe public app ID'],
            ['label' => 'Connected APIs', 'value' => count($access), 'meta' => 'Explicit relationships'],
            ['label' => 'Active Keys', 'value' => count(array_filter($keys, function($key){ return $key['status'] === 'active'; })), 'meta' => 'Scoped credentials'],
            ['label' => 'Requests', 'value' => number_format($metrics['total']), 'meta' => 'Captured logs'],
            ['label' => 'Errors', 'value' => number_format($metrics['errors']), 'meta' => 'HTTP 4xx and 5xx'],
            ['label' => 'Average Latency', 'value' => $metrics['avg_ms'] . ' ms', 'meta' => 'Real logs only'],
        ]);
        $content .= '<div class="apiplatform-publisher-workspace-grid">';
        $content .= $this->keys_panel($app, $keys);
        $content .= $this->apis_panel($app, $access);
        $content .= $this->settings_panel($app);
        $content .= $this->logs_panel($app_id);
        $content .= '</div></div>';

        return $this->section($content);
    }

    private function key_notice(array $result){
        if (empty($result['ok'])) {
            return $result['message'] ?? 'Application key action failed.';
        }

        return apiplatform_render_one_time_api_key_notice($result['message'] ?? 'Application key created.', $result['plaintext'] ?? '');
    }

    private function keys_panel(array $app, array $keys){
        $html = '<form method="post" class="apiplatform-publisher-editor-form">' . $this->hidden_fields('create-key', $app['id']) . $this->field_input('Key Name', 'application_key_name', 'Application Key') . '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Create Key</button></div></form>';

        if (!$keys) {
            $html .= $this->empty_state('No keys', 'Create a key before this application can authenticate.');
        } else {
            $html .= '<div class="apiplatform-publisher-key-list">';
            foreach ($keys as $key) {
                $html .= '<article class="apiplatform-publisher-key-row"><div><strong>' . esc_html($key['key_name']) . '</strong><code>' . esc_html($key['masked_key']) . '</code></div><dl class="apiplatform-publisher-meta"><div><dt>Status</dt><dd>' . esc_html(ucfirst($key['status'])) . '</dd></div><div><dt>Identifier</dt><dd>' . esc_html($key['key_prefix']) . '</dd></div><div><dt>Created</dt><dd>' . esc_html($this->date_label($key['created_at'])) . '</dd></div><div><dt>Last Used</dt><dd>' . esc_html($this->date_label($key['last_used_at'])) . '</dd></div></dl><div class="apiplatform-publisher-actions">';
                if ($key['status'] === 'active') {
                    $html .= '<form method="post" class="apiplatform-publisher-inline-form">' . $this->hidden_fields('regenerate-key', $app['id']) . '<input type="hidden" name="key_id" value="' . esc_attr($key['id']) . '"><label class="apiplatform-checkbox-row"><input type="checkbox" name="confirm_regenerate" value="1" required> Invalidate current credential</label><button class="apiplatform-button apiplatform-button-warning" type="submit">Regenerate</button></form>';
                    $html .= '<form method="post" class="apiplatform-publisher-inline-form">' . $this->hidden_fields('revoke-key', $app['id']) . '<input type="hidden" name="key_id" value="' . esc_attr($key['id']) . '"><button class="apiplatform-button apiplatform-button-danger" type="submit">Revoke</button></form>';
                }
                $html .= '</div></article>';
            }
            $html .= '</div>';
        }

        return $this->panel('Application Keys', $html);
    }

    private function apis_panel(array $app, array $access){
        $apis = get_posts(['post_type' => 'user_api', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => 50, 'orderby' => 'title', 'order' => 'ASC']);
        $html = '<form method="post" class="apiplatform-publisher-editor-form">' . $this->hidden_fields('connect-api', $app['id']) . '<div class="apiplatform-form-row"><label for="apiplatform-application-api-id">Add API</label><select id="apiplatform-application-api-id" class="apiplatform-input" name="api_id">';
        foreach ($apis as $api) {
            $visibility = get_post_meta($api->ID, 'apiplatform_portal_visibility', true) ?: 'private';
            $can_view_api = class_exists('APIPlatform_Ownership_Service')
                ? APIPlatform_Ownership_Service::can((int) $app['owner_user_id'], $api->ID, 'apis.view')
                : ((int) $api->post_author === (int) $app['owner_user_id'] || current_user_can('manage_options'));
            if (!$can_view_api && $visibility !== 'public' && !current_user_can('manage_options')) {
                continue;
            }
            $html .= '<option value="' . esc_attr($api->ID) . '">' . esc_html(get_the_title($api) . ' / ' . $api->post_name) . '</option>';
        }
        $html .= '</select></div><div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Add API</button></div></form>';

        if (!$access) {
            $html .= $this->empty_state('No APIs connected', 'Add an API to define what this application may access.');
        } else {
            $html .= '<div class="apiplatform-table-wrapper"><table class="apiplatform-table apiplatform-publisher-table"><thead><tr><th>API</th><th>Status</th><th>Granted</th><th>Last Used</th></tr></thead><tbody>';
            foreach ($access as $row) {
                $html .= '<tr><td><strong>' . esc_html($row['post_title']) . '</strong><br><span>' . esc_html($row['post_name']) . '</span></td><td>' . esc_html(ucfirst($row['access_status'])) . '</td><td>' . esc_html($this->date_label($row['granted_at'])) . '</td><td>' . esc_html($this->date_label($row['last_used_at'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $this->panel('Connected APIs', $html);
    }

    private function settings_panel(array $app){
        $html = '<form method="post" class="apiplatform-publisher-editor-form">' . $this->hidden_fields('update', $app['id']);
        $html .= $this->field_input('Application Name', 'application_name', $app['name']);
        $html .= $this->field_select('Environment', 'application_environment', $app['environment'], APIPlatform_Applications_Service::environments());
        $html .= $this->field_select('Status', 'application_status', $app['status'], APIPlatform_Applications_Service::statuses());
        $html .= $this->field_textarea('Description', 'application_description', $app['description'], 4);
        $html .= $this->field_input('Website URL', 'application_website_url', $app['website_url'], 'url');
        $html .= '<p class="apiplatform-publisher-muted">Archived applications restore as Disabled; enable them explicitly before credentials can authenticate.</p><div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Save Application</button></div></form>';

        return $this->panel('Settings', $html);
    }

    private function logs_panel($app_id){
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT l.*, p.post_title FROM `" . APIPlatform_Applications_Service::tables()['logs'] . "` l LEFT JOIN `{$wpdb->posts}` p ON p.ID = l.api_id WHERE l.application_id = %d ORDER BY l.created_at DESC LIMIT 10", absint($app_id)), ARRAY_A);

        if (!$rows) {
            return $this->panel('Application Logs', $this->empty_state('No usage yet', 'Requests made with this application will appear here.'));
        }

        $html = '<div class="apiplatform-table-wrapper"><table class="apiplatform-table apiplatform-publisher-table"><thead><tr><th>Time</th><th>Request ID</th><th>API</th><th>Key</th><th>Status</th><th>Latency</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . esc_html($this->date_label($row['created_at'])) . '</td><td><code>' . esc_html($row['request_id'] ?: (string) $row['id']) . '</code></td><td>' . esc_html($row['post_title'] ?: $row['endpoint']) . '</td><td>' . esc_html($row['application_key_id'] ? '#' . $row['application_key_id'] : 'Unknown') . '</td><td>' . esc_html((string) $row['status']) . '</td><td>' . esc_html((int) round(((float) $row['response_time']) * 1000) . ' ms') . '</td></tr>';
        }

        return $this->panel('Application Logs', $html . '</tbody></table></div>');
    }

    private function metrics($app_id, $user_id){
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors, AVG(response_time) AS avg_time FROM `" . APIPlatform_Applications_Service::tables()['logs'] . "` WHERE application_id = %d", absint($app_id)), ARRAY_A) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'avg_ms' => (int) round(((float) ($row['avg_time'] ?? 0)) * 1000),
        ];
    }

    private function application_card(array $app){
        return '<article class="apiplatform-publisher-card"><div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html(strtoupper(substr($app['name'], 0, 2))) . '</span><div><h2>' . esc_html($app['name']) . '</h2><p>' . esc_html($app['description'] ?: $app['slug']) . '</p></div></div><div class="apiplatform-publisher-badges"><span class="apiplatform-publisher-badge">Environment: ' . esc_html($this->environment_label($app['environment'])) . '</span><span class="apiplatform-publisher-badge">Status: ' . esc_html(ucfirst($app['status'])) . '</span></div><dl class="apiplatform-publisher-meta"><div><dt>Requests</dt><dd>' . esc_html(number_format((int) ($app['request_count'] ?? 0))) . '</dd></div><div><dt>Last Used</dt><dd>' . esc_html($this->date_label($app['last_used_at'])) . '</dd></div><div><dt>Updated</dt><dd>' . esc_html($this->date_label($app['updated_at'])) . '</dd></div></dl><div class="apiplatform-publisher-actions">' . $this->button('Manage', $this->application_url($app['id']), 'primary') . '</div></article>';
    }

    private function filters(){
        $status = sanitize_key($_GET['status'] ?? '');
        $environment = sanitize_key($_GET['environment'] ?? '');
        $sort = sanitize_key($_GET['sort'] ?? 'updated');
        return [
            'status' => isset(APIPlatform_Applications_Service::statuses()[$status]) ? $status : '',
            'environment' => isset(APIPlatform_Applications_Service::environments()[$environment]) ? $environment : '',
            'sort' => in_array($sort, ['updated', 'used', 'name', 'requests'], true) ? $sort : 'updated',
        ];
    }

    private function filters_form(array $filters){
        return '<form class="apiplatform-publisher-filters" method="get" action="' . esc_url($this->page_url('applications')) . '">' .
            (current_user_can('manage_options') ? '<input type="hidden" name="apipage" value="applications">' : '') .
            '<label><span>Status</span><select name="status"><option value="">Active and Disabled</option>' . $this->options(APIPlatform_Applications_Service::statuses(), $filters['status']) . '</select></label>' .
            '<label><span>Environment</span><select name="environment"><option value="">All</option>' . $this->options(APIPlatform_Applications_Service::environments(), $filters['environment']) . '</select></label>' .
            '<label><span>Sort</span><select name="sort">' . $this->options(['updated' => 'Recently Updated', 'used' => 'Recently Used', 'name' => 'Name', 'requests' => 'Request Volume'], $filters['sort']) . '</select></label>' .
            '<button class="apiplatform-button apiplatform-button-primary" type="submit">Apply</button></form>';
    }

    private function hidden_fields($action, $app_id){
        return '<input type="hidden" name="apiplatform_application_action" value="' . esc_attr($action) . '">' .
            '<input type="hidden" name="application_id" value="' . esc_attr(absint($app_id)) . '">' .
            '<input type="hidden" name="apiplatform_application_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_application_action_' . absint($app_id) . '_' . sanitize_key($action))) . '">';
    }

    private function enqueue(){
        APIPlatform_Frontend_Assets::enqueue_common();
        wp_enqueue_style('apiplatform-dashboard-style');
    }

    private function page_url($page, array $args = []){
        $base = current_user_can('manage_options') ? add_query_arg('apipage', sanitize_key($page), (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))) : site_url('/' . sanitize_key($page));
        return $args ? add_query_arg($args, $base) : $base;
    }

    private function application_url($app_id){
        return $this->page_url('application', ['application_id' => absint($app_id)]);
    }

    private function notice_key($user_id, $app_id){
        return 'apiplatform_application_notice_' . absint($user_id) . '_' . absint($app_id);
    }

    private function environment_label($env){
        $options = APIPlatform_Applications_Service::environments();
        return $options[$env] ?? 'Other';
    }

    private function date_label($date){
        $timestamp = strtotime((string) $date);
        return $timestamp ? human_time_diff($timestamp, current_time('timestamp')) . ' ago' : 'Not captured';
    }

    private function options(array $options, $selected){
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        return $html;
    }

    private function field_input($label, $name, $value, $type = 'text'){
        return '<div class="apiplatform-form-row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label><input id="' . esc_attr($name) . '" class="apiplatform-input" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></div>';
    }

    private function field_textarea($label, $name, $value, $rows){
        return '<div class="apiplatform-form-row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label><textarea id="' . esc_attr($name) . '" class="apiplatform-input" name="' . esc_attr($name) . '" rows="' . absint($rows) . '">' . esc_textarea($value) . '</textarea></div>';
    }

    private function field_select($label, $name, $value, array $options){
        return '<div class="apiplatform-form-row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label><select id="' . esc_attr($name) . '" class="apiplatform-input" name="' . esc_attr($name) . '">' . $this->options($options, $value) . '</select></div>';
    }

    private function section($content){
        return APIPlatform_Renderer::component('section', ['content' => $content]);
    }

    private function alert($type, $content){
        return APIPlatform_Renderer::component('alert', ['type' => $type, 'content' => $content]);
    }

    private function button($label, $url, $type = 'secondary'){
        return APIPlatform_Renderer::component('button', ['type' => $type, 'label' => $label, 'url' => $url]);
    }

    private function panel($title, $content){
        return '<section class="apiplatform-publisher-panel"><h2>' . esc_html($title) . '</h2>' . $content . '</section>';
    }

    private function metric_grid(array $stats){
        $html = '<div class="apiplatform-publisher-metrics">';
        foreach ($stats as $stat) {
            $html .= '<div class="apiplatform-publisher-metric"><span>' . esc_html($stat['label']) . '</span><strong>' . esc_html((string) $stat['value']) . '</strong><small>' . esc_html($stat['meta']) . '</small></div>';
        }
        return $html . '</div>';
    }

    private function empty_state($title, $message){
        return '<div class="apiplatform-publisher-empty"><strong>' . esc_html($title) . '</strong><p>' . esc_html($message) . '</p></div>';
    }
}
