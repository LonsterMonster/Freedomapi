<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Publisher_Pages {

    private $owner_redirect_args = [];

    public function __construct(){
        add_action('template_redirect', [$this, 'maybe_handle_owner_action']);
        add_filter('apiplatform_api_documentation_data', [$this, 'filter_documentation_data'], 10, 3);
        add_filter('apiplatform_api_documentation_supported_methods', [$this, 'filter_supported_methods'], 10, 2);
        add_filter('apiplatform_api_documentation_parameters', [$this, 'filter_documentation_parameters'], 10, 2);
        add_filter('apiplatform_api_documentation_errors', [$this, 'filter_documentation_errors']);
        add_filter('apiplatform_api_documentation_code_examples', [$this, 'filter_code_examples'], 10, 4);
        add_shortcode('api_publisher_dashboard_page', [$this, 'render_dashboard']);
        add_shortcode('api_my_apis_page', [$this, 'render_my_apis']);
        add_shortcode('api_publisher_api_page', [$this, 'render_api_hub']);
        add_shortcode('api_publisher_analytics_page', [$this, 'render_analytics']);
        add_shortcode('api_publisher_docs_manager_page', [$this, 'render_documentation_manager']);
        add_shortcode('api_publisher_versions_page', [$this, 'render_versions']);
        add_shortcode('api_publisher_settings_page', [$this, 'render_settings']);
    }

    public function maybe_handle_owner_action(){
        if (empty($_POST['apiplatform_owner_action'])) {
            return;
        }

        $this->debug_owner_action_post('received');

        if (!is_user_logged_in()) {
            $this->debug_owner_action_post('not_logged_in');
            return;
        }

        $user_id = get_current_user_id();
        $api_id = isset($_POST['api_id']) ? absint($_POST['api_id']) : 0;
        $api = $this->owned_api_post($api_id, $user_id);
        $action = sanitize_key(wp_unslash($_POST['apiplatform_owner_action']));
        $target = sanitize_key(wp_unslash($_POST['apiplatform_lifecycle_target'] ?? ''));
        $nonce = isset($_POST['apiplatform_owner_nonce']) ? sanitize_text_field(wp_unslash($_POST['apiplatform_owner_nonce'])) : '';
        $nonce_ok = wp_verify_nonce($nonce, 'apiplatform_owner_action_' . $api_id);
        $permission = $this->permission_for_owner_action($action);
        $capability_ok = class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($user_id, $api_id, $permission)
            : (current_user_can('manage_options') || current_user_can('edit_post', $api_id));
        $ownership_ok = $api !== null;

        $this->debug_owner_action_post('resolved', [
            'api_id' => $api_id,
            'action' => $action,
            'target' => $target,
            'nonce_present' => $nonce !== '' ? 'yes' : 'no',
            'nonce_ok' => $nonce_ok ? 'yes' : 'no',
            'capability_ok' => $capability_ok ? 'yes' : 'no',
            'ownership_ok' => $ownership_ok ? 'yes' : 'no',
            'stored_lifecycle' => class_exists('APIPlatform_Lifecycle_Policy') ? APIPlatform_Lifecycle_Policy::get_state($api_id) : sanitize_key(get_post_meta($api_id, 'apiplatform_lifecycle_status', true)),
            'normalized_target' => class_exists('APIPlatform_API_Status') ? APIPlatform_API_Status::normalize_lifecycle($target) : $target,
        ]);

        if (!$api || !$capability_ok) {
            wp_die('You cannot manage that API.');
        }

        if (!$nonce_ok) {
            wp_die('Security check failed.');
        }

        if ($action === 'archive') {
            $message = $this->transition_lifecycle($api, 'archived', $user_id);
        } elseif ($action === 'restore') {
            $message = $this->transition_lifecycle($api, 'private', $user_id);
        } elseif ($action === 'lifecycle') {
            $message = $this->transition_lifecycle($api, $target, $user_id);
        } elseif ($action === 'save-version') {
            $message = $this->save_version_metadata($api);
        } elseif ($action === 'save-docs') {
            $message = $this->save_documentation_metadata($api);
        } elseif ($action === 'publish-docs') {
            $message = $this->publish_documentation($api, $user_id);
        } elseif ($action === 'save-doc-release') {
            $message = $this->save_documentation_release($api, $user_id);
        } elseif ($action === 'save-endpoint-schema') {
            $message = $this->save_endpoint_schema($api, $user_id);
        } elseif ($action === 'save-data-models') {
            $message = $this->save_data_models($api, $user_id);
        } elseif ($action === 'save-validation-settings') {
            $message = $this->save_validation_settings($api, $user_id);
        } elseif ($action === 'save-gateway-settings') {
            $message = $this->save_gateway_settings($api, $user_id);
        } elseif ($action === 'preview-openapi-import') {
            $message = $this->preview_openapi_import($api, $user_id);
        } elseif ($action === 'confirm-openapi-import') {
            $message = $this->confirm_openapi_import($api, $user_id);
        } elseif ($action === 'export-openapi') {
            $message = $this->export_openapi($api, $user_id);
        } elseif ($action === 'save-sdk-settings') {
            $message = $this->save_sdk_settings($api, $user_id);
        } elseif ($action === 'preview-sdk') {
            $message = $this->preview_sdk_generation($api, $user_id);
        } elseif ($action === 'download-sdk') {
            $message = $this->download_sdk_package($api, $user_id);
        } elseif ($action === 'unpublish') {
            $message = $this->transition_lifecycle($api, 'private', $user_id);
        } elseif ($action === 'application-access') {
            $access_id = absint($_POST['access_id'] ?? 0);
            $access_status = sanitize_key(wp_unslash($_POST['access_status'] ?? ''));
            $result = class_exists('APIPlatform_Applications_Service')
                ? APIPlatform_Applications_Service::publisher_update_access($access_id, $user_id, $access_status)
                : ['ok' => false, 'message' => 'Developer applications are unavailable.'];
            $message = $result['message'] ?? 'Application access updated.';
        } else {
            wp_die('Unknown publisher action.');
        }

        $this->record_organization_api_event($api, $user_id, $action, $message);
        set_transient($this->notice_key($user_id, $api_id), $message, 60);
        wp_safe_redirect($this->api_hub_url($api_id, array_merge(['section' => $this->target_section_for_action($action)], $this->owner_redirect_args)));
        exit;
    }

    public function render_dashboard(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $user_id = get_current_user_id();
        $apis = $this->owned_apis($user_id);
        $metrics = $this->log_metrics($user_id, $apis);
        $visibility_counts = $this->visibility_counts($apis);
        $lifecycle_counts = $this->lifecycle_counts($apis);
        $latest_updates = array_slice($this->sort_apis($apis, 'updated_desc'), 0, 5);
        $recent_keys = $this->recent_keys($apis, 5);
        $recent_docs = $this->recent_docs($apis, 5);
        $activity = $this->recent_activity($user_id, $apis, 8);

        $stats = [
            ['label' => 'Total APIs', 'value' => count($apis), 'meta' => 'Owned APIs'],
            ['label' => 'Published APIs', 'value' => $visibility_counts['public'], 'meta' => 'Visible in directory'],
            ['label' => 'Private APIs', 'value' => $visibility_counts['private'], 'meta' => 'Owner-only'],
            ['label' => 'Draft', 'value' => $lifecycle_counts['draft'], 'meta' => 'Setup incomplete'],
            ['label' => 'Testing', 'value' => $lifecycle_counts['testing'], 'meta' => 'Internal validation'],
            ['label' => 'Ready', 'value' => $lifecycle_counts['ready'], 'meta' => 'Ready to publish'],
            ['label' => 'Public', 'value' => $lifecycle_counts['public'], 'meta' => 'Live in portal'],
            ['label' => 'Deprecated', 'value' => $lifecycle_counts['deprecated'], 'meta' => 'Migration advised'],
            ['label' => 'Archived', 'value' => $lifecycle_counts['archived'], 'meta' => 'Runtime blocked'],
            ['label' => 'Requests Today', 'value' => number_format($metrics['today']), 'meta' => 'Since midnight'],
            ['label' => 'Requests This Month', 'value' => number_format($metrics['month']), 'meta' => current_time('F Y')],
            ['label' => 'Total Requests', 'value' => number_format($metrics['total']), 'meta' => 'All captured logs'],
            ['label' => 'Average Response Time', 'value' => $metrics['avg_ms'] . ' ms', 'meta' => 'From request logs'],
            ['label' => 'Error Rate', 'value' => $metrics['error_rate'] . '%', 'meta' => 'HTTP 4xx and 5xx'],
        ];

        $content = '<div class="apiplatform-publisher-dashboard">';
        $content .= '<div class="apiplatform-publisher-hero"><div><p class="apiplatform-publisher-kicker">Publisher Dashboard</p><h1>Manage your FreedomAPI workspace.</h1><p>Create, publish, monitor, document, and maintain APIs from one owner console.</p></div><div class="apiplatform-publisher-hero-actions">' .
            $this->button('Create API', $this->page_url('create'), 'primary') .
            $this->button('View My APIs', $this->page_url('my-apis'), 'secondary') .
            '</div></div>';
        $content .= $this->metric_grid($stats);
        $content .= '<div class="apiplatform-publisher-dashboard-grid">';
        $content .= $this->panel('Recent Activity', $this->activity_list($activity));
        $content .= $this->panel('Latest API Updates', $this->api_update_list($latest_updates));
        $content .= $this->panel('Quick Actions', $this->quick_actions());
        $content .= $this->panel('Recent Documentation Changes', $this->docs_change_list($recent_docs));
        $content .= $this->panel('Recently Generated Keys', $this->key_list($recent_keys));
        $content .= '</div></div>';

        return $this->section($content);
    }

    public function render_my_apis(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $user_id = get_current_user_id();
        $filters = $this->my_api_filters();
        $apis = $this->filter_apis($this->owned_apis($user_id), $filters);
        $apis = $this->sort_apis($apis, $filters['sort']);

        $content = '<div class="apiplatform-publisher-page apiplatform-publisher-my-apis">';
        $content .= '<div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">My APIs</p><h1>Owned APIs</h1><p>Search, filter, and jump into the existing management tools for each API.</p></div>' . $this->button('Create API', $this->page_url('create'), 'primary') . '</div>';
        $content .= $this->filters_form($filters);

        if (!$apis) {
            $content .= $this->empty_state('No APIs match those filters.', 'Create a new API or clear the search filters.');
        } else {
            $content .= '<div class="apiplatform-publisher-api-grid">';
            foreach ($apis as $api) {
                $content .= $this->api_card($api);
            }
            $content .= '</div>';
        }

        return $this->section($content . '</div>');
    }

    public function render_api_hub(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        APIPlatform_Frontend_Assets::enqueue_keys();
        $user_id = get_current_user_id();
        $api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;
        $api_post = $this->owned_api_post($api_id, $user_id);

        if (!$api_post) {
            return $this->section($this->alert('error', 'You cannot manage that API.'));
        }

        $api = $this->api_context($api_post, $user_id);
        $section = $this->api_section();
        $notice = get_transient($this->notice_key($user_id, $api_id));
        $edit_notice_key = 'apiplatform_edit_api_notice_' . absint($user_id) . '_' . absint($api_id);
        $edit_notice = get_transient($edit_notice_key);
        $content = '<div class="apiplatform-publisher-page apiplatform-publisher-api-hub">';

        if ($notice) {
            $notice_type = strpos((string) $notice, 'Error:') === 0 ? 'error' : 'success';
            $content .= $this->alert($notice_type, esc_html($notice));
            delete_transient($this->notice_key($user_id, $api_id));
        }

        if ($edit_notice) {
            $content .= $edit_notice;
            delete_transient($edit_notice_key);
        }

        $api_heading = ($api['owner_type'] ?? 'personal') === 'organization'
            ? (($api['owner_label'] ?? 'Organization') . ' / ' . $api['title'])
            : ('Personal / ' . $api['title']);
        $content .= '<div class="apiplatform-publisher-page-head apiplatform-publisher-api-head"><div><p class="apiplatform-publisher-kicker">Selected API</p><h1>' . esc_html($api_heading) . '</h1><p>' . esc_html($api['summary'] ?: $api['endpoint']) . '</p></div><div class="apiplatform-publisher-hero-actions">' .
            $this->button('Edit API', $api['urls']['edit'], 'primary') .
            $this->button('Test API', $api['urls']['tester'], 'secondary') .
            $this->button('View Logs', $api['urls']['logs'], 'secondary') .
            '</div></div>';
        $content .= $this->api_owner_context_bar($api);
        $content .= $this->api_quick_action_bar($api);
        $content .= $this->api_scoped_nav($api, $section);
        $content .= $this->api_hub_body($api, $api_post, $section);
        $content .= '</div>';

        return $this->section($content);
    }

    public function render_analytics(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $user_id = get_current_user_id();
        $apis = array_values(array_filter($this->owned_apis($user_id), function($api){
            return !empty($api['can_view_analytics']);
        }));
        $requested_api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;

        if ($requested_api_id) {
            $apis = array_values(array_filter($apis, function($api) use ($requested_api_id){
                return (int) $api['id'] === $requested_api_id;
            }));

            if (!$apis) {
                return $this->section($this->alert('error', 'You cannot view analytics for that API.'));
            }
        }

        $range = $this->analytics_range();
        $analytics = $this->analytics_data($user_id, $apis, $range);
        $analytics_title = $requested_api_id && $apis
            ? 'Analytics for ' . $apis[0]['title']
            : 'API Analytics';

        $content = '<div class="apiplatform-publisher-page">';
        $content .= '<div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Analytics</p><h1>' . esc_html($analytics_title) . '</h1><p>Charts and tables are derived from the existing request log table.</p></div>' . $this->button('Open Logs', $requested_api_id ? $this->page_url('logs', ['api_id' => $requested_api_id]) : $this->page_url('logs'), 'secondary') . '</div>';
        if ($requested_api_id && $apis) {
            $content .= $this->api_scoped_nav($apis[0], 'analytics');
        }
        $content .= $this->range_nav($range);
        $content .= $this->metric_grid([
            ['label' => 'Requests', 'value' => number_format($analytics['metrics']['total']), 'meta' => $analytics['label']],
            ['label' => 'Latency', 'value' => $analytics['metrics']['avg_ms'] . ' ms', 'meta' => 'Average response time'],
            ['label' => 'Errors', 'value' => number_format($analytics['metrics']['errors']), 'meta' => 'HTTP 4xx and 5xx'],
            ['label' => 'Error Rate', 'value' => $analytics['metrics']['error_rate'] . '%', 'meta' => 'Of captured requests'],
        ]);
        $content .= '<div class="apiplatform-publisher-dashboard-grid">';
        $content .= $this->panel('Requests', $this->bar_list($analytics['requests'], 'day', 'total'));
        $content .= $this->panel('Latency', $this->bar_list($analytics['latency'], 'day', 'avg_ms', ' ms'));
        $content .= $this->panel('Errors', $this->bar_list($analytics['errors'], 'status', 'total'));
        $content .= $this->panel('Top Endpoints', $this->bar_list($analytics['top_endpoints'], 'endpoint', 'total'));
        $content .= $this->panel('Status Codes', $this->bar_list($analytics['status_codes'], 'status', 'total'));
        $content .= $this->panel('Top APIs', $this->bar_list($analytics['top_apis'], 'api', 'total'));
        $content .= $this->panel('Top API Keys', $this->not_captured('API-key identity is not stored in the current request log schema.'));
        $content .= $this->panel('Countries', $this->not_captured('Country data is not captured by the current logger.'));
        $content .= $this->panel('Peak Hours', $this->bar_list($analytics['peak_hours'], 'hour', 'total'));
        $content .= '</div></div>';

        return $this->section($content);
    }

    public function render_documentation_manager(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $selected = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;

        if ($selected) {
            $api_post = $this->owned_api_post($selected, get_current_user_id());
            if (!$api_post) {
                return $this->section($this->alert('error', 'You cannot manage documentation for that API.'));
            }
            $api = $this->api_context($api_post, get_current_user_id());
            return $this->section('<div class="apiplatform-publisher-page">' . $this->api_scoped_nav($api, 'documentation') . $this->api_documentation_editor($api, $api_post) . '</div>');
        }

        $apis = $this->owned_apis(get_current_user_id());
        $content = '<div class="apiplatform-publisher-page"><div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Documentation</p><h1>Documentation Manager</h1><p>Use the existing documentation engine and API editor as the canonical source.</p></div>' . $this->button('Create API', $this->page_url('create'), 'primary') . '</div>';
        $content .= '<div class="apiplatform-table-wrapper"><table class="apiplatform-table apiplatform-publisher-table"><thead><tr><th>API</th><th>Docs</th><th>Version</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';

        foreach ($apis as $api) {
            $docs_status = $api['docs_status'] === 'ready' ? 'Enabled' : 'Coming soon';
            $content .= '<tr><td><strong>' . esc_html($api['title']) . '</strong><br><span>' . esc_html($api['endpoint']) . '</span></td><td>' . esc_html($docs_status) . '</td><td>' . esc_html($api['version']) . '</td><td>' . esc_html($api['updated_label']) . '</td><td class="apiplatform-publisher-table-actions">' .
                $this->button('Edit Documentation', $api['urls']['documentation'], 'primary') .
                $this->button('Private Docs', $api['urls']['private_documentation'], 'secondary') .
                ($api['visibility'] !== 'private' ? $this->button('Public Docs', $api['urls']['public_docs'], 'secondary') : '<span class="apiplatform-publisher-muted">Private</span>') .
                '</td></tr>';
        }

        $content .= $apis ? '</tbody></table></div>' : '<tr><td colspan="5">No APIs yet.</td></tr></tbody></table></div>';

        return $this->section($content . '</div>');
    }

    public function render_versions(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $selected = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;

        if ($selected) {
            $api_post = $this->owned_api_post($selected, get_current_user_id());
            if (!$api_post) {
                return $this->section($this->alert('error', 'You cannot manage versions for that API.'));
            }
            $api = $this->api_context($api_post, get_current_user_id());
            return $this->section('<div class="apiplatform-publisher-page">' . $this->api_scoped_nav($api, 'versions') . $this->api_versions_panel($api) . '</div>');
        }

        $apis = $this->owned_apis(get_current_user_id());
        $content = '<div class="apiplatform-publisher-page"><div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Versions</p><h1>Version Management</h1><p>Current version labels are available now. Multiple stored API versions are planned for a later schema phase.</p></div></div>';
        $content .= '<div class="apiplatform-publisher-api-grid">';

        foreach ($apis as $api) {
            $content .= '<article class="apiplatform-publisher-card"><div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html($api['initials']) . '</span><div><h2>' . esc_html($api['title']) . '</h2><p>' . esc_html($api['endpoint']) . '</p></div></div><dl class="apiplatform-publisher-meta"><div><dt>Current Version</dt><dd>' . esc_html($api['version']) . '</dd></div><div><dt>Status</dt><dd>' . esc_html($api['version_status']) . '</dd></div><div><dt>Default</dt><dd>Current runtime version</dd></div></dl><div class="apiplatform-publisher-actions">' . $this->button('Edit Version Metadata', $this->api_hub_url($api['id'], ['section' => 'versions']), 'primary') . '<span class="apiplatform-publisher-disabled-action">Independent runtime versions not supported yet</span></div></article>';
        }

        return $this->section($content . '</div></div>');
    }

    public function render_settings(){
        if (!is_user_logged_in()) {
            return $this->alert('error', 'Login required');
        }

        $this->enqueue();
        $selected = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;

        if ($selected) {
            $api_post = $this->owned_api_post($selected, get_current_user_id());
            if (!$api_post) {
                return $this->section($this->alert('error', 'You cannot manage settings for that API.'));
            }
            $api = $this->api_context($api_post, get_current_user_id());
            return $this->section('<div class="apiplatform-publisher-page">' . $this->api_scoped_nav($api, 'settings') . $this->api_settings_panel($api) . '</div>');
        }

        $apis = $this->owned_apis(get_current_user_id());
        $content = '<div class="apiplatform-publisher-page"><div class="apiplatform-publisher-page-head"><div><p class="apiplatform-publisher-kicker">Settings</p><h1>API Settings</h1><p>Change API title, slug, visibility, category, authentication, rate limits, icon, tags, and danger-zone actions from the existing Edit API workflow.</p></div></div>';
        $content .= '<div class="apiplatform-publisher-api-grid">';

        foreach ($apis as $api) {
            $content .= '<article class="apiplatform-publisher-card"><div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html($api['initials']) . '</span><div><h2>' . esc_html($api['title']) . '</h2><p>' . esc_html($api['summary']) . '</p></div></div><dl class="apiplatform-publisher-meta"><div><dt>Visibility</dt><dd>' . esc_html(ucfirst($api['visibility'])) . '</dd></div><div><dt>Category</dt><dd>' . esc_html($api['category_label']) . '</dd></div><div><dt>Authentication</dt><dd>' . esc_html($api['auth_label']) . '</dd></div></dl><div class="apiplatform-publisher-actions">' . $this->button('Manage Settings', $api['urls']['edit'], 'primary') . $this->button('Danger Zone', $api['urls']['edit'] . '#apiplatform-danger-zone', 'danger') . '</div></article>';
        }

        return $this->section($content . '</div></div>');
    }

    private function api_context($api_post, $user_id){
        $api = $this->owned_apis($user_id);

        foreach ($api as $item) {
            if ((int) $item['id'] === (int) $api_post->ID) {
                $metrics = $this->api_metrics((int) $api_post->ID, $user_id);
                return array_merge($item, [
                    'error_rate' => $metrics['error_rate'],
                    'avg_ms' => $metrics['avg_ms'],
                    'total_requests' => $metrics['total'],
                    'last_request' => $metrics['last_request'],
                    'last_error' => $metrics['last_error'],
                    'post' => $api_post,
                ]);
            }
        }

        return [];
    }

    private function api_section(){
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'overview';
        $aliases = [
            'endpoint-schema' => 'schema',
        ];
        $section = $aliases[$section] ?? $section;
        $allowed = ['overview', 'configuration', 'schema', 'documentation', 'publishing', 'analytics', 'logs', 'versions', 'keys', 'settings'];

        return in_array($section, $allowed, true) ? $section : 'overview';
    }

    private function api_scoped_nav(array $api, $active){
        $items = [
            'overview' => 'Overview',
            'configuration' => 'Configuration',
            'schema' => 'Endpoint Schema',
            'documentation' => 'Documentation',
            'publishing' => 'Publishing',
            'analytics' => 'Analytics',
            'logs' => 'Logs',
            'versions' => 'Versions',
            'keys' => 'Keys',
            'settings' => 'Settings',
        ];
        $html = '<nav class="apiplatform-publisher-api-nav" aria-label="API management sections"><a href="' . esc_url($this->page_url('my-apis')) . '">My APIs</a><span>' . esc_html($api['title']) . '</span>';

        foreach ($items as $section => $label) {
            $html .= '<a href="' . esc_url($this->api_hub_url($api['id'], ['section' => $section])) . '"' . ($active === $section ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }

        return $html . '</nav>';
    }

    private function api_owner_context_bar(array $api){
        $owner_type = sanitize_key($api['owner_type'] ?? 'personal');
        $owner_label = (string) ($api['owner_label'] ?? 'Personal');
        $parts = [
            ['Owner', $owner_label],
            ['Owner Type', ucfirst($owner_type)],
        ];

        if ($owner_type === 'organization') {
            $member = class_exists('APIPlatform_Organization_Membership_Service')
                ? APIPlatform_Organization_Membership_Service::member(absint($api['owner_id'] ?? 0), get_current_user_id())
                : null;
            $parts[] = ['Your Role', is_array($member) ? APIPlatform_Organization_Membership_Service::role_label($member['role'] ?? 'viewer') : 'Member'];
            $parts[] = ['Permission', class_exists('APIPlatform_Ownership_Service') && APIPlatform_Ownership_Service::can(get_current_user_id(), $api['id'], 'apis.edit') ? 'Manage' : 'View'];
        }

        $content = '<dl class="apiplatform-publisher-meta apiplatform-publisher-owner-context">';
        foreach ($parts as $part) {
            $content .= '<div><dt>' . esc_html($part[0]) . '</dt><dd>' . esc_html($part[1]) . '</dd></div>';
        }
        $content .= '</dl>';

        if ($owner_type === 'organization' && !empty($api['owner_slug'])) {
            $content .= '<div class="apiplatform-publisher-actions">' . $this->button('Back to Organization APIs', $this->organization_url($api['owner_slug'], 'apis'), 'secondary') . '</div>';
        }

        return '<div class="apiplatform-publisher-card apiplatform-publisher-owner-context-card">' . $content . '</div>';
    }

    private function api_hub_body(array $api, $api_post, $section){
        if ($section === 'configuration') {
            return $this->api_configuration_panel($api);
        }

        if ($section === 'documentation') {
            return $this->api_documentation_editor($api, $api_post);
        }

        if ($section === 'schema') {
            return $this->api_endpoint_schema_panel($api, $api_post);
        }

        if ($section === 'publishing') {
            return $this->api_publishing_panel($api, $api_post);
        }

        if ($section === 'analytics') {
            return $this->api_analytics_panel($api);
        }

        if ($section === 'logs') {
            return $this->api_logs_panel($api);
        }

        if ($section === 'versions') {
            return $this->api_versions_panel($api);
        }

        if ($section === 'keys') {
            return $this->api_keys_panel($api);
        }

        if ($section === 'settings') {
            return $this->api_settings_panel($api);
        }

        return $this->api_overview_panel($api);
    }

    private function api_overview_panel(array $api){
        $stats = [
            ['label' => 'Name', 'value' => $api['title'], 'meta' => 'Owner workspace'],
            ['label' => 'Visibility', 'value' => ucfirst($api['visibility']), 'meta' => 'Developer Portal'],
            ['label' => 'Lifecycle', 'value' => $api['lifecycle_label'], 'meta' => 'API maturity'],
            ['label' => 'Runtime Status', 'value' => ucfirst($api['status']), 'meta' => $api['runtime_available'] ? 'Requests allowed' : 'Requests blocked'],
            ['label' => 'Version', 'value' => $api['version'], 'meta' => ucfirst($api['version_status'])],
            ['label' => 'Authentication', 'value' => $api['auth_label'], 'meta' => 'Public metadata'],
            ['label' => 'Category', 'value' => $api['category_label'], 'meta' => 'Directory grouping'],
            ['label' => 'Created', 'value' => $api['created_label'], 'meta' => 'API created'],
            ['label' => 'Updated', 'value' => $api['updated_label'], 'meta' => 'Last modified'],
            ['label' => 'Requests Today', 'value' => number_format($api['requests_today']), 'meta' => 'Selected API only'],
            ['label' => 'Total Requests', 'value' => number_format($api['total_requests']), 'meta' => 'Captured logs'],
            ['label' => 'Error Rate', 'value' => $api['error_rate'] . '%', 'meta' => 'Captured logs'],
            ['label' => 'Average Response Time', 'value' => $api['avg_ms'] . ' ms', 'meta' => 'Captured logs'],
        ];

        $public_action = in_array($api['visibility'], ['public', 'unlisted'], true)
            ? $this->button('View Public Page', $api['urls']['public'], 'secondary')
            : '';

        return $this->metric_grid($stats) .
            '<div class="apiplatform-publisher-workspace-grid">' .
            $this->panel('Endpoint', '<code class="apiplatform-publisher-endpoint">' . esc_html($api['endpoint']) . '</code><div class="apiplatform-publisher-actions">' . $this->button('Edit API', $api['urls']['edit'], 'primary') . $this->button('Test API', $api['urls']['tester'], 'secondary') . $this->button('Edit Documentation', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'secondary') . $this->button('Manage Publishing', $this->api_hub_url($api['id'], ['section' => 'publishing']), 'secondary') . $public_action . '</div>') .
            $this->lifecycle_panel($api) .
            $this->api_health_panel($api) .
            $this->api_diagnostics_panel($api) .
            $this->readiness_panel($api) .
            $this->documentation_status_panel($api) .
            $this->schema_status_panel($api) .
            $this->version_status_panel($api) .
            $this->key_summary_panel($api) .
            $this->application_access_panel($api) .
            $this->recent_requests_panel($api) .
            $this->recent_errors_panel($api) .
            $this->activity_timeline_panel($api) .
            $this->related_links_panel($api) .
            '</div>';
    }

    private function api_configuration_panel(array $api){
        return $this->panel('Configuration', '<p class="apiplatform-publisher-muted">Configuration uses the existing Edit API screen and save handler for title, slug, active/disabled state, endpoint behavior, response JSON, and validation.</p><div class="apiplatform-publisher-actions">' . $this->button('Edit API', $api['urls']['edit'], 'secondary') . $this->button('Edit Canonical Schema', $api['urls']['schema'], 'primary') . $this->button('Test API', $api['urls']['tester'], 'secondary') . '</div>');
    }

    private function lifecycle_panel(array $api){
        $readiness = $api['lifecycle_readiness'] ?? ['ready' => false, 'missing' => []];
        $runtime = $api['runtime_available'] ? 'Available through authenticated requests' : 'Blocked';
        $discovery = $api['public_discoverable'] ? 'Listed publicly' : ($api['visibility'] === 'unlisted' ? 'Direct-link only' : 'Not discoverable');
        $last = $api['last_lifecycle_change'] ?: 'Not captured yet';
        $next = $this->lifecycle_next_action($api);
        $items = [
            ['Lifecycle', $api['lifecycle_label']],
            ['Visibility', ucfirst($api['visibility'])],
            ['Runtime', $runtime],
            ['Public Discovery', $discovery],
            ['Readiness', $api['readiness_percent'] . '%'],
            ['Next Action', $next],
            ['Last Lifecycle Change', $last],
        ];

        $html = $this->definition_list($items);

        if (empty($readiness['ready'])) {
            $missing_labels = $readiness['missing_labels'] ?? [];
            if (!$missing_labels && !empty($readiness['missing'])) {
                foreach ((array) $readiness['missing'] as $missing) {
                    $missing_labels[] = is_array($missing)
                        ? (string) ($missing['label'] ?? $missing['code'] ?? '')
                        : (string) $missing;
                }
            }
            $html .= '<p class="apiplatform-publisher-muted">Incomplete readiness checks: ' . esc_html(implode(', ', array_filter($missing_labels)) ?: 'review the Publish Readiness panel') . '.</p>';
        }

        $html .= '<p class="apiplatform-publisher-muted">Developer Portal Visibility controls portal exposure. API Lifecycle controls whether the API is Private, Testing, Ready, Public, Deprecated, or Archived. Public Status controls the public-facing Active, Beta, or Deprecated version badge.</p>';

        return $this->panel('API Lifecycle', $html . $this->lifecycle_select_form($api) . '<div class="apiplatform-publisher-actions">' . $this->lifecycle_actions($api) . '</div>', 'apiplatform-api-lifecycle');
    }

    private function lifecycle_next_action(array $api){
        $state = $api['lifecycle'];

        if ($state === 'draft') {
            return 'Continue setup or move to Testing.';
        }
        if ($state === 'private') {
            return 'Move to Testing or complete readiness.';
        }
        if ($state === 'testing') {
            return 'Run tester and mark Ready when checks pass.';
        }
        if ($state === 'ready') {
            return 'Preview and publish when you choose.';
        }
        if ($state === 'public') {
            return 'Monitor usage, deprecate, or unpublish.';
        }
        if ($state === 'deprecated') {
            return 'Maintain migration details or archive.';
        }
        if ($state === 'archived') {
            return 'Restore to Private if needed.';
        }

        return 'Review lifecycle settings.';
    }

    private function lifecycle_actions(array $api){
        $state = $api['lifecycle'];
        $actions = '';

        if ($state === 'draft') {
            $actions .= $this->button('Continue Setup', $api['urls']['edit'], 'primary');
            $actions .= $this->lifecycle_post_button('Move to Testing', 'testing', $api['id'], 'secondary');
        } elseif ($state === 'private') {
            $actions .= $this->lifecycle_post_button('Move to Testing', 'testing', $api['id'], 'secondary');
            $actions .= $this->lifecycle_post_button('Mark Ready', 'ready', $api['id'], 'primary');
        } elseif ($state === 'testing') {
            $actions .= $this->button('Run Tester', $api['urls']['tester'], 'secondary');
            $actions .= $this->lifecycle_post_button('Mark Ready', 'ready', $api['id'], 'primary');
            $actions .= $this->lifecycle_post_button('Return to Private', 'private', $api['id'], 'secondary');
        } elseif ($state === 'ready') {
            $actions .= $this->button('Preview', $api['visibility'] === 'private' ? $api['urls']['private_documentation'] : $api['urls']['public'], 'secondary');
            $actions .= $this->lifecycle_post_button('Publish', 'public', $api['id'], 'primary');
            $actions .= $this->lifecycle_post_button('Return to Testing', 'testing', $api['id'], 'secondary');
        } elseif ($state === 'public') {
            $actions .= $this->button('View Public API', $api['urls']['public'], 'secondary');
            $actions .= $this->lifecycle_deprecate_form($api);
            $actions .= $this->lifecycle_post_button('Unpublish', 'private', $api['id'], 'warning');
        } elseif ($state === 'deprecated') {
            $actions .= $this->button('View Migration Details', $this->api_hub_url($api['id'], ['section' => 'versions']), 'secondary');
            $actions .= $this->lifecycle_post_button('Restore to Public', 'public', $api['id'], 'success');
            $actions .= $this->lifecycle_post_button('Archive', 'archived', $api['id'], 'warning');
        } elseif ($state === 'archived') {
            $actions .= $this->lifecycle_post_button('Restore to Private', 'private', $api['id'], 'success');
        }

        return $actions;
    }

    private function lifecycle_select_form(array $api){
        if (!class_exists('APIPlatform_Lifecycle_Policy')) {
            return '<p class="apiplatform-publisher-muted">Lifecycle controls are unavailable.</p>';
        }

        $state = sanitize_key($api['lifecycle'] ?? 'private');
        $states = APIPlatform_Lifecycle_Policy::states();
        $allowed = APIPlatform_Lifecycle_Policy::allowed_transitions();
        $targets = array_unique(array_merge([$state], $allowed[$state] ?? []));
        $form_id = 'apiplatform-lifecycle-form-' . absint($api['id']) . '-' . sanitize_html_class($state);
        $select_id = 'apiplatform-lifecycle-target-' . absint($api['id']) . '-' . sanitize_html_class($state);
        $html = '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url($this->api_hub_url($api['id'], ['section' => 'overview'])) . '" class="apiplatform-publisher-lifecycle-select-form">' .
            $this->owner_hidden_fields('lifecycle', $api['id']) .
            '<label for="' . esc_attr($select_id) . '"><span>Change API Lifecycle</span><select id="' . esc_attr($select_id) . '" class="apiplatform-input" name="apiplatform_lifecycle_target">';

        foreach ($states as $value => $label) {
            $disabled = !in_array($value, $targets, true);
            $label_text = $value === $state ? $label . ' (current)' : $label;
            if ($disabled) {
                $label_text .= ' (not available from ' . APIPlatform_Lifecycle_Policy::label($state) . ')';
            }

            $html .= '<option value="' . esc_attr($value) . '"' . selected($state, $value, false) . disabled($disabled, true, false) . '>' . esc_html($label_text) . '</option>';
        }

        $html .= '</select></label>' .
            '<p class="apiplatform-publisher-muted">Only lifecycle transitions allowed by the platform are selectable. Deprecated lifecycle is separate from the Public Status badge and may require a deprecation notice.</p>' .
            '<button class="apiplatform-button apiplatform-button-primary" type="submit">Update Lifecycle</button>' .
            '</form>';

        return $html;
    }

    private function lifecycle_readiness_action(array $api){
        $state = sanitize_key($api['lifecycle'] ?? 'private');

        if (in_array($state, ['public', 'deprecated'], true)) {
            return '';
        }

        $blocker = $this->lifecycle_public_blocker($api);
        if ($blocker === '') {
            return '<div class="apiplatform-publisher-readiness-action">' .
                $this->lifecycle_post_button('Publish API', 'public', $api['id'], 'primary') .
                '<a class="apiplatform-button apiplatform-button-secondary" href="#apiplatform-api-lifecycle">Review API Lifecycle</a>' .
                '</div>';
        }

        return '<div class="apiplatform-publisher-readiness-action">' .
            '<small>' . esc_html($blocker) . '</small>' .
            '<a class="apiplatform-button apiplatform-button-secondary" href="#apiplatform-api-lifecycle">Review API Lifecycle</a>' .
            '</div>';
    }

    private function lifecycle_public_blocker(array $api){
        if (!class_exists('APIPlatform_Lifecycle_Policy')) {
            return 'Lifecycle policy is unavailable.';
        }

        $state = sanitize_key($api['lifecycle'] ?? 'private');
        $allowed = APIPlatform_Lifecycle_Policy::allowed_transitions();

        if (!in_array('public', $allowed[$state] ?? [], true)) {
            return 'Cannot move lifecycle from ' . APIPlatform_Lifecycle_Policy::label($state) . ' to Public directly.';
        }

        $readiness = APIPlatform_Lifecycle_Policy::readiness($api['id'], ['require_public_visibility' => false]);
        if (empty($readiness['ready'])) {
            return 'Complete these readiness checks first: ' . implode(', ', $readiness['missing_labels'] ?? []) . '.';
        }

        return '';
    }

    private function api_quick_action_bar(array $api){
        $public = in_array($api['visibility'], ['public', 'unlisted'], true)
            ? $this->button('View Public API', $api['urls']['public'], 'secondary')
            : '';

        return '<div class="apiplatform-publisher-quick-actions" aria-label="API quick actions">' .
            $this->button('Edit', $api['urls']['edit'], 'primary') .
            $this->button('Documentation', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'secondary') .
            $this->button('Tester', $api['urls']['tester'], 'secondary') .
            $this->button('Analytics', $api['urls']['analytics'], 'secondary') .
            $this->button('Logs', $api['urls']['logs'], 'secondary') .
            $this->button('Versions', $this->api_hub_url($api['id'], ['section' => 'versions']), 'secondary') .
            $this->button('Publishing', $this->api_hub_url($api['id'], ['section' => 'publishing']), 'secondary') .
            $this->button('Settings', $this->api_hub_url($api['id'], ['section' => 'settings']), 'secondary') .
            $public .
            $this->copy_endpoint_button($api) .
            $this->regenerate_key_form($api) .
            '</div>';
    }

    private function api_health_panel(array $api){
        $warnings = [];

        if ($api['docs_state']['state'] !== 'Ready' && $api['docs_state']['state'] !== 'Published') {
            $warnings[] = 'No ready documentation.';
        }

        if ($api['visibility'] === 'private') {
            $warnings[] = 'Private API.';
        }

        if ((int) $api['total_requests'] === 0) {
            $warnings[] = 'No requests yet.';
        }

        if ($api['status'] !== 'active') {
            $warnings[] = 'API is not active.';
        }

        if ($api['version'] === '') {
            $warnings[] = 'No version metadata.';
        }

        $items = [
            ['Status', ucfirst($api['status'])],
            ['Requests Today', number_format($api['requests_today'])],
            ['Average Response Time', $api['avg_ms'] . ' ms'],
            ['Last Request', $api['last_request'] ?: 'No requests yet'],
            ['Last Error', $api['last_error'] ?: 'No recent errors'],
            ['Publication Status', ucfirst($api['visibility'])],
            ['Documentation Status', $api['docs_state']['state']],
            ['Testing Status', !empty($api['allow_public_tester']) ? 'Public tester enabled' : 'Owner tester available'],
        ];

        $html = $this->definition_list($items);

        if ($warnings) {
            $html .= '<ul class="apiplatform-publisher-warning-list">';
            foreach ($warnings as $warning) {
                $html .= '<li>' . esc_html($warning) . '</li>';
            }
            $html .= '</ul>';
        }

        return $this->panel('API Health', $html);
    }

    /** Read-only, API-scoped operational diagnostics for the authorized API overview. */
    private function api_diagnostics_data($api_id){
        $api_id = absint($api_id);
        $user_id = get_current_user_id();
        if (!$api_id || !$user_id || !class_exists('APIPlatform_Ownership_Service') || !APIPlatform_Ownership_Service::can($user_id, $api_id, 'apis.edit')) {
            return null;
        }

        $plan = class_exists('APIPlatform_Request_History_Retention')
            ? APIPlatform_Request_History_Retention::plan_id_for_api($api_id)
            : '';
        $retention_days = class_exists('APIPlatform_Request_History_Retention')
            ? APIPlatform_Request_History_Retention::effective_retention_days_for_api($api_id)
            : 0;
        $plan_label = $plan && class_exists('APIPlatform_Membership_Panel')
            ? APIPlatform_Membership_Panel::get_plan_label($plan)
            : ($plan ? ucfirst($plan) : 'Not available');
        $scheduled = class_exists('APIPlatform_Request_History_Retention')
            && wp_next_scheduled(APIPlatform_Request_History_Retention::HOOK);

        global $wpdb;
        $aggregate = ['total' => 0, 'oldest' => '', 'newest' => ''];
        $table = $wpdb->prefix . 'apiplatform_logs';
        if ($this->table_exists($table)) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS total, MIN(created_at) AS oldest, MAX(created_at) AS newest FROM `{$table}` WHERE api_id = %d",
                $api_id
            ), ARRAY_A) ?: [];
            $aggregate = [
                'total' => (int) ($row['total'] ?? 0),
                'oldest' => (string) ($row['oldest'] ?? ''),
                'newest' => (string) ($row['newest'] ?? ''),
            ];
        }

        return [
            'plan' => $plan_label,
            'retention_days' => absint($retention_days),
            'scheduled' => (bool) $scheduled,
            'total' => $aggregate['total'],
            'oldest' => $aggregate['oldest'],
            'newest' => $aggregate['newest'],
        ];
    }

    private function api_diagnostics_panel(array $api){
        $diagnostics = $this->api_diagnostics_data($api['id'] ?? 0);
        if (!$diagnostics) {
            return '';
        }

        $has_history = $diagnostics['total'] > 0;
        $items = [
            ['API Status', ucfirst((string) ($api['status'] ?? 'inactive'))],
            ['Plan', $diagnostics['plan']],
            ['Retention Policy', 'Configured'],
            ['Retention Window', $diagnostics['retention_days'] . ' days'],
            ['Cleanup Schedule', $diagnostics['scheduled'] ? 'Scheduled' : 'Not Scheduled'],
            ['Stored Requests', number_format_i18n($diagnostics['total'])],
            ['Oldest Retained Request', $has_history ? $this->date_label($diagnostics['oldest']) : 'No request history'],
            ['Most Recent Request', $has_history ? $this->date_label($diagnostics['newest']) : 'No request history'],
        ];

        return $this->panel('API Diagnostics', $this->definition_list($items));
    }

    private function readiness_panel(array $api){
        $items = !empty($api['lifecycle_readiness']['checks'])
            ? $api['lifecycle_readiness']['checks']
            : $this->readiness_items($api);
        $ready = count(array_filter($items, function($item){ return !empty($item['ready']); }));
        $total = count($items);
        $percent = $total > 0 ? round(($ready / $total) * 100) : 0;
        $html = '<div class="apiplatform-publisher-readiness-progress"><strong>' . esc_html($ready . ' / ' . $total . ' Ready') . '</strong><span><i style="width:' . esc_attr($percent) . '%"></i></span></div>';
        $html .= '<ul class="apiplatform-publisher-checklist">';

        foreach ($items as $item) {
            $action = '';
            if (empty($item['ready']) && sanitize_key($item['code'] ?? '') === 'published_lifecycle') {
                $action = $this->lifecycle_readiness_action($api);
            }

            $html .= '<li class="' . ($item['ready'] ? 'is-ready' : 'is-missing') . '"><span aria-hidden="true">' . ($item['ready'] ? 'OK' : 'TODO') . '</span><strong>' . esc_html($item['label']) . '</strong><small>' . esc_html($item['message'] ?? ($item['ready'] ? 'Ready.' : 'Incomplete.')) . '</small>' . $action . '</li>';
        }

        return $this->panel('Publish Readiness', $html . '</ul>');
    }

    private function documentation_status_panel(array $api){
        $state = $api['docs_state'];
        $completion = class_exists('APIPlatform_Documentation_Workspace')
            ? APIPlatform_Documentation_Workspace::completion($api['id'])
            : ['complete' => 0, 'total' => 0, 'percent' => 0];
        $published_at = get_post_meta($api['id'], 'apiplatform_docs_published_at', true);
        $actions = $this->button('Edit Docs', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'primary') .
            $this->button('Preview', $api['urls']['private_documentation'], 'secondary') .
            $this->button('Publish', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'secondary');

        if ($api['visibility'] !== 'private') {
            $actions .= $this->button('Public Docs', $api['urls']['public_docs'], 'secondary');
        }

        $meta = $this->definition_list([
            ['State', $state['state']],
            ['Current Version', $api['version']],
            ['Last Published', $published_at ? $this->date_label($published_at) : 'Not published'],
            ['Sections Complete', $completion['complete'] . ' / ' . $completion['total'] . ' (' . $completion['percent'] . '%)'],
        ]);

        return $this->panel('Documentation Status', $meta . '<p class="apiplatform-publisher-muted">' . esc_html($state['message']) . '</p><div class="apiplatform-publisher-actions">' . $actions . '</div>');
    }

    private function version_status_panel(array $api){
        $version = $this->version_meta($api['id']);
        $items = [
            ['Current Version', $version['version_label']],
            ['Lifecycle', ucfirst($version['version_status'])],
            ['Deprecated', $version['version_status'] === 'deprecated' ? 'Yes' : 'No'],
            ['Replacement', $version['replacement_version'] ?: 'Not documented'],
            ['Sunset', $version['sunset_date'] ?: 'Not scheduled'],
            ['Migration Message', $version['migration_message'] ?: 'Not documented'],
        ];

        return $this->panel('Version Status', $this->definition_list($items) . '<p class="apiplatform-publisher-muted">Independent runtime versions are not supported yet.</p><div class="apiplatform-publisher-actions">' . $this->button('Edit Version Metadata', $this->api_hub_url($api['id'], ['section' => 'versions']), 'primary') . '</div>');
    }

    private function key_summary_panel(array $api){
        $keys = $this->key_summaries($api['id']);
        $html = '';

        if (!$keys) {
            $html = $this->empty_state('No API keys found.', 'Generate or regenerate keys from the existing secure key workflow.');
        } else {
            foreach (array_slice($keys, 0, 2) as $key) {
                $html .= '<article class="apiplatform-publisher-key-row"><strong>' . esc_html($key['label']) . '</strong><code>' . esc_html($key['masked']) . '</code>' . $this->definition_list([
                    ['Created', $key['created']],
                    ['Last Used', $key['last_used']],
                    ['Status', $key['status']],
                ]) . '</article>';
            }
        }

        return $this->panel('Key Management', $html . '<div class="apiplatform-publisher-actions">' . $this->copy_endpoint_button($api) . $this->regenerate_key_form($api) . $this->button('View Documentation', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'secondary') . $this->button('Test API', $api['urls']['tester'], 'secondary') . '</div>');
    }

    private function application_access_panel(array $api){
        if (!class_exists('APIPlatform_Applications_Service')) {
            return $this->panel('Application Access', $this->not_captured('Developer applications are unavailable.'));
        }

        $rows = APIPlatform_Applications_Service::publisher_access_for_api($api['id'], get_current_user_id());

        if (!$rows) {
            return $this->panel('Application Access', $this->empty_state('No application access yet', 'Applications that request or receive access to this API will appear here.'));
        }

        $html = '<div class="apiplatform-table-wrapper"><table class="apiplatform-table apiplatform-publisher-table"><thead><tr><th>Application</th><th>Owner</th><th>Environment</th><th>Status</th><th>Access</th><th>Last Used</th><th>Actions</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $owner = get_userdata((int) $row['owner_user_id']);
            $html .= '<tr><td><strong>' . esc_html($row['name']) . '</strong><br><span>' . esc_html($row['slug']) . '</span></td><td>' . esc_html($owner ? $owner->display_name : 'Unknown') . '</td><td>' . esc_html(ucfirst($row['environment'])) . '</td><td>' . esc_html(ucfirst($row['application_status'])) . '</td><td>' . esc_html(ucfirst($row['access_status'])) . '</td><td>' . esc_html($this->date_label($row['last_used_at'])) . '</td><td class="apiplatform-publisher-table-actions">';

            if ($row['access_status'] !== 'approved') {
                $html .= $this->application_access_button('Approve', $api['id'], $row['id'], 'approved', 'success');
            }
            if ($row['access_status'] === 'requested') {
                $html .= $this->application_access_button('Deny', $api['id'], $row['id'], 'denied', 'warning');
            }
            if (in_array($row['access_status'], ['approved', 'denied'], true)) {
                $html .= $this->application_access_button('Revoke', $api['id'], $row['id'], 'revoked', 'danger');
            }

            $html .= '</td></tr>';
        }

        return $this->panel('Application Access', $html . '</tbody></table></div>');
    }

    private function recent_requests_panel(array $api){
        $rows = $this->recent_requests_for_api($api['id'], 5, false);

        if (!$rows) {
            return $this->panel('Recent Requests', $this->empty_state('No requests yet.', 'Try your API using the built-in tester.'));
        }

        return $this->panel('Recent Requests', $this->request_rows($rows) . '<div class="apiplatform-publisher-actions">' . $this->button('View Full Request History', $api['urls']['logs'], 'secondary') . '</div>');
    }

    private function recent_errors_panel(array $api){
        $rows = $this->recent_requests_for_api($api['id'], 5, true);

        if (!$rows) {
            return $this->panel('Recent Errors', $this->empty_state('No recent failures.', 'Errors will appear here after failed requests are captured.'));
        }

        return $this->panel('Recent Errors', $this->request_rows($rows) . '<div class="apiplatform-publisher-actions">' . $this->button('View Logs', $api['urls']['logs'], 'secondary') . '</div>');
    }

    private function activity_timeline_panel(array $api){
        $events = $this->timeline_events($api);
        $html = '<ol class="apiplatform-publisher-timeline">';

        foreach ($events as $event) {
            $html .= '<li><strong>' . esc_html($event['title']) . '</strong><span>' . esc_html($event['time']) . '</span></li>';
        }

        return $this->panel('API Activity Timeline', $html . '</ol>');
    }

    private function related_links_panel(array $api){
        $public = in_array($api['visibility'], ['public', 'unlisted'], true)
            ? $this->button('Open Public Page', $api['urls']['public'], 'secondary')
            : '<span class="apiplatform-publisher-disabled-action">Public page unavailable while private</span>';

        return $this->panel('Related Links', '<div class="apiplatform-publisher-action-list">' .
            $public .
            $this->button('Developer Portal', class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url() : home_url('/developers'), 'secondary') .
            $this->button('Documentation', $this->api_hub_url($api['id'], ['section' => 'documentation']), 'secondary') .
            $this->button('Analytics', $api['urls']['analytics'], 'secondary') .
            $this->button('Logs', $api['urls']['logs'], 'secondary') .
            $this->button('Versions', $this->api_hub_url($api['id'], ['section' => 'versions']), 'secondary') .
            $this->button('Settings', $this->api_hub_url($api['id'], ['section' => 'settings']), 'secondary') .
            '</div>');
    }

    private function api_publishing_panel(array $api, $api_post){
        if (!class_exists('APIPlatform_Developer_Portal_Publishing')) {
            return $this->panel('Publishing', $this->not_captured('Developer Portal publishing is not available.'));
        }

        $view_data = [
            'api' => [
                'id' => $api['id'],
                'name' => get_the_title($api_post),
                'slug' => $api_post->post_name,
                'description' => $api_post->post_content,
                'status' => get_post_meta($api['id'], 'apiplatform_status', true) ?: 'active',
                'response_json' => get_post_meta($api['id'], 'api_json', true),
            ],
            'portal' => APIPlatform_Developer_Portal_Publishing::settings_for_view($api['id']),
            'edit_nonce' => wp_create_nonce('apiplatform_edit_api_' . $api['id']),
        ];
        $extra = '<div class="apiplatform-publisher-actions apiplatform-publisher-unpublish-row">';

        if ($api['visibility'] !== 'private' && $api['lifecycle'] !== 'deprecated') {
            $extra .= $this->owner_post_button('Unpublish', 'unpublish', $api['id'], 'warning');
            $extra .= $this->button('View Public API', $api['urls']['public'], 'secondary');
        } elseif ($api['lifecycle'] === 'deprecated') {
            $extra .= '<span class="apiplatform-publisher-disabled-action">Deprecated APIs can be restored to Public or archived from the Lifecycle card.</span>';
        }

        $distinction = $this->panel('Publishing Settings Guide',
            '<div class="apiplatform-publisher-setting-guide">' .
            '<p><strong>Developer Portal Visibility</strong><span>Controls whether this API is Private, Unlisted, or Public in portal routes.</span></p>' .
            '<p><strong>API Lifecycle</strong><span>Controls the real publication state saved in <code>apiplatform_lifecycle_status</code>. Directory readiness requires Public or Deprecated.</span></p>' .
            '<p><strong>Public Status</strong><span>Controls only the public-facing version badge: Active, Beta, or Deprecated.</span></p>' .
            '</div>'
        );

        return $this->lifecycle_panel($api) . $distinction . APIPlatform_Developer_Portal_Publishing::render_settings($view_data) . $extra . '</div>';
    }

    private function api_analytics_panel(array $api){
        return $this->panel('Analytics', '<p class="apiplatform-publisher-muted">This view is scoped to this API and reuses captured request logs only.</p><div class="apiplatform-publisher-actions">' . $this->button('Open API Analytics', $api['urls']['analytics'], 'primary') . $this->button('Open API Logs', $api['urls']['logs'], 'secondary') . '</div>');
    }

    private function api_logs_panel(array $api){
        return $this->panel('Logs', '<p class="apiplatform-publisher-muted">Request History enforces ownership and applies this API ID as a filter.</p><div class="apiplatform-publisher-actions">' . $this->button('Open Filtered Logs', $api['urls']['logs'], 'primary') . '</div>');
    }

    private function api_settings_panel(array $api){
        $danger = $api['status'] === 'archived'
            ? $this->owner_post_button('Restore API', 'restore', $api['id'], 'success')
            : $this->owner_post_button('Archive API', 'archive', $api['id'], 'warning');
        $settings = $this->gateway_settings($api['id']);
        $runtime_labels = [
            'internal' => 'Internal FreedomAPI Response',
            'rest_proxy' => 'REST Proxy',
            'webhook_proxy' => 'Webhook Proxy',
            'external_http' => 'External HTTP Proxy',
        ];
        $validation_labels = [
            'off' => 'Off',
            'warn' => 'Warn Only',
            'strict' => 'Strict',
        ];
        $runtime_summary = $this->definition_list([
            ['Runtime Type', $runtime_labels[$settings['runtime_type']] ?? 'Internal FreedomAPI Response'],
            ['Response Validation', $validation_labels[$settings['response_validation']] ?? 'Off'],
            ['GET Cache', $settings['cache_enabled'] ? 'Enabled for successful GET responses' : 'Disabled'],
            ['Proxy Target', $settings['proxy_url'] !== '' ? $settings['proxy_url'] : 'Not configured'],
        ]);
        $form = '<form method="post" class="apiplatform-publisher-editor-form">' .
            $this->owner_hidden_fields('save-gateway-settings', $api['id']) .
            '<p class="apiplatform-publisher-muted">Runtime settings control how this API executes after authentication, rate limiting, and schema validation. Proxy targets must be HTTPS and never receive API key headers.</p>' .
            $this->field_select('Runtime Type', 'apiplatform_gateway_runtime_type', $settings['runtime_type'], $runtime_labels) .
            $this->field_select('Response Validation', 'apiplatform_gateway_response_validation', $settings['response_validation'], $validation_labels) .
            '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Gateway Cache</span><input type="checkbox" name="apiplatform_gateway_cache_enabled" value="1" ' . checked($settings['cache_enabled'], true, false) . '> Cache successful GET responses</label>' .
            $this->field_input('Cache TTL Seconds', 'apiplatform_gateway_cache_ttl', $settings['cache_ttl'], 'number') .
            $this->field_input('Proxy HTTPS URL', 'apiplatform_gateway_proxy_url', $settings['proxy_url'], 'url') .
            $this->field_input('Allowed Proxy Host', 'apiplatform_gateway_proxy_host', $settings['proxy_host']) .
            $this->field_input('Proxy Timeout Seconds', 'apiplatform_gateway_proxy_timeout', $settings['proxy_timeout'], 'number') .
            '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Save Gateway Settings</button></div></form>';

        return $this->panel('Settings', '<p class="apiplatform-publisher-muted">General settings link to the canonical Edit API workflow. Archive is the normal reversible safety action; permanent deletion remains in the existing danger zone with POST, nonce, and confirmation.</p><div class="apiplatform-publisher-actions">' . $this->button('Edit Settings', $api['urls']['edit'], 'primary') . $danger . $this->button('Permanent Delete Zone', $api['urls']['edit'] . '#apiplatform-danger-zone', 'danger') . '</div>' . $this->panel('Gateway Runtime', $runtime_summary . $form) . $this->ownership_transfer_panel($api));
    }

    private function ownership_transfer_panel(array $api){
        if (!class_exists('APIPlatform_Ownership_Service') || !APIPlatform_Ownership_Service::can(get_current_user_id(), $api['id'], 'apis.transfer')) {
            return '';
        }

        $user_id = get_current_user_id();
        $owner = APIPlatform_Ownership_Service::owner($api['id']);
        $options = '<option value="personal:' . absint($user_id) . '">Personal Workspace</option>';
        foreach (APIPlatform_Organization_Service::list_for_user($user_id) as $organization) {
            if (APIPlatform_Organization_Permissions::can($user_id, $organization['id'], 'apis.create')) {
                $value = 'organization:' . absint($organization['id']);
                $selected = selected($owner['owner_type'] . ':' . $owner['owner_id'], $value, false);
                $options .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($organization['name']) . '</option>';
            }
        }

        $form = '<form method="post" class="apiplatform-publisher-editor-form">' .
            wp_nonce_field('apiplatform_org_action', 'apiplatform_org_nonce', true, false) .
            '<input type="hidden" name="apiplatform_org_action" value="transfer-api">' .
            '<input type="hidden" name="api_id" value="' . absint($api['id']) . '">' .
            '<input type="hidden" name="target_owner_type" value="personal">' .
            '<input type="hidden" name="target_owner_id" value="' . absint($user_id) . '">' .
            '<label class="apiplatform-form-row"><span>Transfer To</span><select class="apiplatform-input" name="target_owner_context">' . $options . '</select></label>' .
            '<label class="apiplatform-form-row"><span>Confirmation</span><input class="apiplatform-input" name="confirmation" placeholder="type transfer"></label>' .
            '<p class="apiplatform-publisher-muted">Transfer preserves keys, docs, schemas, logs, analytics, SDK settings, runtime settings, and portal metadata. It does not duplicate the API.</p>' .
            '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-warning" onclick="var v=this.form.target_owner_context.value.split(\':\');this.form.target_owner_type.value=v[0];this.form.target_owner_id.value=v[1];">Transfer API</button></div></form>';

        return $this->panel('API Ownership', '<p class="apiplatform-publisher-muted">Current owner: ' . esc_html(ucfirst($owner['owner_type']) . ' #' . $owner['owner_id']) . '</p>' . $form);
    }

    private function gateway_settings($api_id){
        $runtime_type = sanitize_key(get_post_meta($api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal');
        $response_validation = sanitize_key(get_post_meta($api_id, 'apiplatform_gateway_response_validation', true) ?: 'off');

        return [
            'runtime_type' => in_array($runtime_type, ['internal', 'rest_proxy', 'webhook_proxy', 'external_http'], true) ? $runtime_type : 'internal',
            'response_validation' => in_array($response_validation, ['off', 'warn', 'strict'], true) ? $response_validation : 'off',
            'cache_enabled' => (bool) get_post_meta($api_id, 'apiplatform_gateway_cache_enabled', true),
            'cache_ttl' => max(1, min(3600, absint(get_post_meta($api_id, 'apiplatform_gateway_cache_ttl', true) ?: 60))),
            'proxy_url' => esc_url_raw(get_post_meta($api_id, 'apiplatform_gateway_proxy_url', true)),
            'proxy_host' => sanitize_text_field(get_post_meta($api_id, 'apiplatform_gateway_proxy_host', true)),
            'proxy_timeout' => max(1, min(15, absint(get_post_meta($api_id, 'apiplatform_gateway_proxy_timeout', true) ?: 5))),
        ];
    }

    private function save_gateway_settings($api, $user_id){
        $api_id = absint($api->ID);
        $runtime_type = sanitize_key(wp_unslash($_POST['apiplatform_gateway_runtime_type'] ?? 'internal'));
        $response_validation = sanitize_key(wp_unslash($_POST['apiplatform_gateway_response_validation'] ?? 'off'));
        $cache_enabled = !empty($_POST['apiplatform_gateway_cache_enabled']) ? '1' : '';
        $cache_ttl = max(1, min(3600, absint($_POST['apiplatform_gateway_cache_ttl'] ?? 60)));
        $proxy_url = esc_url_raw(wp_unslash($_POST['apiplatform_gateway_proxy_url'] ?? ''));
        $proxy_host = sanitize_text_field(wp_unslash($_POST['apiplatform_gateway_proxy_host'] ?? ''));
        $proxy_timeout = max(1, min(15, absint($_POST['apiplatform_gateway_proxy_timeout'] ?? 5)));

        if (!in_array($runtime_type, ['internal', 'rest_proxy', 'webhook_proxy', 'external_http'], true)) {
            $runtime_type = 'internal';
        }

        if (!in_array($response_validation, ['off', 'warn', 'strict'], true)) {
            $response_validation = 'off';
        }

        update_post_meta($api_id, 'apiplatform_gateway_runtime_type', $runtime_type);
        update_post_meta($api_id, 'apiplatform_gateway_response_validation', $response_validation);
        update_post_meta($api_id, 'apiplatform_gateway_cache_enabled', $cache_enabled);
        update_post_meta($api_id, 'apiplatform_gateway_cache_ttl', $cache_ttl);
        update_post_meta($api_id, 'apiplatform_gateway_proxy_url', $proxy_url);
        update_post_meta($api_id, 'apiplatform_gateway_proxy_host', $proxy_host);
        update_post_meta($api_id, 'apiplatform_gateway_proxy_timeout', $proxy_timeout);

        return 'Gateway runtime settings saved.';
    }

    private function api_documentation_editor(array $api, $api_post){
        $docs = $this->documentation_meta($api['id']);
        $completion = class_exists('APIPlatform_Documentation_Workspace')
            ? APIPlatform_Documentation_Workspace::completion($api['id'])
            : ['sections' => [], 'complete' => 0, 'total' => 0, 'percent' => 0, 'required_missing' => []];
        $snapshots = class_exists('APIPlatform_Documentation_Workspace')
            ? APIPlatform_Documentation_Workspace::snapshots($api['id'])
            : [];
        $releases = class_exists('APIPlatform_Documentation_Workspace')
            ? APIPlatform_Documentation_Workspace::releases($api['id'])
            : [];
        $published_at = get_post_meta($api['id'], 'apiplatform_docs_published_at', true);

        $status_cards = '<div class="apiplatform-doc-workspace-stats">' .
            '<div><span>Draft State</span><strong>' . esc_html($api['docs_state']['state']) . '</strong></div>' .
            '<div><span>Published Version</span><strong>' . esc_html($api['version']) . '</strong></div>' .
            '<div><span>Last Published</span><strong>' . esc_html($published_at ? $this->date_label($published_at) : 'Not published') . '</strong></div>' .
            '<div><span>Sections Complete</span><strong>' . esc_html($completion['complete'] . ' / ' . $completion['total']) . '</strong></div>' .
            '</div>';

        $checklist = '<ul class="apiplatform-doc-workspace-checklist">';
        foreach ($completion['sections'] as $label => $ready) {
            $checklist .= '<li class="' . ($ready ? 'is-ready' : 'is-missing') . '"><span>' . esc_html($ready ? 'OK' : 'TODO') . '</span>' . esc_html($label) . '</li>';
        }
        $checklist .= '</ul>';

        $content = '<form method="post" class="apiplatform-publisher-editor-form">';
        $content .= $this->owner_hidden_fields('save-docs', $api['id']);
        $content .= '<label class="apiplatform-doc-workspace-search"><span>Search documentation sections</span><input type="search" class="apiplatform-input" data-doc-workspace-search placeholder="Search sections"></label>';
        $content .= $this->doc_workspace_section('Overview', $this->field_textarea('Overview Markdown', 'apiplatform_docs_overview', $docs['overview'], 7), !empty($completion['sections']['Overview']));
        $content .= $this->doc_workspace_section('Authentication', $this->field_textarea('Authentication Markdown', 'apiplatform_docs_authentication', $docs['authentication'], 5), !empty($completion['sections']['Authentication']));
        $content .= $this->doc_workspace_section('Endpoints', $this->field_textarea('Endpoint Description Markdown', 'apiplatform_docs_endpoint_description', $docs['endpoint_description'], 5) . $this->field_input('Supported Methods', 'apiplatform_docs_methods', implode(', ', $docs['methods'])), !empty($completion['sections']['Endpoint description']));
        $content .= $this->doc_workspace_section('Parameters', $this->field_textarea('Query Parameters JSON', 'apiplatform_docs_parameters', $docs['parameters_json'], 8) . $this->field_textarea('Required Headers JSON', 'apiplatform_docs_headers', $docs['headers_json'], 6), !empty($completion['sections']['Parameters']));
        $content .= $this->doc_workspace_section('Request Examples', $this->field_textarea('Request Body Example JSON', 'apiplatform_docs_request_body', $docs['request_body'], 8), trim((string) $docs['request_body']) !== '' && trim((string) $docs['request_body']) !== '{}');
        $content .= $this->doc_workspace_section('Response Examples', $this->field_textarea('Success Response Example JSON', 'apiplatform_docs_success_response', $docs['success_response'], 8), !empty($completion['sections']['Response example']));
        $content .= $this->doc_workspace_section('Error Responses', $this->field_textarea('Documented Errors JSON', 'apiplatform_docs_errors', $docs['errors_json'], 8), !empty($completion['sections']['Errors']));
        $content .= $this->doc_workspace_section('Rate Limits', $this->field_textarea('Rate-limit Markdown', 'apiplatform_docs_rate_limit', $docs['rate_limit'], 4), !empty($completion['sections']['Rate limits']));
        $content .= $this->doc_workspace_section('SDK Examples', $this->field_textarea('SDK Examples Markdown', 'apiplatform_docs_sdk_examples', $docs['sdk_examples'], 8), !empty($completion['sections']['SDK examples']));
        $content .= $this->doc_workspace_section('FAQ', $this->field_textarea('FAQ Markdown', 'apiplatform_docs_faq', $docs['faq'], 6), !empty($completion['sections']['FAQ']));
        $content .= $this->doc_workspace_section('Migration Guide', $this->field_textarea('Migration Guide Markdown', 'apiplatform_docs_migration_guide', $docs['migration_guide'], 6), !empty($completion['sections']['Migration guide']));
        $content .= '<div class="apiplatform-doc-workspace-preview"><h3>Draft Preview</h3><div>' . (class_exists('APIPlatform_Documentation_Workspace') ? APIPlatform_Documentation_Workspace::markdown($docs['overview']) : nl2br(esc_html($docs['overview']))) . '</div></div>';
        $content .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Save Draft</button>' . $this->button('Preview Private Documentation', $api['urls']['private_documentation'], 'secondary');

        if ($api['visibility'] !== 'private') {
            $content .= $this->button('Preview Public Documentation', $api['urls']['public_docs'], 'secondary');
        }

        $content .= '</div></form>';
        $publish = '<form method="post" class="apiplatform-publisher-inline-form apiplatform-doc-workspace-publish">' .
            $this->owner_hidden_fields('publish-docs', $api['id']) .
            '<button class="apiplatform-button apiplatform-button-primary" type="submit">Publish Documentation Snapshot</button>';
        if (!empty($completion['required_missing'])) {
            $publish .= '<span class="apiplatform-publisher-disabled-action">Missing required sections: ' . esc_html(implode(', ', $completion['required_missing'])) . '</span>';
        }
        $publish .= '</form>';

        $history = $this->documentation_version_history($snapshots, $releases);
        $release_form = $this->documentation_release_form($api);

        return $this->panel('Documentation Workspace', '<p class="apiplatform-publisher-muted">Drafts feed the existing private documentation generator. Publishing creates an immutable public snapshot for the selected version.</p>' . $status_cards . $checklist . $content . $publish . $release_form . $history);
    }

    private function api_endpoint_schema_panel(array $api, $api_post){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return $this->panel('Endpoint Schema', $this->not_captured('Endpoint schema service is unavailable.'));
        }

        $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api['id']);
        $models = APIPlatform_Endpoint_Schema_Service::models($api['id'], $api['version']);
        $summary = APIPlatform_Endpoint_Schema_Service::validation_summary($api['id']);
        $diff = APIPlatform_Endpoint_Schema_Service::compare_with_previous($api['id']);
        $endpoint_json = wp_json_encode($schema['endpoints'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $model_json = wp_json_encode($models, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $stats = '<div class="apiplatform-doc-workspace-stats">' .
            '<div><span>Schema Version</span><strong>' . esc_html($schema['version']) . '</strong></div>' .
            '<div><span>Endpoints</span><strong>' . esc_html(count($schema['endpoints'])) . '</strong></div>' .
            '<div><span>Models</span><strong>' . esc_html(count($models)) . '</strong></div>' .
            '<div><span>Readiness</span><strong>' . esc_html($summary['score'] . '%') . '</strong></div>' .
            '</div>';

        $warnings = '<ul class="apiplatform-publisher-warning-list">';
        foreach (array_merge($summary['errors'], $summary['warnings']) as $item) {
            $warnings .= '<li>' . esc_html(is_array($item) ? ($item['message'] ?? 'Schema issue') : $item) . '</li>';
        }
        $warnings .= '</ul>';
        if (empty($summary['errors']) && empty($summary['warnings'])) {
            $warnings = $this->empty_state('Schema checks passed.', 'Generated documentation and tester forms can use this schema.');
        }

        $diff_html = '<div class="apiplatform-doc-version-history"><h3>Schema Changes</h3>';
        if (empty($diff['previous_version'])) {
            $diff_html .= $this->empty_state('No previous schema version.', 'Publish another API version to compare schema changes.');
        } elseif (empty($diff['changes'])) {
            $diff_html .= $this->empty_state('No schema changes detected.', 'Current schema matches the previous saved version.');
        } else {
            $diff_html .= '<p class="apiplatform-publisher-muted">Compared with ' . esc_html($diff['previous_version']) . '.</p><ul class="apiplatform-publisher-warning-list">';
            foreach ($diff['changes'] as $change) {
                $diff_html .= '<li>' . esc_html($change) . '</li>';
            }
            $diff_html .= '</ul>';
            if (!empty($diff['breaking'])) {
                $diff_html .= '<p class="apiplatform-publisher-muted">Breaking changes detected: ' . esc_html(implode(', ', $diff['breaking'])) . '</p>';
            }
        }
        $diff_html .= '</div>';

        $schema_form = '<form method="post" class="apiplatform-publisher-editor-form">';
        $schema_form .= $this->owner_hidden_fields('save-endpoint-schema', $api['id']);
        $schema_form .= '<input type="hidden" name="apiplatform_schema_version_label" value="' . esc_attr($schema['version']) . '">';
        $schema_form .= '<p class="apiplatform-publisher-muted">Edit the canonical endpoint schema for this API version. This replaces the old loose endpoint fields as the source used by generated docs, examples, tester defaults, and validation.</p>';
        $schema_form .= $this->field_textarea('Endpoint Schema JSON', 'apiplatform_endpoint_schema_json', $endpoint_json, 18);
        $schema_form .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Save Endpoint Schema</button></div></form>';

        $models_form = '<form method="post" class="apiplatform-publisher-editor-form">';
        $models_form .= $this->owner_hidden_fields('save-data-models', $api['id']);
        $models_form .= '<input type="hidden" name="apiplatform_schema_version_label" value="' . esc_attr($schema['version']) . '">';
        $models_form .= '<p class="apiplatform-publisher-muted">Reusable data models are versioned with the API and may be referenced by endpoints and responses.</p>';
        $models_form .= $this->field_textarea('Data Models JSON', 'apiplatform_data_models_json', $model_json, 12);
        $models_form .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary" type="submit">Save Data Models</button></div></form>';

        $validation_form = '<form method="post" class="apiplatform-publisher-editor-form">';
        $validation_form .= $this->owner_hidden_fields('save-validation-settings', $api['id']);
        $validation_form .= $this->field_select('Validation Mode', 'apiplatform_schema_validation_mode', $summary['mode'], APIPlatform_Endpoint_Schema_Service::validation_modes());
        $mode_descriptions = APIPlatform_Endpoint_Schema_Service::validation_mode_descriptions();
        $validation_form .= '<p class="apiplatform-publisher-muted">' . esc_html($mode_descriptions[$summary['mode']] ?? '') . '</p>';
        $validation_form .= '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Unknown body fields</span><input type="checkbox" name="apiplatform_schema_reject_unknown" value="1" ' . checked(!empty($summary['reject_unknown']), true, false) . '> Reject unknown fields when enforce mode is active</label>';
        $validation_form .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary" type="submit">Save Validation Settings</button></div></form>';

        return $this->panel('Endpoint Schema Workspace', $stats . $warnings . $diff_html . $schema_form . $models_form . $validation_form . $this->openapi_import_export_panel($api, $schema) . $this->sdk_generation_panel($api, $schema));
    }

    private function openapi_import_export_panel(array $api, array $schema){
        if (!class_exists('APIPlatform_OpenAPI_Service')) {
            return $this->not_captured('OpenAPI import/export service is unavailable.');
        }

        $preview = get_transient($this->openapi_preview_key(get_current_user_id(), $api['id']));
        $export = get_transient($this->openapi_export_key(get_current_user_id(), $api['id']));
        $html = '<section class="apiplatform-doc-version-history"><h3>OpenAPI 3.1 Import / Export</h3>';

        if (is_array($preview)) {
            $html .= '<div class="apiplatform-publisher-empty"><strong>Import Preview</strong>';
            $html .= '<p>Endpoints to create: ' . esc_html(count($preview['preview']['endpoints_create'] ?? [])) . '. Endpoints to update: ' . esc_html(count($preview['preview']['endpoints_update'] ?? [])) . '.</p>';
            $html .= '<p>Models to create: ' . esc_html(count($preview['preview']['models_create'] ?? [])) . '. Models to update: ' . esc_html(count($preview['preview']['models_update'] ?? [])) . '.</p>';
            if (!empty($preview['preview']['warnings'])) {
                $html .= '<p>Warnings: ' . esc_html(implode(', ', $preview['preview']['warnings'])) . '</p>';
            }
            if (!empty($preview['preview']['unsupported'])) {
                $html .= '<p>Unsupported features: ' . esc_html(implode(', ', $preview['preview']['unsupported'])) . '</p>';
            }
            $html .= '<form method="post" class="apiplatform-publisher-inline-form">' . $this->owner_hidden_fields('confirm-openapi-import', $api['id']) .
                '<button class="apiplatform-button apiplatform-button-primary" type="submit">Confirm OpenAPI Import</button></form></div>';
        }

        if (is_array($export) && !empty($export['content'])) {
            $download = 'data:application/octet-stream;charset=utf-8,' . rawurlencode($export['content']);
            $filename = sanitize_title($api['title'] . '-' . ($schema['version'] ?? 'v1') . '-openapi') . '.' . ($export['format'] === 'yaml' ? 'yaml' : 'json');
            $html .= '<div class="apiplatform-doc-workspace-preview"><h3>OpenAPI Export</h3><p class="apiplatform-publisher-muted">Generated from the canonical schema for ' . esc_html($schema['version'] ?? $api['version']) . '.</p><textarea id="apiplatform_openapi_export_output" class="apiplatform-input" rows="12" readonly>' . esc_textarea($export['content']) . '</textarea><div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary apiplatform-copy-value" type="button" data-copy-source="apiplatform_openapi_export_output" data-copy-value="' . esc_attr($export['content']) . '" data-copy-success="Copied!">Copy ' . esc_html(strtoupper($export['format'])) . '</button><a class="apiplatform-button apiplatform-button-primary" download="' . esc_attr($filename) . '" href="' . esc_url($download) . '">Download ' . esc_html(strtoupper($export['format'])) . '</a></div></div>';
            delete_transient($this->openapi_export_key(get_current_user_id(), $api['id']));
        }

        $html .= '<form method="post" enctype="multipart/form-data" class="apiplatform-publisher-editor-form">';
        $html .= $this->owner_hidden_fields('preview-openapi-import', $api['id']);
        $html .= '<input type="hidden" name="apiplatform_schema_version_label" value="' . esc_attr($schema['version'] ?? $api['version']) . '">';
        $html .= '<p class="apiplatform-publisher-muted">Preview an OpenAPI 3.0.x or 3.1.x import. Nothing is saved until the preview is confirmed.</p>';
        $html .= $this->field_select('Source Format', 'apiplatform_openapi_source_type', 'json', ['json' => 'JSON', 'yaml' => 'YAML']);
        $html .= '<div class="apiplatform-form-row"><label for="apiplatform_openapi_file">Upload File</label><input id="apiplatform_openapi_file" class="apiplatform-input" type="file" name="apiplatform_openapi_file" accept=".json,.yaml,.yml,application/json,text/yaml"></div>';
        $html .= $this->field_textarea('Paste OpenAPI JSON or YAML', 'apiplatform_openapi_source', '', 12);
        $html .= '<div class="apiplatform-form-row"><label for="apiplatform_openapi_url">Import URL</label><input id="apiplatform_openapi_url" class="apiplatform-input" type="url" name="apiplatform_openapi_url" value="" placeholder="Future-ready: remote import is not enabled yet"><small class="apiplatform-publisher-muted">Remote URL import is intentionally disabled in this build, so no external OpenAPI document is fetched or executed.</small></div>';
        $html .= $this->field_input('Import Version Label', 'apiplatform_openapi_version_label', $schema['version'] ?? $api['version']);
        $html .= $this->field_select('Import Mode', 'apiplatform_openapi_import_mode', 'replace', [
            'replace' => 'Replace Current Version',
            'new-version' => 'Import As New Version',
            'merge-missing' => 'Merge Missing Endpoints and Models',
            'skip-existing' => 'Skip Existing Version',
        ]);
        $html .= $this->field_select('After Import', 'apiplatform_openapi_publish_mode', 'draft', [
            'draft' => 'Save As Draft',
            'publish' => 'Publish Immediately',
        ]);
        $html .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Preview OpenAPI Import</button></div></form>';

        $html .= '<form method="post" class="apiplatform-publisher-editor-form">';
        $html .= $this->owner_hidden_fields('export-openapi', $api['id']);
        $html .= '<p class="apiplatform-publisher-muted">Export uses only the canonical FreedomAPI schema for the current API version.</p>';
        $html .= $this->field_select('Export Format', 'apiplatform_openapi_export_format', 'json', ['json' => 'JSON', 'yaml' => 'YAML']);
        $html .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary" type="submit">Generate OpenAPI Export</button></div></form>';

        return $html . '</section>';
    }

    private function sdk_generation_panel(array $api, array $schema){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return $this->not_captured('SDK generation service is unavailable.');
        }

        $settings = APIPlatform_SDK_Generator_Service::settings($api['id']);
        $preview_token = isset($_GET['sdk_preview']) ? sanitize_text_field(wp_unslash($_GET['sdk_preview'])) : '';
        $preview = $preview_token !== ''
            ? get_transient($this->sdk_preview_key(get_current_user_id(), $api['id'], $preview_token))
            : null;
        $preview_expired = $preview_token !== '' && !is_array($preview);
        $language_options = APIPlatform_SDK_Generator_Service::languages();
        $selected_language = APIPlatform_SDK_Generator_Service::normalize_language($_GET['sdk_language'] ?? ($preview['language'] ?? '')) ?: 'javascript';
        $html = '<section class="apiplatform-doc-version-history"><h3>SDK Generation</h3>';
        $html .= '<div class="apiplatform-publisher-schema-stats">' .
            '<div><span>Selected API</span><strong>' . esc_html($api['title']) . '</strong></div>' .
            '<div><span>Selected Version</span><strong>' . esc_html($schema['version'] ?? $api['version']) . '</strong></div>' .
            '<div><span>Base URL</span><strong>' . esc_html(function_exists('apiplatform_public_gateway_url') ? apiplatform_public_gateway_url($api['id']) : ($api['endpoint'] ?? (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url() : untrailingslashit(rest_url('platform/v1/api'))))) . '</strong></div>' .
            '<div><span>Authentication</span><strong>Application Key</strong></div>' .
            '<div><span>Endpoints</span><strong>' . esc_html(count($schema['endpoints'] ?? [])) . '</strong></div>' .
            '<div><span>Models</span><strong>' . esc_html(count(APIPlatform_Endpoint_Schema_Service::models($api['id'], $schema['version'] ?? $api['version']))) . '</strong></div>' .
            '</div>';

        if (is_array($preview)) {
            $html .= '<div class="apiplatform-publisher-empty"><strong>SDK Preview</strong>';
            $html .= '<p>Language: ' . esc_html($preview['label'] ?? '') . '</p>';
            $html .= '<p>API Version: ' . esc_html($preview['version'] ?? ($schema['version'] ?? $api['version'])) . '</p>';
            $html .= '<p>Package: ' . esc_html($preview['package_name'] ?? '') . '</p>';
            $html .= '<p>Client Class: ' . esc_html($preview['client_class'] ?? '') . '</p>';
            $html .= '<p>Endpoints: ' . esc_html((string) ($preview['endpoint_count'] ?? 0)) . '</p>';
            $html .= '<p>Models: ' . esc_html((string) ($preview['model_count'] ?? 0)) . '</p>';
            $html .= '<p>Files:</p>' . $this->sdk_preview_list($preview['files'] ?? [], 'No files would be generated.');
            $html .= '<p>Methods:</p>' . $this->sdk_preview_list($preview['methods'] ?? [], 'No endpoint methods are available.');
            $html .= '<p>Model names:</p>' . $this->sdk_preview_list($preview['models'] ?? [], 'No reusable models are available.');
            if (!empty($preview['warnings'])) {
                $html .= '<p>Warnings:</p>' . $this->sdk_preview_list($preview['warnings'], 'No warnings.');
            }
            if (!empty($preview['unsupported'])) {
                $html .= '<p>Unsupported features:</p>' . $this->sdk_preview_list($preview['unsupported'], 'No unsupported features detected.');
            }
            $html .= '</div>';
            delete_transient($this->sdk_preview_key(get_current_user_id(), $api['id'], $preview_token));
        } elseif ($preview_expired) {
            $html .= '<div class="apiplatform-publisher-empty"><strong>SDK preview expired</strong><p>Generate a new preview to see SDK files and methods.</p></div>';
        }

        $html .= '<form method="post" class="apiplatform-publisher-editor-form">';
        $html .= $this->owner_hidden_fields('save-sdk-settings', $api['id']);
        $html .= '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Enable SDK downloads</span><input type="checkbox" name="apiplatform_sdk_enabled" value="1" ' . checked(!empty($settings['enabled']), true, false) . '></label>';
        $html .= $this->field_input('Package Display Name', 'apiplatform_sdk_package_name', $settings['package_name']);
        $html .= $this->field_input('Package Namespace', 'apiplatform_sdk_namespace', $settings['namespace']);
        $html .= $this->field_input('Client Class Name', 'apiplatform_sdk_client_class', $settings['client_class']);
        $html .= '<div class="apiplatform-form-row"><label>Allowed Languages</label><div class="apiplatform-publisher-actions">';
        foreach ($language_options as $value => $label) {
            $html .= '<label class="apiplatform-checkbox-row"><input type="checkbox" name="apiplatform_sdk_allowed_languages[]" value="' . esc_attr($value) . '" ' . checked(in_array($value, $settings['allowed_languages'], true), true, false) . '> <span>' . esc_html($label) . '</span></label>';
        }
        $html .= '</div></div>';
        $html .= '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Include models</span><input type="checkbox" name="apiplatform_sdk_include_models" value="1" ' . checked(!empty($settings['include_models']), true, false) . '></label>';
        $html .= '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Include examples</span><input type="checkbox" name="apiplatform_sdk_include_examples" value="1" ' . checked(!empty($settings['include_examples']), true, false) . '></label>';
        $html .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary" type="submit">Save SDK Settings</button></div></form>';

        $html .= '<form method="post" class="apiplatform-publisher-editor-form">';
        $html .= $this->owner_hidden_fields('preview-sdk', $api['id']);
        $html .= '<input type="hidden" name="apiplatform_sdk_version" value="' . esc_attr($schema['version'] ?? $api['version']) . '">';
        $html .= '<input type="hidden" name="sdk_package_name" value="' . esc_attr($settings['package_name']) . '">';
        $html .= '<input type="hidden" name="sdk_namespace" value="' . esc_attr($settings['namespace']) . '">';
        $html .= '<input type="hidden" name="sdk_client_class" value="' . esc_attr($settings['client_class']) . '">';
        $html .= '<input type="hidden" name="sdk_include_models" value="' . esc_attr(!empty($settings['include_models']) ? '1' : '0') . '">';
        $html .= '<input type="hidden" name="sdk_include_examples" value="' . esc_attr(!empty($settings['include_examples']) ? '1' : '0') . '">';
        $html .= $this->field_select('Language', 'sdk_language', $selected_language, $language_options);
        $html .= $this->field_select('Generation Type', 'sdk_generation_type', 'client', ['snippet' => 'Integration Snippet', 'client' => 'Generated Client Library']);
        $html .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Generate Preview</button></div></form>';

        $html .= '<form method="post" class="apiplatform-publisher-editor-form">';
        $html .= $this->owner_hidden_fields('download-sdk', $api['id']);
        $html .= '<input type="hidden" name="apiplatform_sdk_version" value="' . esc_attr($schema['version'] ?? $api['version']) . '">';
        $html .= '<input type="hidden" name="sdk_generation_type" value="client">';
        $html .= '<input type="hidden" name="sdk_package_name" value="' . esc_attr($settings['package_name']) . '">';
        $html .= '<input type="hidden" name="sdk_namespace" value="' . esc_attr($settings['namespace']) . '">';
        $html .= '<input type="hidden" name="sdk_client_class" value="' . esc_attr($settings['client_class']) . '">';
        $html .= '<input type="hidden" name="sdk_include_models" value="' . esc_attr(!empty($settings['include_models']) ? '1' : '0') . '">';
        $html .= '<input type="hidden" name="sdk_include_examples" value="' . esc_attr(!empty($settings['include_examples']) ? '1' : '0') . '">';
        $html .= $this->field_select('Download Language', 'sdk_language', $selected_language, $language_options);
        $html .= '<p class="apiplatform-publisher-muted">Downloads are generated only after this POST action. Generated files use YOUR_APPLICATION_KEY or environment variables, never real credentials.</p>';
        $html .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Download Client ZIP</button></div></form>';

        return $html . '</section>';
    }

    private function sdk_preview_list(array $items, $empty_message){
        if (!$items) {
            return '<p class="apiplatform-publisher-muted">' . esc_html($empty_message) . '</p>';
        }

        $html = '<ul class="apiplatform-publisher-warning-list">';
        foreach ($items as $item) {
            $html .= '<li>' . esc_html((string) $item) . '</li>';
        }
        return $html . '</ul>';
    }

    private function schema_status_panel(array $api){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return $this->panel('Endpoint Schema', $this->not_captured('Endpoint schema service is unavailable.'));
        }

        $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api['id']);
        $summary = APIPlatform_Endpoint_Schema_Service::validation_summary($api['id']);
        $items = [
            ['Schema Version', $schema['version']],
            ['Endpoints', count($schema['endpoints'])],
            ['Validation Mode', ucfirst($summary['mode'])],
            ['Readiness Score', $summary['score'] . '%'],
        ];

        return $this->panel('Endpoint Schema', $this->definition_list($items) . '<div class="apiplatform-publisher-actions">' . $this->button('Edit Schema', $this->api_hub_url($api['id'], ['section' => 'schema']), 'primary') . $this->button('Open Tester', $api['urls']['tester'], 'secondary') . '</div>');
    }

    private function api_versions_panel(array $api){
        $version = $this->version_meta($api['id']);
        $content = '<form method="post" class="apiplatform-publisher-editor-form">';
        $content .= $this->owner_hidden_fields('save-version', $api['id']);
        $content .= '<p class="apiplatform-publisher-muted"><strong>Version metadata</strong> describes the current runtime API. Independent persisted API runtime versions are not supported yet.</p>';
        $content .= $this->field_input('Public Version Label', 'apiplatform_portal_version_label', $version['version_label']);
        $content .= $this->field_select('Lifecycle State', 'apiplatform_portal_version_status', $version['version_status'], [
            'current' => 'Current',
            'beta' => 'Beta',
            'deprecated' => 'Deprecated',
        ]);
        $content .= $this->field_textarea('Deprecation Notice', 'apiplatform_portal_deprecation_notice', $version['deprecation_notice'], 4);
        $content .= $this->field_textarea('Migration Message', 'apiplatform_portal_migration_message', $version['migration_message'], 4);
        $content .= $this->field_input('Migration URL', 'apiplatform_portal_migration_url', $version['migration_url'], 'url');
        $content .= $this->field_input('Sunset Date', 'apiplatform_portal_sunset_date', $version['sunset_date'], 'date');
        $content .= $this->field_input('Replacement Version Label', 'apiplatform_portal_replacement_version', $version['replacement_version']);
        $content .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-primary" type="submit">Save Version Metadata</button><span class="apiplatform-publisher-disabled-action">Runtime version cloning not supported yet</span></div></form>';

        return $this->panel('Version Metadata', $content);
    }

    private function doc_workspace_section($title, $fields, $ready){
        return '<details class="apiplatform-doc-workspace-section" data-doc-workspace-section open>' .
            '<summary><span>' . esc_html($title) . '</span><strong class="' . esc_attr($ready ? 'is-ready' : 'is-missing') . '">' . esc_html($ready ? 'Complete' : 'Needs content') . '</strong></summary>' .
            '<div class="apiplatform-doc-workspace-section-body">' . $fields . '</div>' .
            '</details>';
    }

    private function documentation_release_form(array $api){
        $content = '<form method="post" class="apiplatform-publisher-editor-form apiplatform-doc-release-form">';
        $content .= $this->owner_hidden_fields('save-doc-release', $api['id']);
        $content .= '<h3>Changelog Entry</h3>';
        $content .= $this->field_input('Version', 'apiplatform_release_version_label', $api['version']);
        $content .= $this->field_input('Release Title', 'apiplatform_release_title', '');
        $content .= $this->field_input('Release Date', 'apiplatform_release_date', current_time('Y-m-d'), 'date');
        $content .= $this->field_select('Release Type', 'apiplatform_release_type', 'changed', [
            'added' => 'Added',
            'changed' => 'Changed',
            'fixed' => 'Fixed',
            'deprecated' => 'Deprecated',
            'removed' => 'Removed',
            'security' => 'Security',
            'performance' => 'Performance',
        ]);
        $content .= '<label class="apiplatform-form-row apiplatform-checkbox-row"><span>Breaking Change</span><input type="checkbox" name="apiplatform_release_breaking_change" value="1"></label>';
        $content .= $this->field_textarea('Summary', 'apiplatform_release_summary', '', 3);
        $content .= $this->field_textarea('Entries', 'apiplatform_release_entries', '', 5);
        $content .= $this->field_textarea('Migration Notes', 'apiplatform_release_migration_notes', '', 4);
        $content .= '<div class="apiplatform-publisher-actions"><button class="apiplatform-button apiplatform-button-secondary" type="submit">Save Changelog Entry</button></div></form>';

        return $content;
    }

    private function documentation_version_history(array $snapshots, array $releases){
        $html = '<div class="apiplatform-doc-version-history"><h3>Version History</h3>';

        if (!$snapshots && !$releases) {
            return $html . $this->empty_state('No documentation versions yet.', 'Publish documentation to create the first immutable snapshot.') . '</div>';
        }

        if ($snapshots) {
            $html .= '<h4>Snapshots</h4><div class="apiplatform-table-wrapper"><table class="apiplatform-table apiplatform-publisher-table"><thead><tr><th>Version</th><th>State</th><th>Snapshot</th><th>Published</th></tr></thead><tbody>';
            foreach ($snapshots as $snapshot) {
                $html .= '<tr><td>' . esc_html($snapshot['version_label']) . '</td><td>' . esc_html(ucfirst($snapshot['documentation_state'])) . '</td><td><code>' . esc_html($snapshot['snapshot_uid']) . '</code></td><td>' . esc_html($this->date_label($snapshot['published_at'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        if ($releases) {
            $html .= '<h4>Changelog</h4><div class="apiplatform-doc-release-list">';
            foreach (array_slice($releases, 0, 8) as $release) {
                $html .= '<article><strong>' . esc_html($release['release_title'] ?: $release['version_label']) . '</strong><span>' . esc_html(ucfirst($release['release_type']) . ' - ' . $release['release_date']) . '</span><p>' . esc_html($release['summary'] ?: 'No summary provided.') . '</p></article>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    private function api_keys_panel(array $api){
        $keys = get_post_meta($api['id'], 'api_keys', true);
        $keys = is_array($keys) ? $keys : [];
        $content = '<div class="apiplatform-publisher-key-list">';
        $security = function_exists('apiplatform_api_key_security_status')
            ? apiplatform_api_key_security_status($api['id'])
            : ['state' => 'current'];
        $content .= !empty($security['legacy_active'])
            ? '<p class="apiplatform-publisher-muted"><strong>API Key Security:</strong> Legacy key compatibility active. Regenerate this API key to move to the current secure key format.</p>'
            : '<p class="apiplatform-publisher-muted"><strong>API Key Security:</strong> Current hashed key storage.</p>';

        if (!$keys) {
            $content .= $this->empty_state('No API keys found.', 'Generate keys from the existing Keys or Edit API workflow.');
        }

        foreach ($keys as $key) {
            $masked = function_exists('apiplatform_key_entry_display')
                ? apiplatform_key_entry_display($key)
                : ($key['masked'] ?? ($key['key'] ?? 'Masked key unavailable'));
            $created = !empty($key['created']) ? gmdate('Y-m-d H:i', (int) $key['created']) : 'Not captured';
            $status = !isset($key['enabled']) || $key['enabled'] ? 'Active' : 'Disabled';
            $content .= '<article class="apiplatform-publisher-key-row"><div><strong>' . esc_html($key['label'] ?? 'API Key') . '</strong><code>' . esc_html($masked) . '</code></div><dl class="apiplatform-publisher-meta"><div><dt>Created</dt><dd>' . esc_html($created) . '</dd></div><div><dt>Last Used</dt><dd>Not captured</dd></div><div><dt>Status</dt><dd>' . esc_html($status) . '</dd></div></dl></article>';
        }

        $content .= '</div><div class="apiplatform-publisher-actions">' . $this->button('Manage Keys', $api['urls']['edit'] . '#apiplatform-edit-keys', 'primary') . '<span class="apiplatform-publisher-disabled-action">Plaintext available only during one-time reveal</span></div>';

        return $this->panel('API Keys', $content);
    }

    private function owned_apis($user_id){
        $api_ids = class_exists('APIPlatform_Organization_Permissions')
            ? APIPlatform_Organization_Permissions::user_api_ids($user_id, 'apis.view')
            : [];

        $args = [
            'post_type' => 'user_api',
            'author' => absint($user_id),
            'numberposts' => -1,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'no_found_rows' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (current_user_can('manage_options')) {
            unset($args['author']);
        } elseif ($api_ids) {
            unset($args['author']);
            $args['post__in'] = array_map('absint', $api_ids);
        } elseif (class_exists('APIPlatform_Organization_Permissions')) {
            $args['post__in'] = [0];
        }

        $posts = get_posts($args);
        $apis = [];

        foreach ($posts as $post) {
            $settings = class_exists('APIPlatform_Developer_Portal_Query')
                ? APIPlatform_Developer_Portal_Query::get_settings($post->ID)
                : [];
            $title = (string) ($settings['title'] ?? get_the_title($post));
            $visibility = class_exists('APIPlatform_API_Status')
                ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? 'private')
                : sanitize_key($settings['visibility'] ?? 'private');
            $portal_slug = sanitize_title($settings['portal_slug'] ?? $post->post_name);
            $category = sanitize_title($settings['category'] ?? '');
            $auth = sanitize_key($settings['authentication_type'] ?? 'api-key');
            $updated = $settings['updated_at'] ?: get_post_modified_time('Y-m-d H:i:s', false, $post);
            $lifecycle = class_exists('APIPlatform_Lifecycle_Policy')
                ? APIPlatform_Lifecycle_Policy::ensure_state($post->ID, $user_id)
                : (get_post_meta($post->ID, 'apiplatform_status', true) ?: 'private');
            $lifecycle_readiness = class_exists('APIPlatform_Lifecycle_Policy')
                ? APIPlatform_Lifecycle_Policy::readiness($post->ID)
                : ['ready' => false, 'checks' => [], 'missing' => []];
            $ready_count = count(array_filter($lifecycle_readiness['checks'] ?? [], function($check){ return !empty($check['ready']); }));
            $ready_total = count($lifecycle_readiness['checks'] ?? []);
            $last_event = class_exists('APIPlatform_Lifecycle_Policy')
                ? APIPlatform_Lifecycle_Policy::last_event($post->ID)
                : null;
            $status = class_exists('APIPlatform_API_Status')
                ? APIPlatform_API_Status::normalize_runtime_status(get_post_meta($post->ID, 'apiplatform_status', true) ?: ($post->post_status === 'publish' ? 'active' : $post->post_status))
                : (get_post_meta($post->ID, 'apiplatform_status', true) ?: ($post->post_status === 'publish' ? 'active' : $post->post_status));
            $owner = class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($post->ID) : ['owner_type' => 'personal', 'owner_id' => (int) $post->post_author, 'owner_name' => 'Personal', 'owner_slug' => ''];
            $owner_label = $owner['owner_name'] ?? ($owner['owner_type'] === 'organization' ? 'Organization' : 'Personal');

            $apis[(int) $post->ID] = [
                'id' => (int) $post->ID,
                'title' => $title,
                'summary' => (string) ($settings['summary'] ?? ''),
                'slug' => $post->post_name,
                'portal_slug' => $portal_slug,
                'initials' => class_exists('APIPlatform_Developer_Portal_Query') ? APIPlatform_Developer_Portal_Query::initials($title) : strtoupper(substr($title, 0, 2)),
                'version' => (string) ($settings['version_label'] ?? 'v1'),
                'version_status' => (string) ($settings['version_status'] ?? 'current'),
                'visibility' => $visibility,
                'status' => $status,
                'lifecycle' => $lifecycle,
                'lifecycle_label' => class_exists('APIPlatform_Lifecycle_Policy') ? APIPlatform_Lifecycle_Policy::label($lifecycle) : ucfirst($lifecycle),
                'lifecycle_readiness' => $lifecycle_readiness,
                'readiness_percent' => $ready_total > 0 ? (int) round(($ready_count / $ready_total) * 100) : 0,
                'runtime_available' => class_exists('APIPlatform_Lifecycle_Policy') ? !empty(APIPlatform_Lifecycle_Policy::runtime_access($post->ID)['allowed']) : ($status === 'active'),
                'gateway_runtime_type' => $this->gateway_settings($post->ID)['runtime_type'],
                'gateway_response_validation' => $this->gateway_settings($post->ID)['response_validation'],
                'gateway_cache_enabled' => $this->gateway_settings($post->ID)['cache_enabled'],
                'public_discoverable' => class_exists('APIPlatform_Lifecycle_Policy') ? APIPlatform_Lifecycle_Policy::public_route_allowed(['id' => $post->ID, 'visibility' => $visibility, 'lifecycle' => $lifecycle], 'directory') : ($visibility === 'public' && $status === 'active'),
                'last_lifecycle_change' => $last_event ? $this->date_label($last_event['created_at']) : '',
                'category' => $category,
                'category_label' => $this->category_label($category),
                'auth' => $auth,
                'auth_label' => $this->auth_label($auth),
                'owner_type' => $owner['owner_type'],
                'owner_id' => $owner['owner_id'],
                'owner_label' => $owner_label,
                'owner_slug' => $owner['owner_slug'] ?? '',
                'can_manage' => class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::can($user_id, $post->ID, 'apis.edit') : true,
                'can_manage_docs' => class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::can($user_id, $post->ID, 'apis.manage_docs') : true,
                'can_view_analytics' => class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::can($user_id, $post->ID, 'analytics.view') : true,
                'can_view_logs' => class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::can($user_id, $post->ID, 'logs.view') : true,
                'docs_status' => sanitize_key($settings['docs_status'] ?? ''),
                'docs_state' => $this->documentation_state((int) $post->ID, sanitize_key($settings['docs_status'] ?? ''), $visibility),
                'updated_at' => $updated,
                'updated_label' => $this->date_label($updated),
                'created_label' => $this->date_label(get_post_time('Y-m-d H:i:s', false, $post)),
                'endpoint' => function_exists('apiplatform_public_gateway_url') ? apiplatform_public_gateway_url($post) : (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) $post->post_author), $post->post_name) : rest_url('platform/v1/api/' . $post->post_name)),
                'requests_today' => 0,
                'allow_public_tester' => !empty($settings['allow_public_tester']),
                'urls' => $this->api_urls((int) $post->ID, $portal_slug),
            ];
        }

        $today_counts = $this->requests_today_by_api(array_keys($apis));

        foreach ($apis as $id => $api) {
            $apis[$id]['requests_today'] = (int) ($today_counts[$id] ?? 0);
        }

        return array_values($apis);
    }

    private function owned_api_post($api_id, $user_id){
        $api_id = absint($api_id);
        $post = $api_id ? get_post($api_id) : null;

        if (!$post || $post->post_type !== 'user_api') {
            return null;
        }

        if (class_exists('APIPlatform_Ownership_Service')) {
            return APIPlatform_Ownership_Service::can($user_id, $api_id, 'apis.edit') ? $post : null;
        }

        if ((int) $post->post_author !== (int) $user_id && !current_user_can('manage_options')) {
            return null;
        }

        return $post;
    }

    private function permission_for_owner_action($action){
        $map = [
            'archive' => 'apis.delete',
            'restore' => 'apis.edit',
            'lifecycle' => 'apis.publish',
            'save-version' => 'apis.manage_versions',
            'save-docs' => 'apis.manage_docs',
            'publish-docs' => 'apis.manage_docs',
            'save-doc-release' => 'apis.manage_docs',
            'save-endpoint-schema' => 'apis.manage_schema',
            'save-data-models' => 'apis.manage_schema',
            'save-validation-settings' => 'apis.manage_schema',
            'save-gateway-settings' => 'apis.manage_runtime',
            'preview-openapi-import' => 'apis.manage_schema',
            'confirm-openapi-import' => 'apis.manage_schema',
            'export-openapi' => 'apis.manage_schema',
            'save-sdk-settings' => 'apis.manage_docs',
            'preview-sdk' => 'apis.manage_docs',
            'download-sdk' => 'apis.manage_docs',
            'unpublish' => 'apis.publish',
            'application-access' => 'application_access.manage',
        ];
        return $map[sanitize_key($action)] ?? 'apis.edit';
    }

    private function debug_owner_action_post($stage, array $extra = []){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $safe_keys = [
            'apiplatform_owner_action',
            'api_id',
            'apiplatform_owner_nonce',
            'apiplatform_lifecycle_target',
        ];
        $present = [];

        foreach ($safe_keys as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }

            $present[$key] = $key === 'apiplatform_owner_nonce'
                ? 'present'
                : sanitize_key(wp_unslash($_POST[$key]));
        }

        $parts = [
            'stage=' . sanitize_key($stage),
            'method=' . sanitize_key($_SERVER['REQUEST_METHOD'] ?? ''),
            'post_keys=' . implode(',', array_keys($present)),
        ];

        foreach ($present as $key => $value) {
            $parts[] = sanitize_key($key) . '=' . sanitize_key((string) $value);
        }

        foreach ($extra as $key => $value) {
            $parts[] = sanitize_key($key) . '=' . sanitize_text_field((string) $value);
        }

        error_log('[FreedomAPI publisher owner action] ' . implode(' ', $parts));
    }

    private function api_metrics($api_id, $user_id){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)) {
            return ['total' => 0, 'avg_ms' => 0, 'errors' => 0, 'error_rate' => '0.0', 'last_request' => '', 'last_error' => ''];
        }

        $where = ['api_id = %d'];
        $args = [absint($api_id)];

        if (!current_user_can('manage_options')) {
            $where[] = 'user_id = %d';
            $args[] = absint($user_id);
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, AVG(response_time) AS avg_time, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors FROM `{$table}` WHERE " . implode(' AND ', $where), $args), ARRAY_A) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);

        $last_error = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM `{$table}` WHERE " . implode(' AND ', array_merge($where, ['status >= 400'])) . " ORDER BY created_at DESC LIMIT 1", $args));
        $last_request = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM `{$table}` WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 1", $args));

        return [
            'total' => $total,
            'avg_ms' => (int) round(((float) ($row['avg_time'] ?? 0)) * 1000),
            'errors' => $errors,
            'error_rate' => $total > 0 ? number_format(($errors / $total) * 100, 1) : '0.0',
            'last_request' => $last_request ? $this->date_label($last_request) : '',
            'last_error' => $last_error ? $this->date_label($last_error) : '',
        ];
    }

    private function archive_api($api){
        update_post_meta($api->ID, 'apiplatform_status', 'archived');
        update_post_meta($api->ID, 'apiplatform_archived_at', current_time('mysql'));
        $this->set_portal_visibility($api->ID, 'private');
    }

    private function unpublish_api($api){
        $this->set_portal_visibility($api->ID, 'private');
    }

    private function transition_lifecycle($api, $target, $user_id){
        if (!class_exists('APIPlatform_Lifecycle_Policy')) {
            return 'Lifecycle policy is unavailable.';
        }

        $from = APIPlatform_Lifecycle_Policy::get_state($api->ID);
        $target = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($target)
            : sanitize_key($target);
        $allowed = APIPlatform_Lifecycle_Policy::allowed_transitions();
        $allowed_labels = [];
        foreach ($allowed[$from] ?? [] as $allowed_state) {
            $allowed_labels[] = APIPlatform_Lifecycle_Policy::label($allowed_state);
        }
        $args = [
            'deprecation_notice' => sanitize_textarea_field(wp_unslash($_POST['apiplatform_deprecation_notice'] ?? '')),
            'sunset_date' => $this->clean_date($_POST['apiplatform_sunset_date'] ?? ''),
            'replacement_version' => sanitize_text_field(wp_unslash($_POST['apiplatform_replacement_version'] ?? '')),
            'migration_url' => esc_url_raw(wp_unslash($_POST['apiplatform_migration_url'] ?? '')),
            'migration_message' => sanitize_textarea_field(wp_unslash($_POST['apiplatform_migration_message'] ?? '')),
        ];
        $result = APIPlatform_Lifecycle_Policy::transition($api->ID, $target, $user_id, $args);
        $stored = APIPlatform_Lifecycle_Policy::get_state($api->ID);

        $this->debug_owner_action_post('lifecycle_result', [
            'api_id' => $api->ID,
            'from' => $from,
            'target' => $target,
            'stored_lifecycle' => $stored,
            'transition_ok' => !empty($result['ok']) ? 'yes' : 'no',
            'allowed_next' => implode(',', $allowed[$from] ?? []),
        ]);

        if (empty($result['ok'])) {
            return 'Error: Lifecycle was not changed. Current lifecycle: ' . APIPlatform_Lifecycle_Policy::label($from) .
                '. Requested lifecycle: ' . APIPlatform_Lifecycle_Policy::label($target) .
                '. Reason: ' . ($result['message'] ?? 'Lifecycle could not be changed.') .
                ' Allowed next states: ' . ($allowed_labels ? implode(', ', $allowed_labels) : 'none') . '.';
        }

        if ($stored !== $target) {
            return 'Error: Lifecycle transition was accepted but storage still reads ' . APIPlatform_Lifecycle_Policy::label($stored) .
                '. Requested lifecycle: ' . APIPlatform_Lifecycle_Policy::label($target) . '.';
        }

        return 'Lifecycle updated: ' . APIPlatform_Lifecycle_Policy::label($from) . ' to ' . APIPlatform_Lifecycle_Policy::label($stored) . '. ' . ($result['message'] ?? '');
    }

    private function record_organization_api_event($api, $user_id, $action, $message){
        if (!class_exists('APIPlatform_Ownership_Service') || !class_exists('APIPlatform_Organization_Service')) {
            return;
        }
        if (strpos((string) $message, 'Error:') === 0) {
            return;
        }

        $owner = APIPlatform_Ownership_Service::owner($api->ID);
        if (($owner['owner_type'] ?? '') !== 'organization' || empty($owner['owner_id'])) {
            return;
        }

        $event_map = [
            'archive' => 'api_archived',
            'restore' => 'api_restored',
            'lifecycle' => 'api_lifecycle_changed',
            'save-version' => 'api_version_updated',
            'save-docs' => 'api_documentation_updated',
            'publish-docs' => 'api_documentation_published',
            'save-doc-release' => 'api_changelog_updated',
            'save-endpoint-schema' => 'api_schema_updated',
            'save-data-models' => 'api_models_updated',
            'save-validation-settings' => 'api_validation_updated',
            'save-gateway-settings' => 'api_gateway_updated',
            'confirm-openapi-import' => 'api_schema_imported',
            'save-sdk-settings' => 'api_sdk_settings_updated',
            'preview-sdk' => 'api_sdk_previewed',
            'download-sdk' => 'api_sdk_generated',
            'unpublish' => 'api_unpublished',
            'application-access' => 'api_application_access_updated',
        ];
        $event_type = $event_map[sanitize_key($action)] ?? '';
        if ($event_type === '') {
            return;
        }

        APIPlatform_Organization_Service::record_event(
            $owner['owner_id'],
            $user_id,
            $event_type,
            'api',
            $api->ID,
            get_the_title($api) . ': ' . $this->event_message_label($event_type),
            [
                'api_id' => absint($api->ID),
                'api_name' => get_the_title($api),
                'action' => sanitize_key($action),
            ]
        );
    }

    private function event_message_label($event_type){
        $labels = [
            'api_archived' => 'API archived',
            'api_restored' => 'API restored',
            'api_lifecycle_changed' => 'Lifecycle changed',
            'api_version_updated' => 'Version metadata updated',
            'api_documentation_updated' => 'Documentation updated',
            'api_documentation_published' => 'Documentation published',
            'api_changelog_updated' => 'Changelog updated',
            'api_schema_updated' => 'Endpoint schema updated',
            'api_models_updated' => 'Data models updated',
            'api_validation_updated' => 'Validation settings updated',
            'api_gateway_updated' => 'Gateway settings updated',
            'api_schema_imported' => 'OpenAPI schema imported',
            'api_sdk_settings_updated' => 'SDK settings updated',
            'api_sdk_previewed' => 'SDK preview generated',
            'api_sdk_generated' => 'SDK generated',
            'api_unpublished' => 'API unpublished',
            'api_application_access_updated' => 'Application access updated',
        ];
        return $labels[sanitize_key($event_type)] ?? ucwords(str_replace('_', ' ', sanitize_key($event_type)));
    }

    private function set_portal_visibility($api_id, $visibility){
        if (!class_exists('APIPlatform_Developer_Portal_Query')) {
            return;
        }

        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($visibility)
            : sanitize_key($visibility);
        $settings = APIPlatform_Developer_Portal_Query::get_settings($api_id);
        $settings['visibility'] = $visibility;
        $settings['updated_at'] = current_time('mysql');
        update_post_meta($api_id, APIPlatform_Developer_Portal_Query::meta_keys()['visibility'], $visibility);
        update_post_meta($api_id, APIPlatform_Developer_Portal_Query::meta_keys()['updated_at'], $settings['updated_at']);
        APIPlatform_Developer_Portal_Query::sync_registry($api_id, $settings);
    }

    private function save_version_metadata($api){
        $version_status = sanitize_key(wp_unslash($_POST['apiplatform_portal_version_status'] ?? 'current'));

        if (!in_array($version_status, ['current', 'beta', 'deprecated'], true)) {
            $version_status = 'current';
        }

        $deprecation_notice = sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_deprecation_notice'] ?? ''));
        if ($version_status === 'deprecated' && class_exists('APIPlatform_Lifecycle_Policy')) {
            $current_lifecycle = APIPlatform_Lifecycle_Policy::get_state($api->ID);

            if (!in_array($current_lifecycle, ['public', 'deprecated'], true)) {
                return 'Version metadata was not saved: move this API to Public before marking it Deprecated.';
            }

            if (trim($deprecation_notice) === '') {
                return 'Version metadata was not saved: add a deprecation notice before marking this API Deprecated.';
            }
        }

        $fields = [
            'apiplatform_portal_version_label' => sanitize_text_field(wp_unslash($_POST['apiplatform_portal_version_label'] ?? 'v1')),
            'apiplatform_portal_version_status' => $version_status,
            'apiplatform_portal_deprecation_notice' => $deprecation_notice,
            'apiplatform_portal_migration_message' => sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_migration_message'] ?? '')),
            'apiplatform_portal_migration_url' => esc_url_raw(wp_unslash($_POST['apiplatform_portal_migration_url'] ?? '')),
            'apiplatform_portal_sunset_date' => $this->clean_date($_POST['apiplatform_portal_sunset_date'] ?? ''),
            'apiplatform_portal_replacement_version' => sanitize_text_field(wp_unslash($_POST['apiplatform_portal_replacement_version'] ?? '')),
        ];

        foreach ($fields as $key => $value) {
            update_post_meta($api->ID, $key, $value);
        }

        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $settings = APIPlatform_Developer_Portal_Query::get_settings($api->ID);
            $settings['updated_at'] = current_time('mysql');
            APIPlatform_Developer_Portal_Query::sync_registry($api->ID, $settings);
        }

        if ($version_status === 'deprecated' && class_exists('APIPlatform_Lifecycle_Policy')) {
            $notice = trim((string) $fields['apiplatform_portal_deprecation_notice']);
            if ($notice !== '') {
                APIPlatform_Lifecycle_Policy::transition($api->ID, 'deprecated', get_current_user_id(), [
                    'deprecation_notice' => $fields['apiplatform_portal_deprecation_notice'],
                    'sunset_date' => $fields['apiplatform_portal_sunset_date'],
                    'replacement_version' => $fields['apiplatform_portal_replacement_version'],
                    'migration_url' => $fields['apiplatform_portal_migration_url'],
                    'migration_message' => $fields['apiplatform_portal_migration_message'],
                ]);
            }
        }

        return 'Version metadata saved.';
    }

    private function save_documentation_metadata($api){
        $json_fields = [
            'apiplatform_docs_parameters' => 'Query Parameters JSON',
            'apiplatform_docs_headers' => 'Headers JSON',
            'apiplatform_docs_request_body' => 'Request Body Example JSON',
            'apiplatform_docs_success_response' => 'Success Response Example JSON',
            'apiplatform_docs_errors' => 'Documented Errors JSON',
        ];

        foreach ($json_fields as $field => $label) {
            $value = trim((string) wp_unslash($_POST[$field] ?? ''));

            if ($value !== '') {
                json_decode($value, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    wp_die($label . ' is invalid JSON: ' . esc_html(json_last_error_msg()));
                }
            }
        }

        $data = [
            'overview' => wp_unslash($_POST['apiplatform_docs_overview'] ?? ''),
            'authentication' => wp_unslash($_POST['apiplatform_docs_authentication'] ?? ''),
            'endpoint_description' => wp_unslash($_POST['apiplatform_docs_endpoint_description'] ?? ''),
            'methods' => $_POST['apiplatform_docs_methods'] ?? 'GET',
            'parameters_json' => wp_unslash($_POST['apiplatform_docs_parameters'] ?? ''),
            'headers_json' => wp_unslash($_POST['apiplatform_docs_headers'] ?? ''),
            'request_body' => wp_unslash($_POST['apiplatform_docs_request_body'] ?? ''),
            'success_response' => wp_unslash($_POST['apiplatform_docs_success_response'] ?? ''),
            'errors_json' => wp_unslash($_POST['apiplatform_docs_errors'] ?? ''),
            'rate_limit' => wp_unslash($_POST['apiplatform_docs_rate_limit'] ?? ''),
            'sdk_examples' => wp_unslash($_POST['apiplatform_docs_sdk_examples'] ?? ''),
            'faq' => wp_unslash($_POST['apiplatform_docs_faq'] ?? ''),
            'migration_guide' => wp_unslash($_POST['apiplatform_docs_migration_guide'] ?? ''),
        ];

        if (class_exists('APIPlatform_Documentation_Workspace')) {
            $result = APIPlatform_Documentation_Workspace::save_draft($api->ID, get_current_user_id(), $data);
            if (is_wp_error($result)) {
                return 'Error: ' . $result->get_error_message();
            }
        }

        return 'Documentation draft saved.';
    }

    private function publish_documentation($api, $user_id){
        if (!class_exists('APIPlatform_Documentation_Workspace')) {
            return 'Documentation publishing is unavailable.';
        }

        $result = APIPlatform_Documentation_Workspace::publish($api, $user_id);
        return $result['message'] ?? 'Documentation publish completed.';
    }

    private function save_documentation_release($api, $user_id){
        if (!class_exists('APIPlatform_Documentation_Workspace')) {
            return 'Changelog service is unavailable.';
        }

        return APIPlatform_Documentation_Workspace::save_release($api->ID, $user_id, [
            'version_label' => wp_unslash($_POST['apiplatform_release_version_label'] ?? ''),
            'release_title' => wp_unslash($_POST['apiplatform_release_title'] ?? ''),
            'release_date' => wp_unslash($_POST['apiplatform_release_date'] ?? ''),
            'release_type' => wp_unslash($_POST['apiplatform_release_type'] ?? ''),
            'breaking_change' => !empty($_POST['apiplatform_release_breaking_change']),
            'summary' => wp_unslash($_POST['apiplatform_release_summary'] ?? ''),
            'entries' => wp_unslash($_POST['apiplatform_release_entries'] ?? ''),
            'migration_notes' => wp_unslash($_POST['apiplatform_release_migration_notes'] ?? ''),
        ]);
    }

    private function save_endpoint_schema($api, $user_id){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Endpoint schema service is unavailable.';
        }

        $raw = trim((string) wp_unslash($_POST['apiplatform_endpoint_schema_json'] ?? ''));
        $decoded = json_decode($raw, true);

        if ($raw === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return 'Error: Endpoint Schema JSON is invalid: ' . json_last_error_msg();
        }

        $endpoints = isset($decoded['endpoints']) && is_array($decoded['endpoints']) ? $decoded['endpoints'] : $decoded;
        if (!$endpoints) {
            return 'Error: Endpoint Schema JSON must contain at least one endpoint. Empty schemas require a future explicit reset action.';
        }

        if (!array_filter((array) $endpoints, 'is_array')) {
            return 'Error: Endpoint Schema JSON must be an array of endpoint objects or an object with an endpoints array.';
        }

        $schema = [
            'version' => sanitize_text_field(wp_unslash($_POST['apiplatform_schema_version_label'] ?? APIPlatform_Endpoint_Schema_Service::version_label($api->ID))),
            'endpoints' => $endpoints,
        ];
        $result = APIPlatform_Endpoint_Schema_Service::save_schema($api->ID, $user_id, $schema);

        return $result['message'] ?? 'Endpoint schema saved.';
    }

    private function save_data_models($api, $user_id){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Endpoint schema service is unavailable.';
        }

        $raw = trim((string) wp_unslash($_POST['apiplatform_data_models_json'] ?? ''));
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if ($raw !== '' && (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded))) {
            return 'Error: Data Models JSON is invalid: ' . json_last_error_msg();
        }

        $models = isset($decoded['models']) && is_array($decoded['models']) ? $decoded['models'] : ($decoded ?: []);
        return APIPlatform_Endpoint_Schema_Service::save_models(
            $api->ID,
            $user_id,
            sanitize_text_field(wp_unslash($_POST['apiplatform_schema_version_label'] ?? APIPlatform_Endpoint_Schema_Service::version_label($api->ID))),
            $models
        );
    }

    private function save_validation_settings($api, $user_id){
        if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Endpoint schema service is unavailable.';
        }

        return APIPlatform_Endpoint_Schema_Service::save_validation_settings(
            $api->ID,
            $user_id,
            wp_unslash($_POST['apiplatform_schema_validation_mode'] ?? 'off'),
            !empty($_POST['apiplatform_schema_reject_unknown'])
        );
    }

    private function preview_openapi_import($api, $user_id){
        if (!class_exists('APIPlatform_OpenAPI_Service') || !class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Error: OpenAPI import service is unavailable.';
        }

        $raw = trim((string) wp_unslash($_POST['apiplatform_openapi_source'] ?? ''));
        $source_type = sanitize_key(wp_unslash($_POST['apiplatform_openapi_source_type'] ?? 'json'));
        $remote_url = esc_url_raw(wp_unslash($_POST['apiplatform_openapi_url'] ?? ''));

        if ($remote_url !== '') {
            return 'Error: OpenAPI URL import is reserved for a future remote-fetch workflow. Upload a file or paste JSON/YAML for this import.';
        }

        if (!empty($_FILES['apiplatform_openapi_file']['tmp_name']) && is_uploaded_file($_FILES['apiplatform_openapi_file']['tmp_name'])) {
            $size = absint($_FILES['apiplatform_openapi_file']['size'] ?? 0);
            if ($size > 1048576) {
                return 'Error: OpenAPI file is too large. Limit imports to 1 MB.';
            }
            $file_raw = file_get_contents($_FILES['apiplatform_openapi_file']['tmp_name']);
            if ($file_raw === false) {
                return 'Error: OpenAPI upload could not be read.';
            }
            if (is_string($file_raw) && trim($file_raw) !== '') {
                $raw = $file_raw;
                $name = sanitize_file_name($_FILES['apiplatform_openapi_file']['name'] ?? '');
                if (preg_match('/\.ya?ml$/i', $name)) {
                    $source_type = 'yaml';
                } elseif (preg_match('/\.json$/i', $name)) {
                    $source_type = 'json';
                }
            }
        }

        $parsed = APIPlatform_OpenAPI_Service::parse($raw, $source_type);
        if (empty($parsed['ok'])) {
            if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
                APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'validation_failed', 'OpenAPI import validation failed.');
            }
            return 'Error: ' . ($parsed['message'] ?? 'OpenAPI could not be parsed.');
        }

        $version = sanitize_text_field(wp_unslash($_POST['apiplatform_openapi_version_label'] ?? APIPlatform_Endpoint_Schema_Service::version_label($api->ID)));
        $mode = sanitize_key(wp_unslash($_POST['apiplatform_openapi_import_mode'] ?? 'replace'));
        $publish_mode = sanitize_key(wp_unslash($_POST['apiplatform_openapi_publish_mode'] ?? 'draft'));
        if ($mode !== 'new-version') {
            $version = APIPlatform_Endpoint_Schema_Service::version_label($api->ID);
        }
        if (!in_array($publish_mode, ['draft', 'publish'], true)) {
            $publish_mode = 'draft';
        }

        $preview = APIPlatform_OpenAPI_Service::preview($api->ID, $parsed['spec'], $version);
        if (empty($preview['ok'])) {
            APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'import_failed', 'OpenAPI import preview failed.');
            return 'Error: ' . ($preview['message'] ?? 'OpenAPI import preview failed.');
        }

        $preview['options'] = ['mode' => $mode, 'version' => $version, 'publish_mode' => $publish_mode];
        set_transient($this->openapi_preview_key($user_id, $api->ID), $preview, 10 * MINUTE_IN_SECONDS);

        return 'OpenAPI import preview ready. Review and confirm before saving.';
    }

    private function confirm_openapi_import($api, $user_id){
        if (!class_exists('APIPlatform_OpenAPI_Service') || !class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Error: OpenAPI import service is unavailable.';
        }

        $preview = get_transient($this->openapi_preview_key($user_id, $api->ID));
        if (!is_array($preview)) {
            return 'Error: OpenAPI import preview expired. Preview the import again.';
        }

        $result = APIPlatform_OpenAPI_Service::import($api->ID, $user_id, $preview, $preview['options'] ?? []);
        delete_transient($this->openapi_preview_key($user_id, $api->ID));

        if (empty($result['ok'])) {
            return 'Error: ' . ($result['message'] ?? 'OpenAPI import failed.');
        }

        $message = $result['message'] ?? 'OpenAPI imported.';
        if (($preview['options']['publish_mode'] ?? 'draft') === 'publish') {
            $publish = $this->publish_documentation($api, $user_id);
            $message .= ' ' . $publish;
        }

        return $message;
    }

    private function export_openapi($api, $user_id){
        if (!class_exists('APIPlatform_OpenAPI_Service') || !class_exists('APIPlatform_Endpoint_Schema_Service')) {
            return 'Error: OpenAPI export service is unavailable.';
        }

        $format = sanitize_key(wp_unslash($_POST['apiplatform_openapi_export_format'] ?? 'json'));
        $result = APIPlatform_OpenAPI_Service::export($api->ID, $format);

        if (empty($result['ok'])) {
            APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'validation_failed', 'OpenAPI export validation failed.');
            return 'Error: ' . ($result['message'] ?? 'OpenAPI export failed validation.');
        }

        APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'openapi_exported', 'OpenAPI export generated.');
        set_transient($this->openapi_export_key($user_id, $api->ID), [
            'format' => $result['format'],
            'content' => $result['content'],
        ], 5 * MINUTE_IN_SECONDS);

        return 'OpenAPI export generated.';
    }

    private function save_sdk_settings($api, $user_id){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return 'Error: SDK generation service is unavailable.';
        }

        return APIPlatform_SDK_Generator_Service::save_settings($api->ID, $user_id, [
            'enabled' => !empty($_POST['apiplatform_sdk_enabled']),
            'allowed_languages' => array_map('sanitize_key', (array) ($_POST['apiplatform_sdk_allowed_languages'] ?? [])),
            'package_name' => wp_unslash($_POST['apiplatform_sdk_package_name'] ?? ''),
            'namespace' => wp_unslash($_POST['apiplatform_sdk_namespace'] ?? ''),
            'client_class' => wp_unslash($_POST['apiplatform_sdk_client_class'] ?? ''),
            'include_models' => !empty($_POST['apiplatform_sdk_include_models']),
            'include_examples' => !empty($_POST['apiplatform_sdk_include_examples']),
        ]);
    }

    private function preview_sdk_generation($api, $user_id){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return 'Error: SDK generation service is unavailable.';
        }

        $language = APIPlatform_SDK_Generator_Service::normalize_language(wp_unslash($_POST['sdk_language'] ?? ''));
        if ($language === '') {
            return 'Error: Select a supported SDK language.';
        }
        $type = sanitize_key(wp_unslash($_POST['sdk_generation_type'] ?? 'client'));
        $version = sanitize_text_field(wp_unslash($_POST['apiplatform_sdk_version'] ?? ''));
        $this->owner_redirect_args = ['sdk_language' => $language];
        $options = $this->sdk_generation_options($version);
        $preview = APIPlatform_SDK_Generator_Service::preview($api->ID, $language, $options);

        if (empty($preview['ok'])) {
            if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
                APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'sdk_generation_failed', 'SDK preview failed.');
            }
            return 'Error: ' . ($preview['message'] ?? 'SDK preview failed.');
        }

        $preview['generation_type'] = $type === 'snippet' ? 'snippet' : 'client';
        if ($preview['generation_type'] === 'snippet') {
            $preview['files'] = ['integration-snippet.' . ($language === 'python' ? 'py' : ($language === 'php' ? 'php' : ($language === 'csharp' ? 'cs' : 'js')))];
        }

        $token = wp_generate_password(20, false, false);
        $display_preview = [
            'user_id' => absint($user_id),
            'api_id' => absint($api->ID),
            'language' => $preview['language'],
            'label' => $preview['label'],
            'version' => $preview['ir']['version'] ?? $version,
            'package_name' => $preview['settings']['package_name'] ?? '',
            'client_class' => $preview['settings']['client_class'] ?? '',
            'endpoint_count' => count($preview['ir']['endpoints'] ?? []),
            'model_count' => count($preview['ir']['models'] ?? []),
            'files' => array_values($preview['files'] ?? []),
            'methods' => array_values($preview['methods'] ?? []),
            'models' => array_values($preview['models'] ?? []),
            'warnings' => array_values($preview['warnings'] ?? []),
            'unsupported' => array_values($preview['unsupported'] ?? []),
            'created_at' => current_time('mysql'),
        ];
        set_transient($this->sdk_preview_key($user_id, $api->ID, $token), $display_preview, 10 * MINUTE_IN_SECONDS);
        $this->owner_redirect_args = [
            'sdk_preview' => $token,
            'sdk_language' => $preview['language'],
        ];
        if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
            APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'sdk_preview_generated', 'SDK preview generated for ' . ($preview['label'] ?? $language) . '.');
        }

        return 'SDK preview generated.';
    }

    private function download_sdk_package($api, $user_id){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return 'Error: SDK generation service is unavailable.';
        }

        $language = APIPlatform_SDK_Generator_Service::normalize_language(wp_unslash($_POST['sdk_language'] ?? ''));
        if ($language === '') {
            return 'Error: Select a supported SDK language.';
        }
        $version = sanitize_text_field(wp_unslash($_POST['apiplatform_sdk_version'] ?? ''));
        $this->owner_redirect_args = ['sdk_language' => $language];
        $archive = APIPlatform_SDK_Generator_Service::archive($api->ID, $user_id, $language, $this->sdk_generation_options($version));

        if (empty($archive['ok'])) {
            if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
                APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'sdk_generation_failed', 'SDK download failed.');
            }
            return 'Error: ' . ($archive['message'] ?? 'SDK download failed.');
        }

        if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
            APIPlatform_Endpoint_Schema_Service::record_event($api->ID, $user_id, 'sdk_downloaded', 'SDK downloaded: ' . $archive['filename'] . '.');
        }

        nocache_headers();
        header('Content-Type: ' . $archive['content_type']);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($archive['filename']) . '"');
        header('Content-Length: ' . filesize($archive['path']));
        readfile($archive['path']);
        APIPlatform_SDK_Generator_Service::delete_dir($archive['dir']);
        exit;
    }

    private function sdk_generation_options($version){
        return [
            'version' => sanitize_text_field($version),
            'package_name' => wp_unslash($_POST['sdk_package_name'] ?? ''),
            'namespace' => wp_unslash($_POST['sdk_namespace'] ?? ''),
            'client_class' => wp_unslash($_POST['sdk_client_class'] ?? ''),
            'include_models' => !empty($_POST['sdk_include_models']),
            'include_examples' => !empty($_POST['sdk_include_examples']),
        ];
    }

    private function documentation_meta($api_id){
        if (class_exists('APIPlatform_Documentation_Workspace')) {
            return APIPlatform_Documentation_Workspace::draft_meta($api_id);
        }

        $parameters = get_post_meta($api_id, 'api_parameters', true);
        $headers = get_post_meta($api_id, 'apiplatform_docs_headers', true);
        $errors = get_post_meta($api_id, 'apiplatform_docs_errors', true);

        return [
            'overview' => get_post_meta($api_id, 'apiplatform_docs_overview', true),
            'authentication' => get_post_meta($api_id, 'apiplatform_docs_authentication', true),
            'endpoint_description' => get_post_meta($api_id, 'apiplatform_docs_endpoint_description', true),
            'methods' => $this->sanitize_methods(get_post_meta($api_id, 'apiplatform_docs_methods', true) ?: 'GET'),
            'parameters_json' => wp_json_encode(is_array($parameters) ? $parameters : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'headers_json' => wp_json_encode(is_array($headers) ? $headers : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'request_body' => get_post_meta($api_id, 'api_request_body_example', true) ?: "{}",
            'success_response' => get_post_meta($api_id, 'apiplatform_docs_success_response', true) ?: (get_post_meta($api_id, 'api_json', true) ?: "{}"),
            'errors_json' => wp_json_encode(is_array($errors) ? $errors : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'rate_limit' => get_post_meta($api_id, 'apiplatform_docs_rate_limit', true),
            'sdk_examples' => get_post_meta($api_id, 'apiplatform_docs_sdk_examples', true),
            'faq' => get_post_meta($api_id, 'apiplatform_docs_faq', true),
            'migration_guide' => get_post_meta($api_id, 'apiplatform_docs_migration_guide', true),
        ];
    }

    private function version_meta($api_id){
        return [
            'version_label' => get_post_meta($api_id, 'apiplatform_portal_version_label', true) ?: 'v1',
            'version_status' => get_post_meta($api_id, 'apiplatform_portal_version_status', true) ?: 'current',
            'deprecation_notice' => get_post_meta($api_id, 'apiplatform_portal_deprecation_notice', true),
            'migration_message' => get_post_meta($api_id, 'apiplatform_portal_migration_message', true),
            'migration_url' => get_post_meta($api_id, 'apiplatform_portal_migration_url', true),
            'sunset_date' => get_post_meta($api_id, 'apiplatform_portal_sunset_date', true),
            'replacement_version' => get_post_meta($api_id, 'apiplatform_portal_replacement_version', true),
        ];
    }

    private function documentation_state($api_id, $docs_status, $visibility){
        $stored_state = sanitize_key(get_post_meta($api_id, 'apiplatform_docs_state', true));
        $published_at = get_post_meta($api_id, 'apiplatform_docs_published_at', true);

        if ($stored_state === 'published') {
            return ['state' => 'Published', 'message' => 'Public documentation snapshot is published' . ($published_at ? ' from ' . $this->date_label($published_at) : '') . '.'];
        }

        if ($stored_state === 'outdated') {
            return ['state' => 'Outdated', 'message' => 'A published snapshot exists, but the current draft has unpublished changes.'];
        }

        if ($stored_state === 'archived') {
            return ['state' => 'Archived', 'message' => 'Documentation is archived and not shown publicly.'];
        }

        $docs = $this->documentation_meta($api_id);
        $has_overview = trim((string) $docs['overview']) !== '';
        $has_endpoint = trim((string) $docs['endpoint_description']) !== '';
        $has_response = trim((string) $docs['success_response']) !== '' && trim((string) $docs['success_response']) !== '{}';

        if ($docs_status === 'ready' && $visibility !== 'private') {
            return ['state' => 'Published', 'message' => 'Public documentation is enabled and this API is visible by public or unlisted route.'];
        }

        if ($docs_status === 'ready') {
            return ['state' => 'Ready', 'message' => 'Documentation is marked ready, but the API is private.'];
        }

        if ($has_overview || $has_endpoint || $has_response) {
            return ['state' => 'Incomplete', 'message' => 'Documentation metadata exists but is not marked ready.'];
        }

        return ['state' => $visibility === 'private' ? 'Private' : 'Draft', 'message' => 'Write documentation before publishing.'];
    }

    private function readiness_items(array $api){
        $settings = class_exists('APIPlatform_Developer_Portal_Query')
            ? APIPlatform_Developer_Portal_Query::get_settings($api['id'])
            : [];
        $docs_ready = in_array($api['docs_state']['state'], ['Ready', 'Published'], true);
        $public_metadata = trim((string) ($settings['title'] ?? '')) !== '' &&
            trim((string) ($settings['summary'] ?? '')) !== '' &&
            trim((string) ($settings['description'] ?? '')) !== '';

        return [
            ['label' => 'Endpoint configured', 'ready' => $api['endpoint'] !== '', 'message' => $api['endpoint'] !== '' ? 'Runtime endpoint exists.' : 'Create a runtime endpoint.'],
            ['label' => 'Authentication configured', 'ready' => $api['auth_label'] !== '', 'message' => $api['auth_label'] !== '' ? $api['auth_label'] : 'Set authentication metadata.'],
            ['label' => 'Documentation written', 'ready' => $docs_ready, 'message' => $docs_ready ? $api['docs_state']['state'] : 'Write documentation before publishing.'],
            ['label' => 'Summary written', 'ready' => trim((string) $api['summary']) !== '', 'message' => trim((string) $api['summary']) !== '' ? 'Summary is present.' : 'Add a public summary.'],
            ['label' => 'Category assigned', 'ready' => $api['category'] !== '', 'message' => $api['category'] !== '' ? $api['category_label'] : 'Choose a public category.'],
            ['label' => 'Public metadata complete', 'ready' => $public_metadata, 'message' => $public_metadata ? 'Title, summary, and description exist.' : 'Complete title, summary, and description.'],
            ['label' => 'Version assigned', 'ready' => trim((string) $api['version']) !== '', 'message' => trim((string) $api['version']) !== '' ? $api['version'] : 'Add a version label.'],
            ['label' => 'Active', 'ready' => $api['status'] === 'active', 'message' => $api['status'] === 'active' ? 'Runtime is active.' : 'Enable the API before publishing.'],
        ];
    }

    private function recent_requests_for_api($api_id, $limit, $errors_only){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)) {
            return [];
        }

        $where = ['api_id = %d'];
        $args = [absint($api_id)];

        if (!current_user_can('manage_options')) {
            $where[] = 'user_id = %d';
            $args[] = get_current_user_id();
        }

        if ($errors_only) {
            $where[] = 'status >= 400';
        }

        $args[] = absint($limit);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint, method, status, response_time, created_at FROM `{$table}` WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT %d",
            $args
        ), ARRAY_A);
    }

    private function request_rows(array $rows){
        $html = '<div class="apiplatform-publisher-request-list">';

        foreach ($rows as $row) {
            $latency = (int) round(((float) ($row['response_time'] ?? 0)) * 1000);
            $status = (int) ($row['status'] ?? 0);
            $html .= '<div class="apiplatform-publisher-request-row"><code>' . esc_html(strtoupper((string) ($row['method'] ?? 'GET'))) . '</code><strong class="' . esc_attr($status >= 400 ? 'status-fail' : 'status-ok') . '">' . esc_html((string) $status) . '</strong><span>' . esc_html($latency . ' ms') . '</span><time>' . esc_html($this->date_label($row['created_at'] ?? '')) . '</time></div>';
        }

        return $html . '</div>';
    }

    private function timeline_events(array $api){
        $events = [
            ['title' => 'API Created', 'time' => $api['created_label']],
            ['title' => 'API Updated', 'time' => $api['updated_label']],
        ];

        if ($api['docs_state']['state'] !== 'Draft' && $api['docs_state']['state'] !== 'Private') {
            $events[] = ['title' => 'Documentation Updated', 'time' => $api['updated_label']];
        }

        if (class_exists('APIPlatform_Lifecycle_Policy')) {
            foreach (APIPlatform_Lifecycle_Policy::recent_events($api['id'], 6) as $event) {
                $events[] = [
                    'title' => 'Lifecycle: ' . APIPlatform_Lifecycle_Policy::label($event['previous_state']) . ' to ' . APIPlatform_Lifecycle_Policy::label($event['new_state']),
                    'time' => $this->date_label($event['created_at']),
                ];
            }
        }

        if ($api['visibility'] === 'public') {
            $events[] = ['title' => 'Published', 'time' => $api['updated_label']];
        } elseif ($api['visibility'] === 'unlisted') {
            $events[] = ['title' => 'Unlisted Access Enabled', 'time' => $api['updated_label']];
        }

        if ($api['status'] === 'archived') {
            $archived = get_post_meta($api['id'], 'apiplatform_archived_at', true);
            $events[] = ['title' => 'Archived', 'time' => $archived ? $this->date_label($archived) : 'Recently'];
        } elseif ($api['status'] === 'inactive') {
            $events[] = ['title' => 'Disabled', 'time' => $api['updated_label']];
        } else {
            $events[] = ['title' => 'Enabled', 'time' => $api['updated_label']];
        }

        if ($api['last_request']) {
            $events[] = ['title' => 'Recent Request', 'time' => $api['last_request']];
        }

        if ($api['last_error']) {
            $events[] = ['title' => 'Recent Error', 'time' => $api['last_error']];
        }

        $keys = $this->key_summaries($api['id']);
        if ($keys) {
            $events[] = ['title' => 'Key Generated', 'time' => $keys[0]['created']];
        }

        return $events;
    }

    private function key_summaries($api_id){
        $keys = get_post_meta($api_id, 'api_keys', true);
        $keys = is_array($keys) ? $keys : [];
        $items = [];

        foreach ($keys as $key) {
            $items[] = [
                'label' => is_array($key) ? (string) ($key['label'] ?? 'API Key') : 'API Key',
                'masked' => function_exists('apiplatform_key_entry_display') ? apiplatform_key_entry_display($key) : 'Masked key unavailable',
                'created' => is_array($key) && !empty($key['created']) ? gmdate('Y-m-d H:i', (int) $key['created']) : 'Not captured',
                'last_used' => is_array($key) && !empty($key['last_used']) ? esc_html($key['last_used']) : 'Not captured',
                'status' => !is_array($key) || !isset($key['enabled']) || $key['enabled'] ? 'Active' : 'Disabled',
            ];
        }

        usort($items, function($a, $b){ return strcmp($b['created'], $a['created']); });

        return $items;
    }

    private function definition_list(array $items){
        $html = '<dl class="apiplatform-publisher-meta">';

        foreach ($items as $item) {
            $html .= '<div><dt>' . esc_html($item[0]) . '</dt><dd>' . esc_html((string) $item[1]) . '</dd></div>';
        }

        return $html . '</dl>';
    }

    private function copy_endpoint_button(array $api){
        return APIPlatform_Renderer::component('button', [
            'type' => 'secondary',
            'label' => 'Copy Endpoint',
            'html_type' => 'button',
            'class' => 'apiplatform-copy-value',
            'data_attrs' => [
                'copy-value' => $api['endpoint'],
                'copy-success' => 'Copied!',
            ],
        ]);
    }

    private function regenerate_key_form(array $api){
        return '<form method="post" class="apiplatform-publisher-inline-form">' .
            '<input type="hidden" name="apiplatform_edit_api_action" value="regenerate">' .
            '<input type="hidden" name="apiplatform_regenerate_key_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_regenerate_key_' . absint($api['id']))) . '">' .
            '<button class="apiplatform-button apiplatform-button-secondary" type="submit">Regenerate Key</button></form>';
    }

    private function api_urls($api_id, $portal_slug){
        return [
            'manage' => $this->api_hub_url($api_id),
            'edit' => $this->page_url('edit-api', ['api_id' => $api_id]),
            'schema' => $this->api_hub_url($api_id, ['section' => 'endpoint-schema']),
            'documentation' => $this->api_hub_url($api_id, ['section' => 'documentation']),
            'private_documentation' => $this->page_url('api-documentation', ['api_id' => $api_id]),
            'analytics' => $this->page_url('analytics', ['api_id' => $api_id]),
            'logs' => $this->page_url('request-history', ['api_id' => $api_id]),
            'tester' => $this->page_url('test-api', ['api_id' => $api_id]),
            'publish' => $this->api_hub_url($api_id, ['section' => 'publishing']),
            'versions' => $this->api_hub_url($api_id, ['section' => 'versions']),
            'settings' => $this->api_hub_url($api_id, ['section' => 'settings']),
            'public' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis/' . sanitize_title($portal_slug)) : home_url('/developers/apis/' . sanitize_title($portal_slug)),
            'public_docs' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis/' . sanitize_title($portal_slug) . '/docs') : home_url('/developers/apis/' . sanitize_title($portal_slug) . '/docs'),
        ];
    }

    private function page_url($page, array $args = []){
        $page = sanitize_key($page);
        $base = current_user_can('manage_options')
            ? add_query_arg('apipage', $page, class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
            : site_url('/' . $page);

        return $args ? add_query_arg($args, $base) : $base;
    }

    private function api_hub_url($api_id, array $args = []){
        if (($args['section'] ?? '') === 'schema') {
            $args['section'] = 'endpoint-schema';
        }

        return $this->page_url('api', array_merge(['api_id' => absint($api_id)], $args));
    }

    private function organization_url($slug, $view = 'home'){
        return $this->page_url('organization', [
            'organization' => sanitize_title($slug),
            'org_view' => sanitize_key($view),
        ]);
    }

    private function log_metrics($user_id, array $apis, $from = ''){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)) {
            return ['today' => 0, 'month' => 0, 'total' => 0, 'avg_ms' => 0, 'errors' => 0, 'error_rate' => 0];
        }

        $api_ids = array_map('intval', wp_list_pluck($apis, 'id'));
        $scope = $this->log_scope_sql($user_id, $api_ids);
        $where = $scope['where'];
        $args = $scope['args'];

        if ($from !== '') {
            $where[] = 'created_at >= %s';
            $args[] = $from;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $row = $wpdb->get_row($this->prepare_sql("SELECT COUNT(*) AS total, AVG(response_time) AS avg_time, SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS errors FROM `{$table}` {$where_sql}", $args), ARRAY_A) ?: [];

        $today = $this->count_logs_since($user_id, $api_ids, current_time('Y-m-d') . ' 00:00:00');
        $month = $this->count_logs_since($user_id, $api_ids, current_time('Y-m-01') . ' 00:00:00');
        $total = (int) ($row['total'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);

        return [
            'today' => $today,
            'month' => $month,
            'total' => $total,
            'avg_ms' => (int) round(((float) ($row['avg_time'] ?? 0)) * 1000),
            'errors' => $errors,
            'error_rate' => $total > 0 ? number_format(($errors / $total) * 100, 1) : '0.0',
        ];
    }

    private function analytics_data($user_id, array $apis, $range){
        global $wpdb;

        $from = $this->range_start($range);
        $metrics = $this->log_metrics($user_id, $apis, $from);
        $table = $wpdb->prefix . 'apiplatform_logs';
        $empty = ['requests' => [], 'latency' => [], 'errors' => [], 'top_endpoints' => [], 'status_codes' => [], 'top_apis' => [], 'peak_hours' => []];

        if (!$this->table_exists($table)) {
            return array_merge($empty, ['metrics' => $metrics, 'label' => $this->range_label($range)]);
        }

        $api_ids = array_map('intval', wp_list_pluck($apis, 'id'));
        $scope = $this->log_scope_sql($user_id, $api_ids);

        if ($from !== '') {
            $scope['where'][] = 'created_at >= %s';
            $scope['args'][] = $from;
        }

        $where_sql = $scope['where'] ? 'WHERE ' . implode(' AND ', $scope['where']) : '';
        $args = $scope['args'];
        $api_names = [];

        foreach ($apis as $api) {
            $api_names[(int) $api['id']] = $api['title'];
        }

        $requests = $wpdb->get_results($this->prepare_sql("SELECT DATE(created_at) AS day, COUNT(*) AS total FROM `{$table}` {$where_sql} GROUP BY DATE(created_at) ORDER BY day ASC LIMIT 90", $args), ARRAY_A);
        $latency = $wpdb->get_results($this->prepare_sql("SELECT DATE(created_at) AS day, ROUND(AVG(response_time) * 1000) AS avg_ms FROM `{$table}` {$where_sql} GROUP BY DATE(created_at) ORDER BY day ASC LIMIT 90", $args), ARRAY_A);
        $errors_where = $this->append_where($where_sql, 'status >= 400');
        $errors = $wpdb->get_results($this->prepare_sql("SELECT status, COUNT(*) AS total FROM `{$table}` {$errors_where} GROUP BY status ORDER BY total DESC LIMIT 8", $args), ARRAY_A);
        $top_endpoints = $wpdb->get_results($this->prepare_sql("SELECT endpoint, COUNT(*) AS total FROM `{$table}` {$where_sql} GROUP BY endpoint ORDER BY total DESC LIMIT 8", $args), ARRAY_A);
        $status_codes = $wpdb->get_results($this->prepare_sql("SELECT status, COUNT(*) AS total FROM `{$table}` {$where_sql} GROUP BY status ORDER BY total DESC LIMIT 8", $args), ARRAY_A);
        $top_apis_raw = $wpdb->get_results($this->prepare_sql("SELECT api_id, endpoint, COUNT(*) AS total FROM `{$table}` {$where_sql} GROUP BY api_id, endpoint ORDER BY total DESC LIMIT 8", $args), ARRAY_A);
        $peak_hours = $wpdb->get_results($this->prepare_sql("SELECT HOUR(created_at) AS hour, COUNT(*) AS total FROM `{$table}` {$where_sql} GROUP BY HOUR(created_at) ORDER BY total DESC LIMIT 8", $args), ARRAY_A);

        $top_apis = array_map(function($row) use ($api_names){
            $id = (int) ($row['api_id'] ?? 0);
            return [
                'api' => $api_names[$id] ?? ucwords(str_replace('-', ' ', (string) ($row['endpoint'] ?? 'Unknown API'))),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }, $top_apis_raw);

        return [
            'metrics' => $metrics,
            'label' => $this->range_label($range),
            'requests' => $requests,
            'latency' => $latency,
            'errors' => $errors,
            'top_endpoints' => $top_endpoints,
            'status_codes' => $status_codes,
            'top_apis' => $top_apis,
            'peak_hours' => array_map(function($row){
                $row['hour'] = str_pad((string) ($row['hour'] ?? '0'), 2, '0', STR_PAD_LEFT) . ':00';
                return $row;
            }, $peak_hours),
        ];
    }

    private function log_scope_sql($user_id, array $api_ids){
        $where = [];
        $args = [];

        if (!current_user_can('manage_options')) {
            $where[] = 'user_id = %d';
            $args[] = absint($user_id);
        }

        if ($api_ids) {
            $where[] = 'api_id IN (' . implode(',', array_fill(0, count($api_ids), '%d')) . ')';
            $args = array_merge($args, $api_ids);
        } elseif (!current_user_can('manage_options')) {
            $where[] = 'api_id = 0';
        }

        return ['where' => $where, 'args' => $args];
    }

    private function count_logs_since($user_id, array $api_ids, $from){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)) {
            return 0;
        }

        $scope = $this->log_scope_sql($user_id, $api_ids);
        $scope['where'][] = 'created_at >= %s';
        $scope['args'][] = $from;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $scope['where']), $scope['args']));
    }

    private function requests_today_by_api(array $api_ids){
        global $wpdb;

        $api_ids = array_values(array_filter(array_map('absint', $api_ids)));
        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$api_ids || !$this->table_exists($table)) {
            return [];
        }

        $sql = "SELECT api_id, COUNT(*) AS total FROM `{$table}` WHERE api_id IN (" . implode(',', array_fill(0, count($api_ids), '%d')) . ") AND created_at >= %s GROUP BY api_id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($api_ids, [current_time('Y-m-d') . ' 00:00:00'])));
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->api_id] = (int) $row->total;
        }

        return $counts;
    }

    private function recent_activity($user_id, array $apis, $limit){
        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_logs';

        if (!$this->table_exists($table)) {
            return [];
        }

        $api_ids = array_map('intval', wp_list_pluck($apis, 'id'));
        $scope = $this->log_scope_sql($user_id, $api_ids);
        $where_sql = $scope['where'] ? 'WHERE ' . implode(' AND ', $scope['where']) : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT api_id, endpoint, method, status, created_at FROM `{$table}` {$where_sql} ORDER BY created_at DESC LIMIT %d", array_merge($scope['args'], [absint($limit)])));
        $names = [];

        foreach ($apis as $api) {
            $names[(int) $api['id']] = $api['title'];
        }

        return array_map(function($row) use ($names){
            return [
                'title' => $names[(int) $row->api_id] ?? ucwords(str_replace('-', ' ', (string) $row->endpoint)),
                'meta' => strtoupper((string) $row->method) . ' ' . (int) $row->status . ' - ' . $this->date_label((string) $row->created_at),
            ];
        }, $rows);
    }

    private function visibility_counts(array $apis){
        $counts = ['public' => 0, 'unlisted' => 0, 'private' => 0];

        foreach ($apis as $api) {
            $visibility = $api['visibility'] ?? 'private';
            $counts[$visibility] = ($counts[$visibility] ?? 0) + 1;
        }

        return $counts;
    }

    private function lifecycle_counts(array $apis){
        $counts = [
            'draft' => 0,
            'private' => 0,
            'testing' => 0,
            'ready' => 0,
            'public' => 0,
            'deprecated' => 0,
            'archived' => 0,
        ];

        foreach ($apis as $api) {
            $lifecycle = sanitize_key($api['lifecycle'] ?? 'private');
            $counts[$lifecycle] = ($counts[$lifecycle] ?? 0) + 1;
        }

        return $counts;
    }

    private function filter_apis(array $apis, array $filters){
        return array_values(array_filter($apis, function($api) use ($filters){
            if ($filters['visibility'] !== '' && $api['visibility'] !== $filters['visibility']) {
                return false;
            }

            if ($filters['status'] !== '' && sanitize_key($api['status']) !== $filters['status']) {
                return false;
            }

            if ($filters['lifecycle'] !== '' && sanitize_key($api['lifecycle']) !== $filters['lifecycle']) {
                return false;
            }

            if ($filters['category'] !== '' && sanitize_key($api['category']) !== $filters['category']) {
                return false;
            }

            if (($filters['owner'] ?? '') !== '') {
                if ($filters['owner'] === 'personal' && $api['owner_type'] !== 'personal') {
                    return false;
                }
                if ($filters['owner'] === 'organization' && $api['owner_type'] !== 'organization') {
                    return false;
                }
                if (strpos($filters['owner'], 'organization:') === 0) {
                    $owner_id = absint(substr($filters['owner'], strlen('organization:')));
                    if ($api['owner_type'] !== 'organization' || absint($api['owner_id']) !== $owner_id) {
                        return false;
                    }
                }
            }

            if ($filters['search'] !== '') {
                $haystack = strtolower($api['title'] . ' ' . $api['summary'] . ' ' . $api['slug'] . ' ' . $api['category_label'] . ' ' . $api['auth_label'] . ' ' . $api['owner_label']);
                return strpos($haystack, strtolower($filters['search'])) !== false;
            }

            return true;
        }));
    }

    private function sort_apis(array $apis, $sort){
        usort($apis, function($a, $b) use ($sort){
            if ($sort === 'title_asc') {
                return strcasecmp($a['title'], $b['title']);
            }

            if ($sort === 'requests_desc') {
                return (int) $b['requests_today'] <=> (int) $a['requests_today'];
            }

            if ($sort === 'lifecycle_asc') {
                return strcasecmp($a['lifecycle_label'], $b['lifecycle_label']);
            }

            if ($sort === 'readiness_desc') {
                return (int) $b['readiness_percent'] <=> (int) $a['readiness_percent'];
            }

            return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
        });

        return $apis;
    }

    private function my_api_filters(){
        $visibility = isset($_GET['visibility']) ? sanitize_key(wp_unslash($_GET['visibility'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $lifecycle = isset($_GET['lifecycle']) ? sanitize_key(wp_unslash($_GET['lifecycle'])) : '';
        $category = isset($_GET['category']) ? sanitize_title(wp_unslash($_GET['category'])) : '';
        $owner = isset($_GET['owner']) ? sanitize_text_field(wp_unslash($_GET['owner'])) : '';
        $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'updated_desc';
        $states = class_exists('APIPlatform_Lifecycle_Policy') ? array_keys(APIPlatform_Lifecycle_Policy::states()) : ['draft', 'private', 'testing', 'ready', 'public', 'deprecated', 'archived'];
        $allowed_owners = array_keys($this->owner_filter_options(get_current_user_id()));

        return [
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'visibility' => in_array($visibility, ['', 'private', 'unlisted', 'public'], true) ? $visibility : '',
            'status' => $status,
            'lifecycle' => in_array($lifecycle, array_merge([''], $states), true) ? $lifecycle : '',
            'category' => $category,
            'owner' => in_array($owner, $allowed_owners, true) ? $owner : '',
            'sort' => in_array($sort, ['updated_desc', 'lifecycle_asc', 'title_asc', 'requests_desc', 'readiness_desc'], true) ? $sort : 'updated_desc',
        ];
    }

    private function owner_filter_options($user_id){
        $options = [
            '' => 'All APIs',
            'personal' => 'Personal',
            'organization' => 'All Organizations',
        ];

        if (class_exists('APIPlatform_Organization_Service')) {
            foreach (APIPlatform_Organization_Service::list_for_user($user_id) as $organization) {
                $options['organization:' . absint($organization['id'])] = $organization['name'];
            }
        }

        return $options;
    }

    private function analytics_range(){
        $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '30';
        return in_array($range, ['24h', '7', '30', '90'], true) ? $range : '30';
    }

    private function range_start($range){
        $seconds = [
            '24h' => DAY_IN_SECONDS,
            '7' => 7 * DAY_IN_SECONDS,
            '30' => 30 * DAY_IN_SECONDS,
            '90' => 90 * DAY_IN_SECONDS,
        ];

        return gmdate('Y-m-d H:i:s', current_time('timestamp') - ($seconds[$range] ?? (30 * DAY_IN_SECONDS)));
    }

    private function range_label($range){
        return [
            '24h' => 'Last 24 hours',
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ][$range] ?? 'Last 30 days';
    }

    private function recent_keys(array $apis, $limit){
        $items = [];

        foreach ($apis as $api) {
            $keys = get_post_meta($api['id'], 'api_keys', true);

            foreach ((array) $keys as $key) {
                $created = (int) ($key['created'] ?? 0);

                if (!$created) {
                    continue;
                }

                $items[] = [
                    'title' => ($key['label'] ?? 'API Key') . ' - ' . $api['title'],
                    'meta' => gmdate('Y-m-d H:i', $created),
                ];
            }
        }

        usort($items, function($a, $b){ return strcmp($b['meta'], $a['meta']); });

        return array_slice($items, 0, absint($limit));
    }

    private function recent_docs(array $apis, $limit){
        $items = [];

        foreach ($apis as $api) {
            if ($api['docs_status'] === '') {
                continue;
            }

            $items[] = [
                'title' => $api['title'],
                'meta' => ucfirst($api['docs_status']) . ' - ' . $api['updated_label'],
            ];
        }

        return array_slice($items, 0, absint($limit));
    }

    private function enqueue(){
        APIPlatform_Frontend_Assets::enqueue_common();

        if (wp_style_is('apiplatform-dashboard-style', 'registered')) {
            wp_enqueue_style('apiplatform-dashboard-style');
        }

        if (wp_script_is('apiplatform-keys', 'registered')) {
            wp_enqueue_script('apiplatform-keys');
        }

        wp_enqueue_script(
            'apiplatform-documentation-workspace',
            APIPLATFORM_URL . 'modules/frontend/assets/js/documentation-workspace.js',
            [],
            apiplatform_asset_version('modules/frontend/assets/js/documentation-workspace.js'),
            true
        );
    }

    private function section($content){
        return APIPlatform_Renderer::component('section', [
            'content' => $content,
        ]);
    }

    private function alert($type, $content){
        return APIPlatform_Renderer::component('alert', [
            'type' => $type,
            'content' => $content,
        ]);
    }

    private function button($label, $url, $type = 'secondary'){
        return APIPlatform_Renderer::component('button', [
            'type' => $type,
            'label' => $label,
            'url' => $url,
        ]);
    }

    private function metric_grid(array $stats){
        $html = '<div class="apiplatform-publisher-metrics">';

        foreach ($stats as $stat) {
            $html .= '<div class="apiplatform-publisher-metric"><span>' . esc_html($stat['label']) . '</span><strong>' . esc_html((string) $stat['value']) . '</strong><small>' . esc_html($stat['meta']) . '</small></div>';
        }

        return $html . '</div>';
    }

    private function panel($title, $content, $id = ''){
        $id_attr = $id !== '' ? ' id="' . esc_attr($id) . '"' : '';

        return '<section' . $id_attr . ' class="apiplatform-publisher-panel"><h2>' . esc_html($title) . '</h2>' . $content . '</section>';
    }

    private function quick_actions(){
        return '<div class="apiplatform-publisher-action-list">' .
            $this->button('Create API', $this->page_url('create'), 'primary') .
            $this->button('My APIs', $this->page_url('my-apis'), 'secondary') .
            $this->button('Analytics', $this->page_url('analytics'), 'secondary') .
            $this->button('Logs', $this->page_url('logs'), 'secondary') .
            $this->button('Documentation', $this->page_url('documentation-manager'), 'secondary') .
            '</div>';
    }

    private function activity_list(array $items){
        if (!$items) {
            return $this->empty_state('No recent activity.', 'Requests will appear here after your APIs receive traffic.');
        }

        return $this->simple_list($items);
    }

    private function api_update_list(array $apis){
        $items = array_map(function($api){
            return ['title' => $api['title'], 'meta' => ucfirst($api['visibility']) . ' - ' . $api['updated_label']];
        }, $apis);

        return $items ? $this->simple_list($items) : $this->empty_state('No API updates yet.', 'Edit an API to see updates here.');
    }

    private function docs_change_list(array $items){
        return $items ? $this->simple_list($items) : $this->empty_state('No documentation changes yet.', 'Enable or update API documentation from the Edit API page.');
    }

    private function key_list(array $items){
        return $items ? $this->simple_list($items) : $this->empty_state('No recent key events.', 'Generated key events will appear without exposing plaintext secrets.');
    }

    private function simple_list(array $items){
        $html = '<ul class="apiplatform-publisher-list">';

        foreach ($items as $item) {
            $html .= '<li><strong>' . esc_html($item['title']) . '</strong><span>' . esc_html($item['meta']) . '</span></li>';
        }

        return $html . '</ul>';
    }

    private function api_card(array $api){
        $visibility_class = sanitize_html_class('apiplatform-publisher-badge-' . $api['visibility']);
        $status_class = sanitize_html_class('apiplatform-publisher-badge-' . sanitize_key($api['status']));
        $lifecycle_class = sanitize_html_class('apiplatform-publisher-badge-lifecycle-' . sanitize_key($api['lifecycle']));

        return '<article class="apiplatform-publisher-card">' .
            '<div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html($api['initials']) . '</span><div><h2>' . esc_html($api['title']) . '</h2><p>' . esc_html($api['summary'] ?: $api['endpoint']) . '</p></div></div>' .
            '<div class="apiplatform-publisher-badges"><span class="apiplatform-publisher-badge ' . esc_attr($lifecycle_class) . '">Lifecycle: ' . esc_html($api['lifecycle_label']) . '</span><span class="apiplatform-publisher-badge ' . esc_attr($visibility_class) . '">Visibility: ' . esc_html(ucfirst($api['visibility'])) . '</span><span class="apiplatform-publisher-badge ' . esc_attr($status_class) . '">Runtime: ' . esc_html(ucfirst($api['status'])) . '</span></div>' .
            '<dl class="apiplatform-publisher-meta"><div><dt>Owner</dt><dd>' . esc_html($api['owner_label']) . ' <span>' . esc_html(ucfirst($api['owner_type'])) . '</span></dd></div><div><dt>Version</dt><dd>' . esc_html($api['version']) . '</dd></div><div><dt>Readiness</dt><dd>' . esc_html($api['readiness_percent'] . '%') . '</dd></div><div><dt>Category</dt><dd>' . esc_html($api['category_label']) . '</dd></div><div><dt>Authentication</dt><dd>' . esc_html($api['auth_label']) . '</dd></div><div><dt>Requests Today</dt><dd>' . esc_html(number_format($api['requests_today'])) . '</dd></div><div><dt>Last Updated</dt><dd>' . esc_html($api['updated_label']) . '</dd></div></dl>' .
            '<div class="apiplatform-publisher-actions">' .
            (!empty($api['can_manage']) ? $this->button('Manage', $api['urls']['manage'], 'primary') : '<span class="apiplatform-publisher-disabled-action">Read-only API access</span>') .
            (!empty($api['can_manage_docs']) ? $this->button('Documentation', $api['urls']['documentation'], 'secondary') : '') .
            (!empty($api['can_view_analytics']) ? $this->button('Analytics', $api['urls']['analytics'], 'secondary') : '') .
            (!empty($api['can_view_logs']) ? $this->button('Logs', $api['urls']['logs'], 'secondary') : '') .
            (!empty($api['can_manage']) ? $this->button('Tester', $api['urls']['tester'], 'secondary') . $this->button('Edit', $api['urls']['edit'], 'secondary') . $this->button('Publish', $api['urls']['publish'], 'secondary') . '<span class="apiplatform-publisher-disabled-action">Duplicate planned</span>' . ($api['status'] === 'archived' ? $this->owner_post_button('Restore', 'restore', $api['id'], 'success') : $this->owner_post_button('Archive', 'archive', $api['id'], 'warning')) . $this->button('Delete', $api['urls']['edit'] . '#apiplatform-danger-zone', 'danger') : '') .
            '</div></article>';
    }

    private function filters_form(array $filters){
        $base = current_user_can('manage_options') ? (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')) : site_url('/my-apis');
        $html = '<form class="apiplatform-publisher-filters" method="get" action="' . esc_url($base) . '">';

        if (current_user_can('manage_options')) {
            $html .= '<input type="hidden" name="apipage" value="my-apis">';
        }

        $html .= '<label><span>Search</span><input type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="Search APIs"></label>';
        $lifecycle_options = class_exists('APIPlatform_Lifecycle_Policy') ? APIPlatform_Lifecycle_Policy::states() : ['draft' => 'Draft', 'private' => 'Private', 'testing' => 'Testing', 'ready' => 'Ready', 'public' => 'Public', 'deprecated' => 'Deprecated', 'archived' => 'Archived'];
        $html .= '<label><span>Visibility</span><select name="visibility"><option value="">All</option>' . $this->options(['private' => 'Private', 'unlisted' => 'Unlisted', 'public' => 'Public'], $filters['visibility']) . '</select></label>';
        $html .= '<label><span>Owner</span><select name="owner">' . $this->options($this->owner_filter_options(get_current_user_id()), $filters['owner'] ?? '') . '</select></label>';
        $html .= '<label><span>Lifecycle</span><select name="lifecycle"><option value="">All</option>' . $this->options($lifecycle_options, $filters['lifecycle']) . '</select></label>';
        $html .= '<label><span>Active State</span><select name="status"><option value="">All</option>' . $this->options(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'draft' => 'Draft'], $filters['status']) . '</select></label>';
        $html .= '<label><span>Category</span><select name="category"><option value="">All</option>' . $this->options($this->category_options(), $filters['category']) . '</select></label>';
        $html .= '<label><span>Sort</span><select name="sort">' . $this->options(['updated_desc' => 'Recently updated', 'lifecycle_asc' => 'Lifecycle', 'title_asc' => 'Name', 'requests_desc' => 'Requests', 'readiness_desc' => 'Readiness'], $filters['sort']) . '</select></label>';
        $html .= '<button class="apiplatform-button apiplatform-button-primary" type="submit">Apply</button></form>';

        return $html;
    }

    private function options(array $options, $selected){
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }

        return $html;
    }

    private function owner_hidden_fields($action, $api_id){
        return '<input type="hidden" name="apiplatform_owner_action" value="' . esc_attr($action) . '">' .
            '<input type="hidden" name="api_id" value="' . esc_attr(absint($api_id)) . '">' .
            '<input type="hidden" name="apiplatform_owner_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_owner_action_' . absint($api_id))) . '">';
    }

    private function owner_post_button($label, $action, $api_id, $type = 'secondary'){
        return '<form method="post" class="apiplatform-publisher-inline-form">' .
            $this->owner_hidden_fields($action, $api_id) .
            '<button class="apiplatform-button apiplatform-button-' . esc_attr($type) . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    private function lifecycle_post_button($label, $target, $api_id, $type = 'secondary'){
        return '<form method="post" class="apiplatform-publisher-inline-form">' .
            $this->owner_hidden_fields('lifecycle', $api_id) .
            '<input type="hidden" name="apiplatform_lifecycle_target" value="' . esc_attr($target) . '">' .
            '<button class="apiplatform-button apiplatform-button-' . esc_attr($type) . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    private function lifecycle_deprecate_form(array $api){
        return '<form method="post" class="apiplatform-publisher-lifecycle-form">' .
            $this->owner_hidden_fields('lifecycle', $api['id']) .
            '<input type="hidden" name="apiplatform_lifecycle_target" value="deprecated">' .
            '<label><span>Deprecation Notice</span><textarea name="apiplatform_deprecation_notice" rows="2" required>' . esc_textarea(get_post_meta($api['id'], 'apiplatform_portal_deprecation_notice', true)) . '</textarea></label>' .
            '<label><span>Sunset Date</span><input type="date" name="apiplatform_sunset_date" value="' . esc_attr(get_post_meta($api['id'], 'apiplatform_portal_sunset_date', true)) . '"></label>' .
            '<label><span>Replacement</span><input type="text" name="apiplatform_replacement_version" value="' . esc_attr(get_post_meta($api['id'], 'apiplatform_portal_replacement_version', true)) . '" placeholder="Optional replacement API or version"></label>' .
            '<label><span>Migration URL</span><input type="url" name="apiplatform_migration_url" value="' . esc_attr(get_post_meta($api['id'], 'apiplatform_portal_migration_url', true)) . '" placeholder="https://"></label>' .
            '<label><span>Migration Message</span><textarea name="apiplatform_migration_message" rows="2">' . esc_textarea(get_post_meta($api['id'], 'apiplatform_portal_migration_message', true)) . '</textarea></label>' .
            '<button class="apiplatform-button apiplatform-button-warning" type="submit">Deprecate</button></form>';
    }

    private function application_access_button($label, $api_id, $access_id, $status, $type = 'secondary'){
        return '<form method="post" class="apiplatform-publisher-inline-form">' .
            $this->owner_hidden_fields('application-access', $api_id) .
            '<input type="hidden" name="access_id" value="' . esc_attr(absint($access_id)) . '">' .
            '<input type="hidden" name="access_status" value="' . esc_attr(sanitize_key($status)) . '">' .
            '<button class="apiplatform-button apiplatform-button-' . esc_attr($type) . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    private function field_input($label, $name, $value, $type = 'text'){
        $id = sanitize_html_class($name);

        return '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" class="apiplatform-input" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></div>';
    }

    private function field_textarea($label, $name, $value, $rows){
        $id = sanitize_html_class($name);

        return '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><textarea id="' . esc_attr($id) . '" class="apiplatform-input" name="' . esc_attr($name) . '" rows="' . absint($rows) . '">' . esc_textarea($value) . '</textarea></div>';
    }

    private function field_select($label, $name, $value, array $options){
        $id = sanitize_html_class($name);
        $html = '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" class="apiplatform-input" name="' . esc_attr($name) . '">';

        foreach ($options as $option_value => $option_label) {
            $html .= '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
        }

        return $html . '</select></div>';
    }

    private function sanitize_methods($value){
        $raw = is_array($value) ? $value : preg_split('/[\s,]+/', (string) wp_unslash($value));
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $methods = [];

        foreach ((array) $raw as $method) {
            $method = strtoupper(sanitize_key((string) $method));
            if (in_array($method, $allowed, true)) {
                $methods[] = $method;
            }
        }

        return array_values(array_unique($methods ?: ['GET']));
    }

    private function decoded_json_field($field, $default){
        $value = trim((string) wp_unslash($_POST[$field] ?? ''));

        if ($value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function clean_date($value){
        $value = sanitize_text_field(wp_unslash($value));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function notice_key($user_id, $api_id){
        return 'apiplatform_publisher_notice_' . absint($user_id) . '_' . absint($api_id);
    }

    private function openapi_preview_key($user_id, $api_id){
        return 'apiplatform_openapi_preview_' . absint($user_id) . '_' . absint($api_id);
    }

    private function openapi_export_key($user_id, $api_id){
        return 'apiplatform_openapi_export_' . absint($user_id) . '_' . absint($api_id);
    }

    private function sdk_preview_key($user_id, $api_id, $token = ''){
        return 'apiplatform_sdk_preview_' . absint($user_id) . '_' . absint($api_id) . '_' . sanitize_key($token);
    }

    private function target_section_for_action($action){
        return [
            'archive' => 'settings',
            'restore' => 'settings',
            'lifecycle' => 'overview',
            'save-version' => 'versions',
            'save-docs' => 'documentation',
            'publish-docs' => 'documentation',
            'save-doc-release' => 'documentation',
            'save-endpoint-schema' => 'schema',
            'save-data-models' => 'schema',
            'save-validation-settings' => 'schema',
            'save-gateway-settings' => 'settings',
            'preview-openapi-import' => 'schema',
            'confirm-openapi-import' => 'schema',
            'export-openapi' => 'schema',
            'save-sdk-settings' => 'schema',
            'preview-sdk' => 'schema',
            'download-sdk' => 'schema',
            'unpublish' => 'publishing',
            'application-access' => 'overview',
        ][$action] ?? 'overview';
    }

    public function filter_supported_methods($methods, $api){
        $stored = get_post_meta($api->ID, 'apiplatform_docs_methods', true);

        return $stored ? $this->sanitize_methods($stored) : $methods;
    }

    public function filter_documentation_parameters($parameters, $api_id){
        $stored = get_post_meta($api_id, 'api_parameters', true);

        return is_array($stored) ? $stored : $parameters;
    }

    public function filter_documentation_errors($errors){
        $api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;

        if (!$api_id && isset($_GET['apiplatform_portal_preview'])) {
            $api_id = absint($_GET['apiplatform_portal_preview']);
        }

        if (!$api_id) {
            return $errors;
        }

        $stored = get_post_meta($api_id, 'apiplatform_docs_errors', true);

        return is_array($stored) && $stored ? $stored : $errors;
    }

    public function filter_documentation_data($data, $api, $user_id){
        $overview = get_post_meta($api->ID, 'apiplatform_docs_overview', true);
        $auth = get_post_meta($api->ID, 'apiplatform_docs_authentication', true);
        $endpoint_description = get_post_meta($api->ID, 'apiplatform_docs_endpoint_description', true);
        $headers = get_post_meta($api->ID, 'apiplatform_docs_headers', true);
        $rate_limit = get_post_meta($api->ID, 'apiplatform_docs_rate_limit', true);
        $errors = get_post_meta($api->ID, 'apiplatform_docs_errors', true);
        $success_response = get_post_meta($api->ID, 'apiplatform_docs_success_response', true);

        if ($overview !== '') {
            $data['api']['description'] = $overview;
        }

        if ($auth !== '') {
            $data['authentication']['recommended'] = $auth;
        }

        if ($endpoint_description !== '') {
            $data['endpoint_description'] = $endpoint_description;
        }

        if (is_array($headers) && $headers) {
            $data['headers'] = $headers;
            foreach ($headers as $header) {
                if (!is_array($header) || empty($header['name'])) {
                    continue;
                }
                $data['parameters'][] = [
                    'name' => sanitize_text_field((string) $header['name']),
                    'location' => 'Header',
                    'type' => 'String',
                    'required' => !empty($header['required']),
                    'default' => sanitize_text_field((string) ($header['value'] ?? '')),
                    'description' => sanitize_text_field((string) ($header['description'] ?? '')),
                    'example' => sanitize_text_field((string) ($header['value'] ?? '')),
                ];
            }
        }

        if ($rate_limit !== '') {
            $data['rate_limit']['summary'] = $rate_limit;
        }

        if (is_array($errors) && $errors) {
            $data['errors'] = $errors;
        }

        if (is_string($success_response) && trim($success_response) !== '') {
            $decoded = json_decode($success_response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data['example_response'] = [
                    'label' => 'Documented success response',
                    'code' => wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        return $data;
    }

    public function filter_code_examples($examples, $endpoint, $methods, $body_example){
        if (class_exists('APIPlatform_SDK_Generator_Service')) {
            $method = $methods[0] ?? 'GET';
            $schema_endpoint = [
                'method' => $method,
                'path' => esc_url_raw($endpoint),
                'request_body' => [
                    'enabled' => !empty($body_example['enabled']),
                    'content_type' => 'application/json',
                    'example' => json_decode((string) ($body_example['example'] ?? '{}'), true) ?: [],
                ],
            ];

            return APIPlatform_SDK_Generator_Service::endpoint_snippets($endpoint, $schema_endpoint);
        }

        $method = $methods[0] ?? 'GET';
        $body = !empty($body_example['enabled']) ? (string) ($body_example['example'] ?? '') : '';
        $has_body = $body !== '' && $body !== '{}';
        $body_line = $has_body ? ",\n  body: JSON.stringify(" . $body . ")" : '';
        $content_type = $has_body ? ",\n    \"Content-Type\": \"application/json\"" : '';

        $examples['node'] = [
            'title' => 'Node.js',
            'language' => 'javascript',
            'code' => "const response = await fetch(\"" . esc_url_raw($endpoint) . "\", {\n  method: \"" . esc_js($method) . "\",\n  headers: {\n    \"X-API-Key\": \"YOUR_API_KEY\",\n    \"Accept\": \"application/json\"" . $content_type . "\n  }" . $body_line . "\n});\n\nconst data = await response.json();\nconsole.log(data);",
        ];

        return $examples;
    }

    private function range_nav($active){
        $items = ['24h' => '24 hours', '7' => '7 days', '30' => '30 days', '90' => '90 days'];
        $api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;
        $html = '<nav class="apiplatform-publisher-range" aria-label="Analytics date range">';

        foreach ($items as $range => $label) {
            $args = ['range' => $range];

            if ($api_id) {
                $args['api_id'] = $api_id;
            }

            $html .= '<a href="' . esc_url($this->page_url('analytics', $args)) . '"' . ($active === $range ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }

        return $html . '<span>Custom range planned</span></nav>';
    }

    private function bar_list(array $rows, $label_key, $value_key, $suffix = ''){
        if (!$rows) {
            return $this->empty_state('No captured data.', 'This chart will populate from request logs.');
        }

        $max = max(1, max(array_map(function($row) use ($value_key){ return (int) ($row[$value_key] ?? 0); }, $rows)));
        $html = '<div class="apiplatform-publisher-bars">';

        foreach ($rows as $row) {
            $value = (int) ($row[$value_key] ?? 0);
            $width = max(4, ($value / $max) * 100);
            $html .= '<div class="apiplatform-publisher-bar"><div><span>' . esc_html((string) ($row[$label_key] ?? 'Unknown')) . '</span><strong>' . esc_html(number_format($value) . $suffix) . '</strong></div><i style="width:' . esc_attr(number_format($width, 2)) . '%"></i></div>';
        }

        return $html . '</div>';
    }

    private function not_captured($message){
        return '<div class="apiplatform-publisher-unavailable"><strong>Not captured</strong><p>' . esc_html($message) . '</p></div>';
    }

    private function empty_state($title, $message){
        return '<div class="apiplatform-publisher-empty"><strong>' . esc_html($title) . '</strong><p>' . esc_html($message) . '</p></div>';
    }

    private function category_label($category){
        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $categories = APIPlatform_Developer_Portal_Query::categories();
            return $categories[$category] ?? ($category ? ucwords(str_replace('-', ' ', $category)) : 'Uncategorized');
        }

        return $category ? ucwords(str_replace('-', ' ', $category)) : 'Uncategorized';
    }

    private function category_options(){
        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            return APIPlatform_Developer_Portal_Query::categories();
        }

        return [
            'data' => 'Data',
            'ai' => 'AI',
            'gaming' => 'Gaming',
            'utilities' => 'Utilities',
            'finance' => 'Finance',
            'social' => 'Social',
            'media' => 'Media',
            'developer-tools' => 'Developer Tools',
            'productivity' => 'Productivity',
            'other' => 'Other',
        ];
    }

    private function auth_label($auth){
        if (class_exists('APIPlatform_Developer_Portal_Query')) {
            $options = APIPlatform_Developer_Portal_Query::authentication_options();
            return $options[$auth] ?? 'API Key';
        }

        return $auth ? ucwords(str_replace('-', ' ', $auth)) : 'API Key';
    }

    private function date_label($date){
        $timestamp = strtotime((string) $date);

        return $timestamp ? human_time_diff($timestamp, current_time('timestamp')) . ' ago' : 'Not captured';
    }

    private function table_exists($table){
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function prepare_sql($sql, array $args){
        global $wpdb;

        return $args ? $wpdb->prepare($sql, $args) : $sql;
    }

    private function append_where($where_sql, $condition){
        $where_sql = trim((string) $where_sql);

        return $where_sql === ''
            ? 'WHERE ' . $condition
            : $where_sql . ' AND ' . $condition;
    }
}
