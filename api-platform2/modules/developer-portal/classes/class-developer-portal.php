<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal {

    private static $instance = null;

    private $route = null;
    private $route_vars = [];

    public function __construct(){
        self::$instance = $this;
        add_action('init', [$this, 'register_routes'], 9);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'maybe_handle_portal_action'], 0);
        add_action('template_redirect', [$this, 'render_route']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
    }

    public static function instance(){
        return self::$instance;
    }

    public function register_routes(){
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_prefix() : 'developers';
        add_rewrite_rule('^' . $prefix . '/?$', 'index.php?apiplatform_portal=home', 'top');
        add_rewrite_rule('^' . $prefix . '/apis/?$', 'index.php?apiplatform_portal=directory', 'top');
        add_rewrite_rule('^' . $prefix . '/apis/([^/]+)/sdk/([^/]+)\.zip/?$', 'index.php?apiplatform_portal=sdk&apiplatform_portal_slug=$matches[1]&apiplatform_portal_sdk_language=$matches[2]', 'top');
        add_rewrite_rule('^' . $prefix . '/apis/([^/]+)/docs/?$', 'index.php?apiplatform_portal=docs&apiplatform_portal_slug=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/apis/([^/]+)/test/?$', 'index.php?apiplatform_portal=test&apiplatform_portal_slug=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/apis/([^/]+)/?$', 'index.php?apiplatform_portal=api&apiplatform_portal_slug=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/categories/([^/]+)/?$', 'index.php?apiplatform_portal=category&apiplatform_portal_category=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/tags/([^/]+)/?$', 'index.php?apiplatform_portal=tag&apiplatform_portal_tag=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/getting-started/?$', 'index.php?apiplatform_portal=getting-started', 'top');
        add_rewrite_rule('^' . $prefix . '/dashboard/?$', 'index.php?apiplatform_portal=dashboard', 'top');

        $routes_version = '10-1-' . md5($prefix);
        if (get_option('apiplatform_developer_portal_routes_version') !== $routes_version) {
            flush_rewrite_rules(false);
            update_option('apiplatform_developer_portal_routes_version', $routes_version);
        }
    }

    public function query_vars($vars){
        foreach (['apiplatform_portal', 'apiplatform_portal_slug', 'apiplatform_portal_category', 'apiplatform_portal_tag', 'apiplatform_portal_sdk_language'] as $var) {
            $vars[] = $var;
        }
        return $vars;
    }

    public function maybe_enqueue_assets(){
        if (!$this->current_route()) {
            return;
        }

        wp_enqueue_style(
            'apiplatform-developer-portal',
            APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/css/developer-portal.css',
            ['apiplatform-frontend'],
            apiplatform_asset_version('modules/developer-portal/assets/css/developer-portal.css')
        );
        wp_enqueue_script(
            'apiplatform-developer-portal',
            APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/js/developer-portal.js',
            [],
            apiplatform_asset_version('modules/developer-portal/assets/js/developer-portal.js'),
            true
        );

        $route = $this->current_route();

        if ($route === 'test') {
            wp_enqueue_style(
                'apiplatform-portal-tester',
                APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/css/portal-tester.css',
                ['apiplatform-developer-portal'],
                apiplatform_asset_version('modules/developer-portal/assets/css/portal-tester.css')
            );
            wp_enqueue_script(
                'apiplatform-portal-tester',
                APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/js/portal-tester.js',
                ['apiplatform-developer-portal'],
                apiplatform_asset_version('modules/developer-portal/assets/js/portal-tester.js'),
                true
            );
            wp_add_inline_script(
                'apiplatform-portal-tester',
                'window.APIPlatformPortalTester=' . wp_json_encode(['ajaxUrl' => admin_url('admin-ajax.php')]) . ';',
                'before'
            );
        }

        if ($route === 'docs') {
            wp_enqueue_style(
                'apiplatform-portal-docs',
                APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/css/portal-docs.css',
                ['apiplatform-developer-portal'],
                apiplatform_asset_version('modules/developer-portal/assets/css/portal-docs.css')
            );
            wp_enqueue_script(
                'apiplatform-portal-docs',
                APIPLATFORM_DEVELOPER_PORTAL_URL . 'assets/js/portal-docs.js',
                ['apiplatform-developer-portal'],
                apiplatform_asset_version('modules/developer-portal/assets/js/portal-docs.js'),
                true
            );
        }
    }

    public function render_route(){
        $route = $this->current_route();

        if (!$route) {
            return;
        }

        status_header(200);
        nocache_headers();
        $content = $this->render($route);

        if (class_exists('APIPlatform_Frontend_Layout')) {
            echo APIPlatform_Frontend_Layout::render_document($content, $this->route_context($route));
        } else {
            echo $content;
        }
        exit;
    }

    public function maybe_handle_portal_action(){
        if (empty($_POST['apiplatform_portal_action'])) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_die('Login required.');
        }

        $action = sanitize_key(wp_unslash($_POST['apiplatform_portal_action']));
        $api_id = absint($_POST['api_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['apiplatform_portal_nonce'] ?? ''));

        if ($action !== 'toggle-favorite' || !$api_id || !wp_verify_nonce($nonce, 'apiplatform_portal_favorite_' . $api_id)) {
            wp_die('Security check failed.');
        }

        $api = get_post($api_id);
        if (!$api || $api->post_type !== 'user_api') {
            wp_die('API unavailable.');
        }

        $card = APIPlatform_Developer_Portal_Query::api_card($api);
        if (($card['visibility'] ?? '') !== 'public') {
            wp_die('API unavailable.');
        }

        $favorites = $this->favorite_api_ids(get_current_user_id());
        if (in_array($api_id, $favorites, true)) {
            $favorites = array_values(array_diff($favorites, [$api_id]));
        } else {
            $favorites[] = $api_id;
        }

        update_user_meta(get_current_user_id(), 'apiplatform_developer_favorite_apis', array_values(array_unique(array_map('absint', $favorites))));
        wp_safe_redirect(esc_url_raw(wp_get_referer() ?: (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('dashboard') : home_url('/developers/dashboard'))));
        exit;
    }

    public function render_public($route = 'home'){
        $route = $route ?: 'home';
        return $this->render($route);
    }

    public function current_route(){
        if ($this->route !== null) {
            return $this->route;
        }

        $route = get_query_var('apiplatform_portal');

        if (!$route && isset($_GET['apiplatform_portal'])) {
            $route = sanitize_key(wp_unslash($_GET['apiplatform_portal']));
        }

        if (!$route) {
            $route = $this->route_from_path();
        }

        $allowed = ['home', 'directory', 'api', 'docs', 'test', 'sdk', 'category', 'tag', 'getting-started', 'dashboard'];
        $this->route = in_array($route, $allowed, true) ? $route : '';

        return $this->route;
    }

    private function route_from_path(){
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_prefix() : 'developers';
        $quoted_prefix = preg_quote($prefix, '#');

        if ($path === $prefix) {
            return 'home';
        }

        if ($path === $prefix . '/apis') {
            return 'directory';
        }

        if ($path === $prefix . '/getting-started') {
            return 'getting-started';
        }

        if ($path === $prefix . '/dashboard') {
            return 'dashboard';
        }

        if (preg_match('#^' . $quoted_prefix . '/apis/([^/]+)/sdk/([^/]+)\.zip$#', $path, $matches)) {
            $this->route_vars['slug'] = sanitize_title($matches[1]);
            $this->route_vars['sdk_language'] = sanitize_key($matches[2]);
            return 'sdk';
        }

        if (preg_match('#^' . $quoted_prefix . '/apis/([^/]+)/(docs|test)$#', $path, $matches)) {
            $this->route_vars['slug'] = sanitize_title($matches[1]);
            return sanitize_key($matches[2]);
        }

        if (preg_match('#^' . $quoted_prefix . '/apis/([^/]+)$#', $path, $matches)) {
            $this->route_vars['slug'] = sanitize_title($matches[1]);
            return 'api';
        }

        if (preg_match('#^' . $quoted_prefix . '/categories/([^/]+)$#', $path, $matches)) {
            $this->route_vars['category'] = sanitize_title($matches[1]);
            return 'category';
        }

        if (preg_match('#^' . $quoted_prefix . '/tags/([^/]+)$#', $path, $matches)) {
            $this->route_vars['tag'] = sanitize_title($matches[1]);
            return 'tag';
        }

        return '';
    }

    private function route_var($key){
        return $this->route_vars[$key] ?? '';
    }

    private function render($route){
        if ($route === 'home') return $this->home();
        if ($route === 'directory') return $this->directory();
        if ($route === 'category') return $this->directory(['category' => sanitize_title(get_query_var('apiplatform_portal_category') ?: $this->route_var('category'))]);
        if ($route === 'tag') return $this->directory(['tag' => sanitize_title(get_query_var('apiplatform_portal_tag') ?: $this->route_var('tag'))]);
        if ($route === 'getting-started') return $this->getting_started();
        if ($route === 'dashboard') return $this->developer_dashboard();
        return $this->api_route($route);
    }

    private function route_context($route){
        $map = [
            'home' => ['portal_page' => 'home', 'title' => 'Developer Portal'],
            'directory' => ['portal_page' => 'directory', 'title' => 'API Directory'],
            'api' => ['portal_page' => 'api-detail', 'title' => 'API Details'],
            'docs' => ['portal_page' => 'api-docs', 'title' => 'API Documentation'],
            'test' => ['portal_page' => 'api-test', 'title' => 'API Tester'],
            'sdk' => ['portal_page' => 'api-docs', 'title' => 'SDK Download'],
            'category' => ['portal_page' => 'category', 'title' => 'API Category'],
            'tag' => ['portal_page' => 'tag', 'title' => 'API Tag'],
            'getting-started' => ['portal_page' => 'getting-started', 'title' => 'Getting Started'],
            'dashboard' => ['portal_page' => 'developer-dashboard', 'title' => 'Developer Dashboard'],
        ];

        return array_merge([
            'layout_mode' => 'developer-portal',
            'show_sidebar' => false,
            'size' => 'full',
            'body_class' => 'apiplatform-developer-portal-page',
        ], $map[$route] ?? $map['home']);
    }

    private function home(){
        $directory_url = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis');
        $updated_query = APIPlatform_Developer_Portal_Query::public_apis(['per_page' => 6, 'sort' => 'recent']);
        $published_query = APIPlatform_Developer_Portal_Query::recently_published_apis(6);
        $featured_query = APIPlatform_Developer_Portal_Query::featured_apis(6);
        $updated_cards = array_map(['APIPlatform_Developer_Portal_Query', 'api_card'], $updated_query->posts);
        $published_cards = array_map(['APIPlatform_Developer_Portal_Query', 'api_card'], $published_query->posts);
        $featured_cards = array_map(['APIPlatform_Developer_Portal_Query', 'api_card'], $featured_query->posts);
        $stats = APIPlatform_Developer_Portal_Query::public_directory_stats();

        return $this->layout(
            '<section class="apiplatform-portal-hero"><div><p class="apiplatform-portal-eyebrow">FreedomAPI Developer Portal</p><h1>Discover and integrate published APIs.</h1><p>Browse public APIs, read integration docs, copy examples, and test approved endpoints with your own key.</p><form class="apiplatform-portal-search" action="' . esc_url($directory_url) . '"><label><span>Search APIs</span><input name="search" type="search" placeholder="Search APIs, categories, or tags"></label><button type="submit">Search</button></form></div></section>' .
            '<section class="apiplatform-portal-section"><div class="apiplatform-portal-section-head"><h2>Live Platform Statistics</h2><a href="' . esc_url($directory_url) . '">Browse all APIs</a></div>' . $this->directory_stats($stats, true) . '</section>' .
            '<section class="apiplatform-portal-section"><div class="apiplatform-portal-section-head"><h2>Featured APIs</h2><a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['sort' => 'popular'])) . '">View popular APIs</a></div>' . $this->cards($featured_cards) . '</section>' .
            '<section class="apiplatform-portal-section"><div class="apiplatform-portal-section-head"><h2>Recently Published APIs</h2><a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['sort' => 'newest'])) . '">View newest APIs</a></div>' . $this->cards($published_cards) . '</section>' .
            '<section class="apiplatform-portal-section"><div class="apiplatform-portal-section-head"><h2>Recently Updated APIs</h2><a href="' . esc_url($directory_url) . '">Browse all APIs</a></div>' . $this->cards($updated_cards) . '</section>' .
            '<section class="apiplatform-portal-section"><div class="apiplatform-portal-section-head"><h2>Categories and Tags</h2><a href="' . esc_url($directory_url) . '">Explore directory</a></div>' . $this->taxonomy_counts() . '</section>' .
            $this->quickstart_panel()
        );
    }

    private function directory(array $overrides = []){
        $filters = APIPlatform_Developer_Portal_Query::sanitize_directory_filters(array_merge($_GET, $overrides));
        $query = APIPlatform_Developer_Portal_Query::public_apis($filters);
        $cards = array_map(['APIPlatform_Developer_Portal_Query', 'api_card'], $query->posts);
        $stats = APIPlatform_Developer_Portal_Query::public_directory_stats();
        $category_counts = APIPlatform_Developer_Portal_Query::category_counts();
        $tag_counts = APIPlatform_Developer_Portal_Query::tag_counts();
        $form = $this->directory_filters($filters, $category_counts, $tag_counts);
        $pagination = $this->pagination($query, $filters);
        $summary = sprintf(
            _n('%s public API', '%s public APIs', (int) $query->found_posts, 'apiplatform'),
            number_format_i18n((int) $query->found_posts)
        );

        return $this->layout(
            '<section class="apiplatform-portal-directory-hero"><p class="apiplatform-portal-eyebrow">Public API Directory</p><h1>Find APIs published on FreedomAPI.</h1><p>Search public APIs by name, category, tags, or description. Private and unlisted APIs are never shown here.</p>' . $this->directory_stats($stats) . '</section>' .
            '<section class="apiplatform-portal-section apiplatform-portal-directory"><div class="apiplatform-portal-section-head"><div><h2>Browse APIs</h2><p>' . esc_html($summary) . '</p></div></div>' . $form . $this->taxonomy_counts($category_counts, $tag_counts) . $this->cards($cards, true) . $pagination . '</section>'
        );
    }

    private function api_route($route){
        $slug = sanitize_title(get_query_var('apiplatform_portal_slug') ?: $this->route_var('slug') ?: ($_GET['portal_slug'] ?? ''));
        $api = APIPlatform_Developer_Portal_Query::resolve_api($slug, true);
        $preview = $this->preview_api();

        if (!$api && $preview) {
            $api = $preview;
        }

        if (!$api) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>API unavailable</h1><p>This API is not available in the Developer Portal.</p></section>', 'noindex');
        }

        $card = APIPlatform_Developer_Portal_Query::api_card($api);
        $is_preview = $preview && (int) $preview->ID === (int) $api->ID;

        if (class_exists('APIPlatform_Lifecycle_Policy') && !$is_preview && !APIPlatform_Lifecycle_Policy::public_route_allowed($card, $route)) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>API unavailable</h1><p>This API is not available in the Developer Portal.</p></section>', 'noindex');
        }

        if ($card['visibility'] === 'private' && !$is_preview) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>API unavailable</h1><p>This API is not available in the Developer Portal.</p></section>', 'noindex');
        }

        if (($card['status'] ?? 'active') !== 'active' && !$is_preview) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>API unavailable</h1><p>This API is not available in the Developer Portal.</p></section>', 'noindex');
        }

        if ($route === 'sdk') return $this->sdk_download($api, $card);
        if ($route === 'docs') return $this->docs($api, $card);
        if ($route === 'test') return $this->tester($api, $card);
        return $this->detail($api, $card);
    }

    private function detail($api, array $card){
        $docs = APIPlatform_Developer_Portal_Documentation::normalize($api);
        $warning = $this->deprecation_warning($card);

        return $this->layout(
            '<article class="apiplatform-portal-detail apiplatform-portal-api-landing">' .
            $warning .
            $this->api_detail_hero($card) .
            $this->api_detail_overview($card, $docs) .
            $this->api_quick_start($docs) .
            $this->endpoint_preview($docs, $card) .
            $this->integration_cards($card) .
            $this->related_apis($card) .
            '</article>',
            $card['visibility'] === 'unlisted' ? 'noindex' : 'index'
        );
    }

    private function docs($api, array $card){
        $version = isset($_GET['version']) ? sanitize_text_field(wp_unslash($_GET['version'])) : '';
        $docs = APIPlatform_Developer_Portal_Documentation::normalize($api, $version);

        if ($version !== '' && empty($docs['snapshot'])) {
            return $this->layout('<article class="apiplatform-portal-docs"><section class="apiplatform-portal-state"><h1>Documentation version unavailable.</h1><p>That documentation version is not published for this API.</p><div class="apiplatform-portal-actions"><a href="' . esc_url($card['docs_url']) . '">View current documentation</a></div></section></article>', $card['visibility'] === 'unlisted' ? 'noindex' : 'index');
        }

        $search = '<label class="apiplatform-portal-doc-search"><span>Search documentation</span><input type="search" data-portal-doc-search placeholder="Search endpoints, models, errors, SDKs, versions, tags"></label><div class="apiplatform-portal-doc-search-meta" data-portal-search-meta aria-live="polite"></div>';

        return $this->layout(
            '<article class="apiplatform-portal-docs apiplatform-portal-docs-pro">' .
            $this->docs_header($card) .
            $this->docs_version_selector($card, $docs) .
            '<section class="apiplatform-portal-doc-empty"><strong>Live documentation</strong><p>Endpoint tables, examples, responses, models, and SDK previews are generated from this API\'s canonical schema and existing metadata.</p></section>' .
            '<div class="apiplatform-portal-docs-layout">' .
            '<aside class="apiplatform-portal-doc-sidebar">' . $search . $this->docs_toc($docs, $card) . '</aside>' .
            '<div class="apiplatform-portal-docs-content">' .
            $this->docs_overview($docs, $card) .
            $this->docs_quick_start($docs, $card) .
            $this->docs_authentication($docs, $card) .
            $this->docs_endpoints($docs, $card) .
            $this->docs_examples($docs) .
            $this->docs_models($docs) .
            $this->docs_sdk_center($docs, $card) .
            $this->docs_errors($docs) .
            $this->docs_gateway_errors() .
            $this->docs_rate_limits($docs) .
            $this->docs_try_it($card) .
            $this->docs_workspace_sections($docs) .
            $this->docs_changelog($docs) .
            $this->docs_links($card) .
            '</div></div></article>',
            $card['visibility'] === 'unlisted' ? 'noindex' : 'index'
        );
    }

    private function docs_header(array $card){
        $actions = '<div class="apiplatform-portal-actions"><a href="' . esc_url($card['url']) . '">Back to API overview</a>';
        if (!empty($card['allow_public_tester'])) {
            $actions .= '<a href="' . esc_url($card['test_url']) . '">Try API</a>';
        }
        $actions .= $this->sdk_download_links($card);
        $actions .= '<section class="apiplatform-portal-code-block apiplatform-portal-copy-inline"><button type="button" data-portal-copy>Copy Base URL</button><code>' . esc_html($card['endpoint_url']) . '</code></section></div>';

        return '<section class="apiplatform-portal-detail-hero"><div class="apiplatform-portal-detail-title"><span class="apiplatform-portal-api-icon">' . esc_html($card['icon']) . '</span><div><p class="apiplatform-portal-eyebrow">Public Documentation</p><h1>' . esc_html($card['title']) . '</h1><p>' . esc_html($card['summary'] ?: 'No summary published yet.') . '</p></div></div><div class="apiplatform-portal-card-meta"><span>' . esc_html($card['public_status_label']) . '</span><span>' . esc_html($card['version_label']) . '</span><span>' . esc_html($card['authentication_label']) . '</span></div><div class="apiplatform-portal-base-endpoint"><span>Base URL</span><code>' . esc_html($card['endpoint_url']) . '</code></div>' . $actions . '</section>';
    }

    private function sdk_download_links(array $card){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return '';
        }
        if (($card['visibility'] ?? '') !== 'public') {
            return '';
        }
        $settings = APIPlatform_SDK_Generator_Service::settings($card['id']);
        if (empty($settings['enabled'])) {
            return '';
        }

        $html = '';
        foreach (APIPlatform_SDK_Generator_Service::languages() as $language => $label) {
            if (!in_array($language, $settings['allowed_languages'], true)) {
                continue;
            }
            $html .= '<a href="' . esc_url(trailingslashit($card['url']) . 'sdk/' . sanitize_key($language) . '.zip') . '">Download ' . esc_html($label) . ' SDK</a>';
        }
        return $html;
    }

    private function sdk_download($api, array $card){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>SDK unavailable</h1><p>SDK downloads are not available for this API.</p></section>', 'noindex');
        }
        if (($card['visibility'] ?? '') !== 'public') {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>SDK unavailable</h1><p>SDK downloads are available only for public APIs.</p></section>', 'noindex');
        }

        $settings = APIPlatform_SDK_Generator_Service::settings($api->ID);
        $language = sanitize_key(get_query_var('apiplatform_portal_sdk_language') ?: $this->route_var('sdk_language'));
        if (empty($settings['enabled']) || !in_array($language, $settings['allowed_languages'], true)) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>SDK unavailable</h1><p>That SDK language is not enabled for this API.</p></section>', 'noindex');
        }

        $archive = APIPlatform_SDK_Generator_Service::archive($api->ID, 0, $language, ['version' => $card['version_label']]);
        if (empty($archive['ok'])) {
            status_header(500);
            return $this->layout('<section class="apiplatform-portal-state"><h1>SDK unavailable</h1><p>' . esc_html($archive['message'] ?? 'SDK archive could not be generated.') . '</p></section>', 'noindex');
        }

        if (class_exists('APIPlatform_Endpoint_Schema_Service')) {
            APIPlatform_Endpoint_Schema_Service::record_event($api->ID, 0, 'sdk_downloaded', 'Public SDK downloaded: ' . $archive['filename'] . '.');
        }

        nocache_headers();
        header('Content-Type: ' . $archive['content_type']);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($archive['filename']) . '"');
        header('Content-Length: ' . filesize($archive['path']));
        readfile($archive['path']);
        APIPlatform_SDK_Generator_Service::delete_dir($archive['dir']);
        exit;
    }

    private function docs_toc(array $docs, array $card){
        $html = '<nav class="apiplatform-portal-toc" aria-label="Documentation sections"><a href="#overview">Overview</a><a href="#quick-start">Quick Start</a><a href="#authentication">Authentication</a>';
        $html .= '<details open><summary>Endpoints</summary><div>';
        foreach ($docs['endpoints'] as $endpoint) {
            $method = strtoupper($endpoint['method'] ?? (($endpoint['methods'][0] ?? 'GET')));
            $html .= '<a href="#' . esc_attr($endpoint['id']) . '"><span>' . esc_html($method) . '</span>' . esc_html($endpoint['title']) . '</a>';
        }
        $html .= '</div></details><a href="#examples">Examples</a><a href="#models">Models</a><a href="#sdk-center">SDK Center</a><a href="#errors">Errors</a><a href="#gateway-errors">Gateway Errors</a><a href="#rate-limits">Rate Limits</a>';
        if (!empty($card['allow_public_tester'])) {
            $html .= '<a href="#try-it">Try It</a>';
        }
        $workspace = $docs['workspace'] ?? [];
        if (!empty($workspace['sdk_examples_markdown'])) {
            $html .= '<a href="#sdk-examples">SDK Examples</a>';
        }
        if (!empty($workspace['faq_markdown'])) {
            $html .= '<a href="#faq">FAQ</a>';
        }
        if (!empty($workspace['migration_guide_markdown'])) {
            $html .= '<a href="#migration-guide">Migration</a>';
        }
        if (!empty($docs['all_releases'])) {
            $html .= '<a href="#changelog">Changelog</a>';
        }
        if (!empty($card['support_url']) || !empty($card['changelog'])) {
            $html .= '<a href="#resources">Resources</a>';
        }
        return $html . '</nav>';
    }

    private function docs_version_selector(array $card, array $docs){
        $snapshots = $docs['snapshots'] ?? [];
        if (!$snapshots) {
            return '';
        }

        $html = '<section class="apiplatform-portal-doc-version-bar"><div><span>Documentation version</span><strong>' . esc_html($docs['active_version'] ?? $card['version_label']) . '</strong></div><form method="get">';
        $html .= '<label for="apiplatform-doc-version">Select version</label><select id="apiplatform-doc-version" name="version">';
        $seen_versions = [];
        foreach ($snapshots as $snapshot) {
            $value = (string) ($snapshot['version_label'] ?? '');
            if ($value === '' || isset($seen_versions[$value])) {
                continue;
            }
            $seen_versions[$value] = true;
            $html .= '<option value="' . esc_attr($value) . '"' . selected($docs['active_version'] ?? '', $value, false) . '>' . esc_html($value . ' - ' . ucfirst($snapshot['documentation_state'] ?? 'published')) . '</option>';
        }
        $html .= '</select><button type="submit">View</button></form></section>';
        return $html;
    }

    private function docs_overview(array $docs, array $card){
        $workspace = $docs['workspace'] ?? [];
        $description = trim((string) ($workspace['overview_markdown'] ?? ''));
        $description_html = $description !== ''
            ? $this->safe_markdown($description)
            : '<p>' . esc_html($card['description'] ?: 'Not documented') . '</p>';
        $notice = $this->deprecation_warning($card);

        return '<section id="overview" class="apiplatform-portal-panel" data-doc-section>' . $notice . '<h2>Overview</h2>' . $description_html . $this->api_metadata($card) . '</section>';
    }

    private function docs_quick_start(array $docs, array $card){
        $endpoint = $docs['endpoints'][0] ?? [];
        $request = $this->code_example_code($docs['code_examples']['curl'] ?? '');

        $steps = [
            ['Create Application', 'Create or select a Developer Application from your FreedomAPI account.'],
            ['Generate Key', 'Generate an application key and copy it once. Store it securely.'],
            ['First API Request', 'Send the request below with X-API-Key: YOUR_API_KEY.'],
            ['Review Response', 'Check status, request ID, response body, and documented errors.'],
        ];

        $html = '<section id="quick-start" class="apiplatform-portal-panel apiplatform-portal-doc-quickstart" data-doc-section><h2>Quick Start</h2><ol>';
        foreach ($steps as $step) {
            $html .= '<li><strong>' . esc_html($step[0]) . '</strong><span>' . esc_html($step[1]) . '</span></li>';
        }
        $html .= '</ol>';
        if ($request !== '') {
            $html .= '<section class="apiplatform-portal-code-block" data-json-viewer><h3>First Request</h3><button type="button" data-portal-copy>Copy Request</button><pre><code>' . esc_html($request) . '</code></pre></section>';
        }
        if (!empty($card['allow_public_tester'])) {
            $html .= '<p><a href="' . esc_url(add_query_arg('endpoint', sanitize_key($endpoint['id'] ?? 'default-endpoint'), $card['test_url'])) . '">Open this endpoint in Try It</a></p>';
        }
        return $html . '</section>';
    }

    private function deprecation_warning(array $card){
        if (($card['lifecycle'] ?? '') !== 'deprecated' && empty($card['deprecation_notice'])) {
            return '';
        }

        $html = '<div class="apiplatform-portal-warning"><strong>Deprecated</strong><p>' . esc_html($card['deprecation_notice'] ?: 'This API remains available but should not be used for new integrations.') . '</p>';

        if (!empty($card['sunset_date'])) {
            $html .= '<p>Sunset date: ' . esc_html($card['sunset_date']) . '</p>';
        }

        if (!empty($card['migration_url'])) {
            $html .= '<p><a href="' . esc_url($card['migration_url']) . '" rel="nofollow">Migration guide</a></p>';
        }

        return $html . '</div>';
    }

    private function docs_authentication(array $docs, array $card){
        $auth = $docs['authentication'] ?? [];
        $workspace = $docs['workspace'] ?? [];
        $intro = !empty($workspace['authentication_markdown'])
            ? $this->safe_markdown($workspace['authentication_markdown'])
            : '<p>' . esc_html($auth['recommended'] ?? 'Send your API key with the X-API-Key request header.') . '</p>';
        return '<section id="authentication" class="apiplatform-portal-panel" data-doc-section><h2>Authentication Guide</h2>' . $intro .
            '<section class="apiplatform-portal-code-block"><h3>Required Header</h3><button type="button" data-portal-copy>Copy Header</button><pre><code>' . esc_html($auth['header'] ?? 'X-API-Key: YOUR_API_KEY') . '</code></pre></section>' .
            '<section class="apiplatform-portal-code-block"><h3>Legacy Query URL</h3><button type="button" data-portal-copy>Copy Legacy URL</button><pre><code>' . esc_html($auth['legacy_url'] ?? '') . '</code></pre></section>' .
            '<ul class="apiplatform-portal-doc-checklist"><li>Recommended: send keys with <code>X-API-Key</code>.</li><li>Do not put API keys in client-side repositories, screenshots, logs, or public URLs.</li><li>401 means the request did not include credentials. 403 means credentials were rejected or not allowed.</li><li>429 means rate limits were exceeded.</li></ul><p>For application credentials, create a Developer Application, add this API, generate an application key, store it securely, and send it with <code>X-API-Key: YOUR_APPLICATION_KEY</code>.</p></section>';
    }

    private function docs_endpoints(array $docs, array $card){
        $html = '';
        foreach ($docs['endpoints'] as $endpoint) {
            $methods = $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET'];
            $html .= '<section id="' . esc_attr($endpoint['id']) . '" class="apiplatform-portal-doc-endpoint apiplatform-portal-panel" data-doc-section>';
            $endpoint_actions = '<a href="#' . esc_attr($endpoint['id']) . '">#</a>';
            if (!empty($card['allow_public_tester'])) {
                $endpoint_actions .= '<a href="' . esc_url(add_query_arg('endpoint', sanitize_key($endpoint['id']), $card['test_url'])) . '">Try API</a>';
            }
            $html .= '<div class="apiplatform-portal-doc-endpoint-head"><div><h2>' . esc_html($endpoint['title']) . '</h2><p>' . esc_html($endpoint['description'] ?: 'Not documented') . '</p></div><div class="apiplatform-portal-doc-endpoint-actions">' . $endpoint_actions . '</div></div>';
            $html .= '<div class="apiplatform-portal-endpoint-method-line"><span>' . esc_html(implode(', ', $methods)) . '</span><code>' . esc_html($endpoint['path']) . '</code></div>';
            $html .= '<dl class="apiplatform-portal-endpoint-meta"><div><dt>Authentication</dt><dd>' . esc_html($card['authentication_label'] ?: 'API Key') . '</dd></div><div><dt>Version</dt><dd>' . esc_html($endpoint['version'] ?? $card['version_label']) . '</dd></div><div><dt>Rate limits</dt><dd>' . esc_html($docs['rate_limits'] ?? 'Not documented') . '</dd></div><div><dt>Status</dt><dd>' . (!empty($endpoint['deprecated']) ? 'Deprecated' : esc_html($card['public_status_label'])) . '</dd></div></dl>';
            if (!empty($endpoint['deprecated'])) {
                $html .= '<div class="apiplatform-portal-warning"><strong>Deprecated endpoint</strong><p>This endpoint remains documented for compatibility. Prefer newer alternatives when available.</p></div>';
            }
            $html .= '<section class="apiplatform-portal-code-block"><h3>Endpoint URL</h3><button type="button" data-portal-copy>Copy Endpoint</button><pre><code>' . esc_html($endpoint['url'] ?? '') . '</code></pre></section>';
            $html .= $this->docs_parameter_table($endpoint['parameters'] ?? []);
            $html .= $this->docs_request_body($endpoint['body_example'] ?? []);
            $html .= $this->docs_response_table($endpoint['response_codes'] ?? []);
            $html .= $this->docs_model_refs($endpoint['models'] ?? []);
            if (trim((string) ($endpoint['example_response'] ?? '')) !== '') {
                $html .= '<section class="apiplatform-portal-code-block" data-json-viewer><h3>Example Response</h3><button type="button" data-portal-copy>Copy Response</button><button type="button" data-json-raw>Raw View</button><button type="button" data-json-download="response-' . esc_attr($endpoint['id']) . '.json">Download JSON</button><pre><code>' . esc_html($endpoint['example_response']) . '</code></pre></section>';
            }
            $html .= '</section>';
        }
        return $html;
    }

    private function docs_response_table(array $responses){
        if (!$responses) {
            return '<div class="apiplatform-portal-doc-empty"><h3>Responses</h3><p>Not documented</p></div>';
        }

        $html = '<div class="apiplatform-portal-doc-table-wrap"><table class="apiplatform-portal-doc-table"><thead><tr><th>Status</th><th>Description</th><th>Model</th></tr></thead><tbody>';
        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            $html .= '<tr><td>' . esc_html((string) ($response['status'] ?? '')) . '</td><td>' . esc_html($response['description'] ?? 'Not documented') . '</td><td>' . esc_html($response['model'] ?? 'Not documented') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function docs_model_refs(array $models){
        if (!$models) {
            return '';
        }

        $html = '<div class="apiplatform-portal-doc-empty"><h3>Models Used</h3><p>';
        $html .= esc_html(implode(', ', array_map('sanitize_text_field', $models)));
        return $html . '</p></div>';
    }

    private function docs_parameter_table(array $parameters){
        if (!$parameters) {
            return '<div class="apiplatform-portal-doc-empty"><h3>Parameters</h3><p>Not documented</p></div>';
        }

        $html = '<div class="apiplatform-portal-doc-table-wrap"><table class="apiplatform-portal-doc-table"><thead><tr><th>Name</th><th>Location</th><th>Type</th><th>Required</th><th>Default</th><th>Description</th><th>Example</th><th>Validation</th></tr></thead><tbody>';
        foreach ($parameters as $parameter) {
            $validation = $this->parameter_validation_label($parameter);
            $html .= '<tr><td><code>' . esc_html($parameter['name'] ?? '') . '</code></td><td>' . esc_html($parameter['location'] ?? $parameter['in'] ?? 'Not documented') . '</td><td>' . esc_html($parameter['type'] ?? 'Not documented') . '</td><td>' . (!empty($parameter['required']) ? 'Yes' : 'No') . '</td><td>' . esc_html($parameter['default'] ?? '') . '</td><td>' . esc_html($parameter['description'] ?? 'Not documented') . '</td><td>' . esc_html($parameter['example'] ?? '') . '</td><td>' . esc_html($validation) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function parameter_validation_label(array $parameter){
        $parts = [];
        foreach (['min', 'max', 'minLength', 'maxLength', 'pattern', 'format'] as $key) {
            if (isset($parameter[$key]) && $parameter[$key] !== '') {
                $parts[] = $key . ': ' . sanitize_text_field((string) $parameter[$key]);
            }
        }
        if (!empty($parameter['enum']) && is_array($parameter['enum'])) {
            $parts[] = 'enum: ' . implode(', ', array_map('sanitize_text_field', $parameter['enum']));
        }
        return $parts ? implode('; ', $parts) : 'Not documented';
    }

    private function docs_request_body(array $body){
        if (empty($body['enabled'])) {
            return '<div class="apiplatform-portal-doc-empty"><h3>Request Body</h3><p>' . esc_html($body['source'] ?? 'Not documented') . '</p></div>';
        }

        return '<section class="apiplatform-portal-code-block" data-json-viewer><h3>Request Body</h3><p>Content type: <code>' . esc_html($body['content_type'] ?? 'application/json') . '</code></p><button type="button" data-portal-copy>Copy Request Body</button><button type="button" data-json-raw>Raw View</button><button type="button" data-json-download="request-body.json">Download JSON</button><pre><code>' . esc_html($body['example'] ?? 'Not documented') . '</code></pre></section>';
    }

    private function docs_examples(array $docs){
        $examples = $docs['code_examples'] ?? [];
        if (!$examples) {
            return '<section id="examples" class="apiplatform-portal-panel" data-doc-section><h2>Examples</h2><p>Not documented</p></section>';
        }

        $tabs = '<div class="apiplatform-portal-doc-tabs" data-portal-doc-tabs><div class="apiplatform-portal-doc-tab-list" role="tablist" aria-label="Code examples">';
        $panels = '';
        $first = true;
        foreach ($examples as $key => $example) {
            $example = is_array($example) ? $example : ['title' => ucfirst((string) $key), 'code' => (string) $example];
            $tab_id = 'portal-doc-tab-' . sanitize_html_class($key);
            $panel_id = 'portal-doc-panel-' . sanitize_html_class($key);
            $title = $example['title'] ?? ucfirst((string) $key);
            $tabs .= '<button type="button" id="' . esc_attr($tab_id) . '" role="tab" aria-selected="' . ($first ? 'true' : 'false') . '" aria-controls="' . esc_attr($panel_id) . '" class="' . ($first ? 'is-active' : '') . '">' . esc_html($title) . '</button>';
            $panels .= '<section id="' . esc_attr($panel_id) . '" role="tabpanel" aria-labelledby="' . esc_attr($tab_id) . '" class="apiplatform-portal-doc-tab-panel' . ($first ? ' is-active' : '') . '"' . ($first ? '' : ' hidden') . '><section class="apiplatform-portal-code-block"><button type="button" data-portal-copy>Copy Request Example</button><pre><code>' . esc_html($example['code'] ?? '') . '</code></pre></section></section>';
            $first = false;
        }

        return '<section id="examples" class="apiplatform-portal-panel" data-doc-section><h2>Code Examples</h2>' . $tabs . '</div>' . $panels . '</div></section>';
    }

    private function docs_models(array $docs){
        $models = $docs['models'] ?? [];
        $html = '<section id="models" class="apiplatform-portal-panel apiplatform-portal-models" data-doc-section><h2>Models</h2>';

        if (!$models) {
            return $html . '<p>Not documented</p></section>';
        }

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $fields = is_array($model['fields'] ?? null) ? $model['fields'] : [];
            $html .= '<details class="apiplatform-portal-model" open><summary><strong>' . esc_html($model['name'] ?? 'Model') . '</strong><span>' . esc_html($model['description'] ?? 'No description') . '</span></summary>';
            if (!$fields) {
                $html .= '<p>Properties not documented.</p>';
            } else {
                $html .= '<div class="apiplatform-portal-doc-table-wrap"><table class="apiplatform-portal-doc-table"><thead><tr><th>Property</th><th>Type</th><th>Required</th><th>Nullable</th><th>Enum / Reference</th><th>Description</th></tr></thead><tbody>';
                foreach ($fields as $field) {
                    $enum = !empty($field['enum']) && is_array($field['enum']) ? implode(', ', array_map('sanitize_text_field', $field['enum'])) : '';
                    $reference = sanitize_text_field($field['ref'] ?? $field['reference'] ?? '');
                    $html .= '<tr><td><code>' . esc_html($field['name'] ?? '') . '</code></td><td>' . esc_html($field['type'] ?? 'Not documented') . '</td><td>' . (!empty($field['required']) ? 'Yes' : 'No') . '</td><td>' . (!empty($field['nullable']) ? 'Yes' : 'No') . '</td><td>' . esc_html($enum ?: ($reference ?: 'Not documented')) . '</td><td>' . esc_html($field['description'] ?? 'Not documented') . '</td></tr>';
                }
                $html .= '</tbody></table></div>';
            }
            $html .= '</details>';
        }

        return $html . '</section>';
    }

    private function docs_sdk_center(array $docs, array $card){
        if (!class_exists('APIPlatform_SDK_Generator_Service')) {
            return '<section id="sdk-center" class="apiplatform-portal-panel apiplatform-portal-sdk-center" data-doc-section><h2>SDK Center</h2><p>SDK generator is unavailable.</p></section>';
        }

        $settings = APIPlatform_SDK_Generator_Service::settings($card['id']);
        $examples = $docs['code_examples'] ?? [];
        $languages = [
            'javascript' => 'JavaScript',
            'node' => 'Node.js',
            'python' => 'Python',
            'php' => 'PHP',
            'csharp' => 'C#',
        ];
        $html = '<section id="sdk-center" class="apiplatform-portal-panel apiplatform-portal-sdk-center" data-doc-section><h2>SDK Center</h2><p>SDK downloads reuse the FreedomAPI SDK generator and published canonical schema.</p>';
        if (empty($settings['enabled'])) {
            return $html . '<p>SDK downloads are not enabled for this API.</p></section>';
        }

        $html .= '<div class="apiplatform-portal-doc-tabs" data-portal-doc-tabs><div class="apiplatform-portal-doc-tab-list" role="tablist" aria-label="SDK languages">';
        $panels = '';
        $first = true;
        foreach ($languages as $key => $label) {
            if (!in_array($key, $settings['allowed_languages'], true)) {
                continue;
            }
            $tab_id = 'portal-sdk-tab-' . sanitize_html_class($key);
            $panel_id = 'portal-sdk-panel-' . sanitize_html_class($key);
            $html .= '<button type="button" id="' . esc_attr($tab_id) . '" role="tab" aria-selected="' . ($first ? 'true' : 'false') . '" aria-controls="' . esc_attr($panel_id) . '" class="' . ($first ? 'is-active' : '') . '">' . esc_html($label) . '</button>';
            $install = $this->sdk_install_command($key, $card);
            $preview = $this->code_example_code($examples[$key] ?? '');
            $download = trailingslashit($card['url']) . 'sdk/' . sanitize_key($key) . '.zip';
            $panels .= '<section id="' . esc_attr($panel_id) . '" role="tabpanel" aria-labelledby="' . esc_attr($tab_id) . '" class="apiplatform-portal-doc-tab-panel' . ($first ? ' is-active' : '') . '"' . ($first ? '' : ' hidden') . '><section class="apiplatform-portal-code-block"><h3>Install</h3><button type="button" data-portal-copy>Copy Install Command</button><pre><code>' . esc_html($install) . '</code></pre></section><section class="apiplatform-portal-code-block"><h3>Preview</h3><button type="button" data-portal-copy>Copy Snippet</button><pre><code>' . esc_html($preview ?: 'Preview generated from SDK package.') . '</code></pre></section><p><a href="' . esc_url($download) . '">Download ' . esc_html($label) . ' SDK</a></p></section>';
            $first = false;
        }
        if ($first) {
            return '<section id="sdk-center" class="apiplatform-portal-panel apiplatform-portal-sdk-center" data-doc-section><h2>SDK Center</h2><p>No SDK languages are enabled for this API.</p></section>';
        }
        return $html . '</div>' . $panels . '</div></section>';
    }

    private function sdk_install_command($language, array $card){
        $slug = sanitize_title($card['slug'] ?? 'freedomapi');
        return [
            'javascript' => 'npm install ./' . $slug . '-javascript-sdk.zip',
            'node' => 'npm install ./' . $slug . '-node-sdk.zip',
            'python' => 'pip install ./' . $slug . '-python-sdk.zip',
            'php' => 'composer require freedomapi/' . $slug,
            'csharp' => 'dotnet add package FreedomAPI.' . str_replace('-', '', ucwords($slug, '-')),
        ][$language] ?? 'Download the SDK ZIP for this language.';
    }

    private function docs_errors(array $docs){
        $errors = $docs['common_errors'] ?? [];
        $html = '<section id="errors" class="apiplatform-portal-panel" data-doc-section><h2>Status and Error Responses</h2>';
        if (!$errors) {
            return $html . '<p>Not documented</p></section>';
        }
        $html .= '<div class="apiplatform-portal-doc-table-wrap"><table class="apiplatform-portal-doc-table"><thead><tr><th>Status</th><th>Code</th><th>Meaning</th><th>Resolution</th></tr></thead><tbody>';
        foreach ($errors as $error) {
            $html .= '<tr><td>' . esc_html((string) ($error['status'] ?? '')) . '</td><td><code>' . esc_html($error['code'] ?? '') . '</code></td><td>' . esc_html($error['meaning'] ?? '') . '</td><td>' . esc_html($error['resolution'] ?? 'Not documented') . '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private function docs_gateway_errors(){
        $errors = [
            [400, 'invalid_request', 'The route, method, parameters, or JSON body could not be accepted.', 'Check endpoint path, method, and required fields.'],
            [401, 'authentication_failed', 'No API key was provided or the authentication format is invalid.', 'Send X-API-Key: YOUR_API_KEY.'],
            [403, 'api_disabled', 'The API, version, key, or application access is disabled or forbidden.', 'Confirm access and API status.'],
            [404, 'api_not_found', 'The API, version, or endpoint does not exist or is not publicly routable.', 'Check the copied endpoint URL and version.'],
            [429, 'rate_limit_exceeded', 'The request exceeded an API or key rate limit.', 'Back off and retry after the current window.'],
            [500, 'internal_gateway_error', 'The gateway could not complete the runtime pipeline.', 'Use the request ID when contacting support.'],
        ];
        $html = '<section id="gateway-errors" class="apiplatform-portal-panel" data-doc-section><h2>Gateway Error Reference</h2><div class="apiplatform-portal-doc-table-wrap"><table class="apiplatform-portal-doc-table"><thead><tr><th>Status</th><th>Error code</th><th>Description</th><th>Suggested fix</th><th>Example</th></tr></thead><tbody>';
        foreach ($errors as $error) {
            $example = wp_json_encode(['status' => $error[0], 'error' => ['code' => $error[1], 'message' => $error[2]], 'request_id' => 'REQUEST_ID'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $html .= '<tr><td>' . esc_html((string) $error[0]) . '</td><td><code>' . esc_html($error[1]) . '</code></td><td>' . esc_html($error[2]) . '</td><td>' . esc_html($error[3]) . '</td><td><pre><code>' . esc_html($example) . '</code></pre></td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private function docs_rate_limits(array $docs){
        $rate = $docs['rate_limit'] ?? [];
        $html = '<section id="rate-limits" class="apiplatform-portal-panel" data-doc-section><h2>Rate Limits</h2><p>' . esc_html($rate['summary'] ?? ($docs['rate_limits'] ?? 'Not documented')) . '</p>';
        if (!empty($rate['scope'])) {
            $html .= '<p>Scope: <code>' . esc_html($rate['scope']) . '</code></p>';
        }
        if (!empty($rate['window'])) {
            $html .= '<p>Window: ' . esc_html($rate['window']) . '</p>';
        }
        return $html . '<p>Exceeded requests return <code>' . esc_html($rate['status'] ?? '429 rate_limit_exceeded') . '</code>.</p></section>';
    }

    private function docs_try_it(array $card){
        if (empty($card['allow_public_tester'])) {
            return '<section id="try-it" class="apiplatform-portal-panel" data-doc-section><h2>Try It</h2><p>Testing unavailable.</p></section>';
        }

        return '<section id="try-it" class="apiplatform-portal-panel" data-doc-section><h2>Try It</h2><p>Open the interactive tester to edit parameters, headers, authentication, and request body. Responses include status, headers, timing, request ID, and formatted body.</p><div class="apiplatform-portal-actions"><a href="' . esc_url($card['test_url']) . '">Open API Tester</a></div></section>';
    }

    private function docs_workspace_sections(array $docs){
        $workspace = $docs['workspace'] ?? [];
        $html = '';

        if (!empty($workspace['sdk_examples_markdown'])) {
            $html .= '<section id="sdk-examples" class="apiplatform-portal-panel" data-doc-section><h2>SDK Examples</h2>' . $this->safe_markdown($workspace['sdk_examples_markdown']) . '</section>';
        }

        if (!empty($workspace['faq_markdown'])) {
            $html .= '<section id="faq" class="apiplatform-portal-panel" data-doc-section><h2>FAQ</h2>' . $this->safe_markdown($workspace['faq_markdown']) . '</section>';
        }

        if (!empty($workspace['migration_guide_markdown'])) {
            $html .= '<section id="migration-guide" class="apiplatform-portal-panel" data-doc-section><h2>Migration Guide</h2>' . $this->safe_markdown($workspace['migration_guide_markdown']) . '</section>';
        }

        return $html;
    }

    private function docs_changelog(array $docs){
        $releases = $docs['all_releases'] ?? [];
        if (!$releases) {
            return '';
        }

        $html = '<section id="changelog" class="apiplatform-portal-panel apiplatform-portal-changelog" data-doc-section><h2>Changelog</h2>';
        foreach ($releases as $release) {
            $type = ucfirst((string) ($release['release_type'] ?? 'changed'));
            $html .= '<article><div><span>' . esc_html($type) . '</span><strong>' . esc_html($release['release_title'] ?: $release['version_label']) . '</strong></div>';
            $html .= '<p>' . esc_html($release['summary'] ?: 'Not documented') . '</p>';
            if (!empty($release['entries'])) {
                $html .= '<pre><code>' . esc_html($release['entries']) . '</code></pre>';
            }
            if (!empty($release['breaking_change'])) {
                $html .= '<p class="apiplatform-portal-warning-inline">Breaking change</p>';
            }
            if (!empty($release['migration_notes'])) {
                $html .= '<p>' . esc_html($release['migration_notes']) . '</p>';
            }
            $html .= '<time>' . esc_html($release['release_date'] ?: '') . '</time></article>';
        }
        return $html . '</section>';
    }

    private function safe_markdown($text){
        return class_exists('APIPlatform_Documentation_Workspace')
            ? APIPlatform_Documentation_Workspace::markdown($text)
            : wpautop(esc_html($text));
    }

    private function docs_links(array $card){
        if (empty($card['support_url']) && empty($card['changelog'])) {
            return '';
        }

        $html = '<section id="resources" class="apiplatform-portal-panel" data-doc-section><h2>Resources</h2><div class="apiplatform-portal-integration-grid">';
        if ($card['changelog']) {
            $html .= '<article><h3>Changelog</h3><p>' . esc_html($card['changelog']) . '</p></article>';
        }
        if ($card['support_url']) {
            $html .= '<article><h3>Support</h3><p>Get help from the publisher.</p><a href="' . esc_url($card['support_url']) . '" rel="nofollow">Contact support</a></article>';
        }
        return $html . '</div></section>';
    }

    private function api_detail_hero(array $card){
        $tags = '';
        $eyebrow = ($card['visibility'] === 'public' && $card['publisher_name'])
            ? $card['publisher_name']
            : 'FreedomAPI API';

        foreach ($card['tags'] as $tag) {
            $tags .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['tag' => sanitize_title($tag)])) . '">' . esc_html($tag) . '</a>';
        }

        $summary = $card['summary'] ?: 'No public summary has been provided.';
        $actions = '<div class="apiplatform-portal-actions apiplatform-portal-detail-actions">';
        $endpoint_actions = '<div class="apiplatform-portal-endpoint-actions">';
        $endpoint_actions .= '<section class="apiplatform-portal-code-block apiplatform-portal-copy-inline"><button type="button" data-portal-copy>Copy Endpoint</button><code>' . esc_html($card['endpoint_url']) . '</code></section>';
        $endpoint_actions .= '<section class="apiplatform-portal-code-block apiplatform-portal-copy-inline"><button type="button" data-portal-copy>Copy Base URL</button><code>' . esc_html($card['endpoint_url']) . '</code></section>';

        $actions .= '<a href="' . esc_url($card['docs_url']) . '">View Documentation</a>';
        $endpoint_actions .= '<a href="' . esc_url($card['docs_url']) . '">View Documentation</a>';

        if (!empty($card['allow_public_tester'])) {
            $actions .= '<a href="' . esc_url($card['test_url']) . '">Try API</a>';
            $endpoint_actions .= '<a href="' . esc_url($card['test_url']) . '">Test API</a>';
        } else {
            $actions .= '<span class="apiplatform-portal-action-disabled">Testing unavailable.</span>';
        }

        $actions .= $this->favorite_form($card);
        $actions .= '</div>';
        $endpoint_actions .= '</div>';

        return '<section class="apiplatform-portal-detail-hero"><div class="apiplatform-portal-detail-title"><span class="apiplatform-portal-api-icon">' . esc_html($card['icon']) . '</span><div><p class="apiplatform-portal-eyebrow">' . esc_html($eyebrow) . '</p><h1>' . esc_html($card['title']) . '</h1><p>' . esc_html($summary) . '</p></div></div>' .
            '<div class="apiplatform-portal-card-meta"><span>' . esc_html($card['public_status_label']) . '</span><span>' . esc_html($card['version_label']) . '</span><span>' . esc_html($card['category_label'] ?: 'Other') . '</span><span>' . esc_html($card['authentication_label']) . '</span></div>' .
            ($tags ? '<div class="apiplatform-portal-tags">' . $tags . '</div>' : '') .
            '<div class="apiplatform-portal-base-endpoint"><span>Endpoint URL</span><code>' . esc_html($card['endpoint_url']) . '</code>' . $endpoint_actions . '</div>' .
            $actions .
            '</section>';
    }

    private function api_detail_overview(array $card, array $docs){
        $use_cases = [
            'Build ' . strtolower($card['category_label'] ?: 'API') . ' workflows with a published FreedomAPI endpoint.',
            'Prototype integrations against stable examples before wiring production code.',
            'Use public documentation and tester tools to validate request behavior.',
        ];

        $publisher = ($card['visibility'] === 'public' && $card['publisher_name']) ? '<div><dt>Publisher</dt><dd>' . esc_html($card['publisher_name']) . '</dd></div>' : '';
        $category = $card['category_label'] ?: 'Not provided';
        $rate_limits = $docs['rate_limits'] ?: 'Not provided';

        $html = '<section class="apiplatform-portal-panel apiplatform-portal-overview"><h2>Overview</h2><p>' . nl2br(esc_html($card['description'] ?: 'No public overview has been published yet.')) . '</p><h3>Use cases</h3><ul>';
        foreach ($use_cases as $use_case) {
            $html .= '<li>' . esc_html($use_case) . '</li>';
        }

        $html .= '</ul><dl class="apiplatform-portal-meta-list">' .
            '<div><dt>Authentication</dt><dd>' . esc_html($card['authentication_label'] ?: 'Not provided') . '</dd><small>Uses X-API-Key authentication.</small></div>' .
            '<div><dt>Version</dt><dd>' . esc_html($card['version_label'] ?: 'Not provided') . '</dd><small>Published API version.</small></div>' .
            '<div><dt>Rate limits</dt><dd>' . esc_html($rate_limits) . '</dd><small>Exceeded requests return 429.</small></div>' .
            '<div><dt>Status</dt><dd>' . esc_html($card['public_status_label'] ?: 'Not provided') . '</dd><small>Current public lifecycle state.</small></div>' .
            '<div><dt>Runtime</dt><dd>' . esc_html($this->runtime_label($card['runtime_type'] ?? 'internal')) . '</dd><small>Gateway execution mode.</small></div>' .
            '<div><dt>Updated</dt><dd>' . esc_html($card['updated_label'] ?: 'Not provided') . '</dd><small>Last published metadata update.</small></div>' .
            '<div><dt>Category</dt><dd>' . esc_html($category) . '</dd><small>Directory grouping.</small></div>' .
            $publisher .
            '</dl></section>';

        return $html;
    }

    private function api_quick_start(array $docs){
        $examples = [
            'curl' => $this->code_example_code($docs['code_examples']['curl'] ?? ''),
            'fetch()' => $this->code_example_code($docs['code_examples']['javascript'] ?? ''),
            'PHP' => $this->code_example_code($docs['code_examples']['php'] ?? ''),
            'Python' => $this->code_example_code($docs['code_examples']['python'] ?? ''),
            'Node.js' => $this->node_example_code($docs),
        ];

        $tabs = '';
        $panels = '';
        $first = true;

        foreach ($examples as $label => $code) {
            if ($code === '') {
                continue;
            }

            $tab_id = 'apiplatform-quickstart-tab-' . sanitize_html_class($label);
            $panel_id = 'apiplatform-quickstart-panel-' . sanitize_html_class($label);
            $tabs .= '<button type="button" id="' . esc_attr($tab_id) . '" role="tab" aria-controls="' . esc_attr($panel_id) . '" aria-selected="' . ($first ? 'true' : 'false') . '" data-portal-quickstart-tab="' . esc_attr($label) . '">' . esc_html($label) . '</button>';
            $panels .= '<section id="' . esc_attr($panel_id) . '" role="tabpanel" aria-labelledby="' . esc_attr($tab_id) . '" class="apiplatform-portal-quickstart-panel' . ($first ? ' is-active' : '') . '"' . ($first ? '' : ' hidden') . '><pre><code>' . esc_html($code) . '</code></pre></section>';
            $first = false;
        }

        return '<section class="apiplatform-portal-panel apiplatform-portal-quickstart-code"><div class="apiplatform-portal-section-head"><div><h2>Quick Start</h2><p>Copy a ready-to-use request and replace <code>YOUR_API_KEY</code> with your own key.</p></div></div><section class="apiplatform-portal-code-block"><div class="apiplatform-portal-quickstart-tabs" role="tablist" aria-label="Quick start languages">' . $tabs . '</div><button type="button" data-portal-copy>Copy</button>' . $panels . '</section></section>';
    }

    private function code_example_code($example){
        if (is_array($example)) {
            return (string) ($example['code'] ?? '');
        }

        return is_string($example) ? $example : '';
    }

    private function node_example_code(array $docs){
        $endpoint = $docs['api']['endpoint_url'] ?? ($docs['endpoints'][0]['url'] ?? '');
        $method = strtoupper($docs['endpoints'][0]['method'] ?? ($docs['endpoints'][0]['methods'][0] ?? 'GET'));

        if ($endpoint === '') {
            return '';
        }

        return "const response = await fetch(\"" . $endpoint . "\", {\n  method: \"" . $method . "\",\n  headers: {\n    \"X-API-Key\": \"YOUR_API_KEY\",\n    \"Accept\": \"application/json\"\n  }\n});\n\nif (!response.ok) {\n  throw new Error(`Request failed: \${response.status}`);\n}\n\nconst data = await response.json();\nconsole.log(data);";
    }

    private function endpoint_preview(array $docs, array $card){
        if (empty($docs['endpoints'])) {
            return '<section class="apiplatform-portal-panel apiplatform-portal-empty"><h2>Endpoint Preview</h2><p>Endpoint schemas have not yet been documented.</p></section>';
        }

        $html = '<section class="apiplatform-portal-panel"><h2>Endpoint Preview</h2><div class="apiplatform-portal-endpoint-list">';
        foreach ($docs['endpoints'] as $endpoint) {
            $methods = $endpoint['methods'] ?? [$endpoint['method'] ?? 'GET'];
            $parameters = !empty($endpoint['parameters']) ? count($endpoint['parameters']) . ' documented parameter(s)' : 'No documented parameters';
            $response = $endpoint['example_response'] ?? 'Not documented';
            $html .= '<a href="' . esc_url(trailingslashit($card['docs_url']) . '#' . sanitize_html_class($endpoint['id'])) . '"><span>' . esc_html(implode(', ', $methods)) . '</span><code>' . esc_html($endpoint['path']) . '</code><small>' . esc_html($endpoint['description'] ?: 'No endpoint description documented.') . '</small><dl><div><dt>Authentication</dt><dd>' . esc_html($card['authentication_label'] ?: 'Not provided') . '</dd></div><div><dt>Parameters</dt><dd>' . esc_html($parameters) . '</dd></div><div><dt>Response</dt><dd><code>' . esc_html($this->short_code_preview($response)) . '</code></dd></div></dl></a>';
        }

        return $html . '</div></section>';
    }

    private function short_code_preview($value){
        $value = is_string($value) ? trim($value) : wp_json_encode($value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strlen($value) > 120 ? substr($value, 0, 117) . '...' : ($value ?: 'Not provided');
    }

    private function integration_cards(array $card){
        $items = [];

        $items[] = ['Docs', 'Documentation', 'Review authentication, endpoints, examples, and response codes generated from this API metadata.', $card['docs_url'], 'Available', 'Open docs'];

        $items[] = !empty($card['allow_public_tester'])
            ? ['Test', 'API Tester', 'Send a test request with your own API key.', $card['test_url'], 'Available', 'Open tester']
            : ['Test', 'API Tester', 'The API owner has not enabled public testing.', '', 'Unavailable', 'Unavailable'];

        $items[] = $card['changelog']
            ? ['Log', 'Changelog', $card['changelog'], '', 'Available', 'Read update']
            : ['Log', 'Changelog', 'No public changelog is currently available.', '', 'Unavailable', 'Unavailable'];

        $items[] = $card['support_url']
            ? ['Help', 'Support', 'Get help from the publisher.', $card['support_url'], 'Available', 'Contact support']
            : ['Help', 'Support', 'The API owner has not published support information.', '', 'Unavailable', 'Unavailable'];

        $html = '<section class="apiplatform-portal-panel"><h2>Integration</h2><div class="apiplatform-portal-integration-grid">';
        foreach ($items as $item) {
            [$icon, $title, $description, $url, $status, $action] = $item;
            $html .= '<article class="' . esc_attr($url ? 'is-clickable' : 'is-unavailable') . '">' . ($url ? '<a href="' . esc_url($url) . '"' . ($title === 'Support' ? ' rel="nofollow"' : '') . ' aria-label="' . esc_attr($action . ': ' . $title) . '"></a>' : '') . '<span class="apiplatform-portal-integration-icon">' . esc_html($icon) . '</span><h3>' . esc_html($title) . '</h3><p>' . esc_html($description) . '</p><strong>' . esc_html($status) . '</strong>';
            $html .= $url ? '<span class="apiplatform-portal-integration-action">' . esc_html($action) . '</span>' : '<span>' . esc_html($action) . '</span>';
            $html .= '</article>';
        }

        return $html . '</div></section>';
    }

    private function related_apis(array $card){
        if (empty($card['category'])) {
            return '';
        }

        $query = APIPlatform_Developer_Portal_Query::public_apis([
            'category' => $card['category'],
            'per_page' => 4,
        ]);
        $cards = [];

        foreach ($query->posts as $post) {
            if ((int) $post->ID === (int) $card['id']) {
                continue;
            }
            $cards[] = APIPlatform_Developer_Portal_Query::api_card($post);
        }

        if (!$cards) {
            return '';
        }

        return '<section class="apiplatform-portal-panel"><div class="apiplatform-portal-section-head"><div><h2>Related APIs</h2><p>More public APIs in ' . esc_html($card['category_label']) . '.</p></div><a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['category' => $card['category']])) . '">View category</a></div>' . $this->cards(array_slice($cards, 0, 3)) . '</section>';
    }

    private function tester($api, array $card){
        if (empty($card['allow_public_tester'])) {
            status_header(404);
            return $this->layout('<section class="apiplatform-portal-state"><h1>Testing unavailable.</h1><p>The API owner has not enabled public testing for this API.</p></section>', 'noindex');
        }

        $docs = APIPlatform_Developer_Portal_Documentation::normalize($api);
        $endpoints = $docs['endpoints'] ?? [];

        if (empty($endpoints)) {
            return $this->layout('<section class="apiplatform-portal-state"><h1>Testing unavailable.</h1><p>No documented endpoint is available for the public tester.</p></section>', $card['visibility'] === 'unlisted' ? 'noindex' : 'index');
        }

        $selected_endpoint = sanitize_key($_GET['endpoint'] ?? 'default-endpoint');
        $endpoint_options = '';
        $first_endpoint = $endpoints[0]['id'] ?? 'default-endpoint';
        $selected_found = false;

        foreach ($endpoints as $endpoint) {
            $endpoint_id = sanitize_key($endpoint['id'] ?? 'default-endpoint');
            $selected = $endpoint_id === $selected_endpoint;
            $selected_found = $selected_found || $selected;
            $label = trim(($endpoint['title'] ?? 'Endpoint') . ' - ' . ($endpoint['path'] ?? $card['endpoint_url']));
            $endpoint_options .= '<option value="' . esc_attr($endpoint_id) . '"' . selected($selected, true, false) . '>' . esc_html($label) . '</option>';
        }

        if (!$selected_found) {
            $selected_endpoint = $first_endpoint;
        }

        $active_endpoint = $endpoints[0];
        foreach ($endpoints as $endpoint) {
            if (sanitize_key($endpoint['id'] ?? '') === $selected_endpoint) {
                $active_endpoint = $endpoint;
                break;
            }
        }

        $methods = array_values(array_unique(array_map('strtoupper', $active_endpoint['methods'] ?? [$active_endpoint['method'] ?? 'GET'])));
        $method_options = '';
        foreach ($methods as $method) {
            $method = sanitize_key($method);
            $method_options .= '<option value="' . esc_attr(strtoupper($method)) . '">' . esc_html(strtoupper($method)) . '</option>';
        }

        $body = '<article class="apiplatform-portal-tester" data-portal-api-slug="' . esc_attr($card['slug']) . '">';
        $body .= '<div class="apiplatform-portal-tester-head"><div><p class="apiplatform-portal-eyebrow">Public API Tester</p><h1>Test ' . esc_html($card['title']) . '</h1><p>Bring your own API key. Your key is used only for this request, never stored, never echoed back, and never included in generated examples.</p></div><a href="' . esc_url($card['url']) . '">Back to API overview</a></div>';
        $body .= '<form data-portal-tester-form class="apiplatform-portal-tester-form">';
        $body .= '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('apiplatform_portal_test_' . $card['slug'])) . '">';
        $body .= '<label for="apiplatform-portal-tester-endpoint">Endpoint<select id="apiplatform-portal-tester-endpoint" name="endpoint_id">' . $endpoint_options . '</select></label>';
        $body .= '<label for="apiplatform-portal-tester-method">Method<select id="apiplatform-portal-tester-method" name="method">' . $method_options . '</select></label>';
        $body .= '<label class="apiplatform-portal-api-key-row" for="apiplatform-portal-tester-api-key">API Key<span><input id="apiplatform-portal-tester-api-key" name="api_key" type="password" autocomplete="off" placeholder="Paste API key for this request" required><button type="button" data-portal-toggle-key aria-label="Show or hide API key">Show</button></span></label>';
        $body .= $this->tester_parameters($active_endpoint, 'query');
        $body .= $this->tester_parameters($active_endpoint, 'header');
        $body_example = !empty($active_endpoint['body_example']['enabled']) ? ($active_endpoint['body_example']['example'] ?? '') : '';
        $body .= '<label for="apiplatform-portal-tester-body">JSON Body<textarea id="apiplatform-portal-tester-body" name="body" rows="7" placeholder="Enter documented JSON body when this endpoint accepts one">' . esc_textarea($body_example) . '</textarea></label>';
        $body .= '<div class="apiplatform-portal-tester-actions"><button type="submit" data-portal-send-test>Send Request</button></div>';
        $body .= '<div class="apiplatform-portal-tester-error" role="alert" aria-live="polite"></div>';
        $body .= '</form>';
        $body .= '<section class="apiplatform-portal-tester-result" aria-live="polite">';
        $body .= '<div class="apiplatform-portal-tester-result-summary"><span>Status: <strong data-portal-result-status>Not sent</strong></span><span>Response time: <strong data-portal-result-time>-</strong></span><span>Request ID: <strong data-portal-result-request-id>-</strong></span><span>Size: <strong data-portal-result-size>-</strong></span></div>';
        $body .= '<h2>Response Headers</h2><pre><code data-portal-result-headers>{}</code></pre>';
        $body .= '<h2>Response Body</h2><pre><code data-portal-result-body>Send a request to see the response.</code></pre>';
        $body .= '<div class="apiplatform-portal-tester-actions"><button type="button" data-portal-copy-result="response">Copy Response</button><button type="button" data-portal-copy-result="curl">Copy cURL</button></div>';
        $body .= '<pre class="apiplatform-portal-tester-curl" hidden><code data-portal-result-curl>' . esc_html($this->tester_curl_placeholder($active_endpoint, $card)) . '</code></pre>';
        $body .= '</section></article>';
        return $this->layout($body, $card['visibility'] === 'unlisted' ? 'noindex' : 'index');
    }

    private function tester_parameters(array $endpoint, $location){
        $parameters = [];

        foreach ($endpoint['parameters'] ?? [] as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $parameter_location = sanitize_key($parameter['location'] ?? $parameter['in'] ?? '');

            if ($parameter_location === $location) {
                $parameters[] = $parameter;
            }
        }

        $title = $location === 'query' ? 'Query Parameters' : 'Allowed Headers';

        if (!$parameters) {
            return '<section class="apiplatform-portal-tester-fields"><h2>' . esc_html($title) . '</h2><p>No ' . esc_html(strtolower($title)) . ' documented for this endpoint.</p></section>';
        }

        $html = '<section class="apiplatform-portal-tester-fields"><h2>' . esc_html($title) . '</h2>';

        foreach ($parameters as $parameter) {
            $name = sanitize_key($parameter['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $description = sanitize_text_field($parameter['description'] ?? '');
            $required = !empty($parameter['required']);
            $value = sanitize_text_field($parameter['example'] ?? $parameter['default'] ?? '');
            $html .= '<label for="apiplatform-portal-' . esc_attr($location) . '-' . esc_attr($name) . '">' . esc_html($name) . ($required ? ' *' : '') . '<input id="apiplatform-portal-' . esc_attr($location) . '-' . esc_attr($name) . '" data-portal-param="' . esc_attr($location) . '" data-portal-param-name="' . esc_attr($name) . '" type="text" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '></label>';

            if ($description !== '') {
                $html .= '<p>' . esc_html($description) . '</p>';
            }
        }

        return $html . '</section>';
    }

    private function tester_curl_placeholder(array $endpoint, array $card){
        $method = strtoupper($endpoint['method'] ?? (($endpoint['methods'][0] ?? 'GET')));
        $url = $endpoint['url'] ?? $card['endpoint_url'];

        return 'curl -X "' . $method . '" "' . $url . '" -H "X-API-Key: YOUR_API_KEY" -H "Accept: application/json"';
    }

    private function getting_started(){
        $sections = [
            ['What is FreedomAPI?', 'FreedomAPI is a developer platform for publishing, discovering, testing, and integrating API endpoints with secure key-based access.'],
            ['Authentication', 'Use the X-API-Key request header with your own API key. Legacy query-string keys may be shown for convenience, but headers are recommended.'],
            ['Generating API Keys', 'Create keys from your FreedomAPI dashboard, copy new secrets immediately, and rotate keys whenever access changes.'],
            ['Making Your First Request', 'Open an API detail page, review the docs, copy a sample request, replace YOUR_API_KEY, and send a test call.'],
            ['Rate Limits', 'APIs can enforce plan, API, and key-level limits. Exceeded requests return a 429 response.'],
            ['Errors', 'Handle 401 for missing keys, 403 for invalid or disabled access, 404 for unavailable endpoints, 429 for rate limits, and 500 for server errors.'],
            ['Best Practices', 'Start with docs, test with small payloads, cache stable responses, and monitor failures before shipping production integrations.'],
            ['Security Tips', 'Never commit API keys, never paste keys into shared screenshots, rotate compromised keys, and prefer X-API-Key over URL parameters.'],
        ];

        $html = '<section class="apiplatform-portal-section apiplatform-portal-getting-started"><p class="apiplatform-portal-eyebrow">FreedomAPI Guide</p><h1>Getting Started</h1><p>Learn the essentials for finding, testing, and integrating APIs published on FreedomAPI.</p></section>';
        $html .= '<section class="apiplatform-portal-panel"><h2>Integration Path</h2>' . $this->quickstart_steps() . '</section>';
        $html .= '<section class="apiplatform-portal-panel"><h2>Developer Basics</h2><div class="apiplatform-portal-guide-grid">';

        foreach ($sections as $section) {
            [$title, $description] = $section;
            $html .= '<article><h3>' . esc_html($title) . '</h3><p>' . esc_html($description) . '</p></article>';
        }

        return $this->layout($html . '</div></section>');
    }

    private function developer_dashboard(){
        if (!is_user_logged_in()) {
            return $this->layout('<section class="apiplatform-portal-state"><h1>Developer Dashboard</h1><p>Log in to view applications, favorite APIs, recent requests, SDK activity, and recently viewed APIs.</p></section>', 'noindex');
        }

        $user_id = get_current_user_id();
        $favorite_cards = $this->favorite_cards($user_id);
        $applications = class_exists('APIPlatform_Applications_Service') && method_exists('APIPlatform_Applications_Service', 'list_applications')
            ? (array) APIPlatform_Applications_Service::list_applications($user_id)
            : [];
        $application_count = count($applications);
        $request_count = 0;
        foreach ($applications as $application) {
            $request_count += absint($application['request_count'] ?? 0);
        }
        $directory_stats = APIPlatform_Developer_Portal_Query::public_directory_stats();
        $metrics = [
            ['Applications', $application_count, 'Developer applications owned by this account.'],
            ['Favorites', count($favorite_cards), 'Public APIs starred for quick access.'],
            ['Requests', $request_count, 'Requests recorded for this account\'s developer applications.'],
            ['Available SDKs', $directory_stats['sdks'] ?? 0, 'Public APIs with SDK downloads enabled.'],
        ];

        $html = '<section class="apiplatform-portal-section apiplatform-portal-dashboard"><p class="apiplatform-portal-eyebrow">Developer Dashboard</p><h1>Your integration workspace.</h1><div class="apiplatform-portal-dashboard-metrics">';
        foreach ($metrics as $metric) {
            $html .= '<article><span>' . esc_html($metric[0]) . '</span><strong>' . esc_html((string) $metric[1]) . '</strong><p>' . esc_html($metric[2]) . '</p></article>';
        }
        $html .= '</div><div class="apiplatform-portal-section-head"><div><h2>Favorite APIs</h2><p>Only public APIs are listed here. Private and unlisted APIs are not revealed by favorites.</p></div><a href="' . esc_url(class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis')) . '">Browse APIs</a></div>' . $this->cards($favorite_cards, true) . '</section>';

        return $this->layout($html, 'noindex');
    }

    private function directory_stats(array $stats, $expanded = false){
        $items = [
            'Public APIs' => $stats['public_apis'] ?? 0,
            'Endpoints' => $stats['endpoints'] ?? 0,
            'Categories' => $stats['categories'] ?? 0,
            'Tags' => $stats['tags'] ?? 0,
            'Publishers' => $stats['publishers'] ?? 0,
            'Models' => $stats['models'] ?? 0,
            'SDKs' => $stats['sdks'] ?? 0,
            'Recently Updated' => $stats['recently_updated'] ?? 0,
        ];
        if (!$expanded) {
            $items = array_intersect_key($items, array_flip(['Public APIs', 'Endpoints', 'Categories', 'Tags', 'Recently Updated']));
        }

        $html = '<div class="apiplatform-portal-directory-stats" aria-label="Public API directory statistics">';
        foreach ($items as $label => $value) {
            $html .= '<div><strong>' . esc_html(number_format_i18n((int) $value)) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        return $html . '</div>';
    }

    private function taxonomy_counts(array $category_counts = [], array $tag_counts = []){
        if (!$category_counts) {
            $category_counts = APIPlatform_Developer_Portal_Query::category_counts();
        }
        if (!$tag_counts) {
            $tag_counts = APIPlatform_Developer_Portal_Query::tag_counts();
        }

        $categories = APIPlatform_Developer_Portal_Query::categories();
        $html = '<div class="apiplatform-portal-taxonomy-counts">';
        $html .= '<section><h3>Categories</h3><div class="apiplatform-portal-tags">';
        foreach ($category_counts as $slug => $total) {
            $label = $categories[$slug] ?? ucwords(str_replace('-', ' ', $slug));
            $html .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['category' => $slug])) . '">' . esc_html($label . ' (' . number_format_i18n((int) $total) . ')') . '</a>';
        }
        if (!$category_counts) {
            $html .= '<span>No categories published yet</span>';
        }
        $html .= '</div></section><section><h3>Tags</h3><div class="apiplatform-portal-tags">';
        foreach ($tag_counts as $slug => $total) {
            $html .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['tag' => $slug])) . '">' . esc_html($slug . ' (' . number_format_i18n((int) $total) . ')') . '</a>';
        }
        if (!$tag_counts) {
            $html .= '<span>No tags published yet</span>';
        }
        return $html . '</div></section></div>';
    }

    private function directory_filters(array $filters, array $category_counts = [], array $tag_counts = []){
        $categories = APIPlatform_Developer_Portal_Query::categories();
        $auth_options = APIPlatform_Developer_Portal_Query::authentication_options();
        $status_options = APIPlatform_Developer_Portal_Query::directory_status_options();
        $sort_options = [
            'recent' => 'Recently Updated',
            'newest' => 'Newest',
            'alphabetical' => 'Alphabetical',
            'oldest' => 'Oldest',
            'popular' => 'Most Popular',
        ];

        $form = '<form class="apiplatform-portal-filters" method="get" action="' . esc_url(class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis')) . '">';
        $form .= '<label>Search<input name="search" type="search" value="' . esc_attr($filters['search']) . '" placeholder="Search title, summary, tags"></label>';
        $form .= '<label>Category<select name="category"><option value="">All Categories</option>';
        foreach ($categories as $slug => $label) {
            $count = isset($category_counts[$slug]) ? ' (' . number_format_i18n((int) $category_counts[$slug]) . ')' : '';
            $form .= '<option value="' . esc_attr($slug) . '"' . selected($filters['category'], $slug, false) . '>' . esc_html($label . $count) . '</option>';
        }
        $form .= '</select></label>';

        $form .= '<label>Authentication<select name="auth"><option value="">All</option>';
        foreach ($auth_options as $slug => $label) {
            $form .= '<option value="' . esc_attr($slug) . '"' . selected($filters['auth'], $slug, false) . '>' . esc_html($label) . '</option>';
        }
        $form .= '</select></label>';

        $form .= '<label>Status<select name="status"><option value="">All Statuses</option>';
        foreach ($status_options as $slug => $label) {
            $form .= '<option value="' . esc_attr($slug) . '"' . selected($filters['status'], $slug, false) . '>' . esc_html($label) . '</option>';
        }
        $form .= '</select></label>';

        $form .= '<label>Tag<input name="tag" value="' . esc_attr($filters['tag']) . '" placeholder="' . esc_attr($tag_counts ? implode(', ', array_slice(array_keys($tag_counts), 0, 3)) : 'tag') . '"></label>';
        $form .= '<label>Sort<select name="sort">';
        foreach ($sort_options as $slug => $label) {
            $form .= '<option value="' . esc_attr($slug) . '"' . selected($filters['sort'], $slug, false) . '>' . esc_html($label) . '</option>';
        }
        $form .= '</select></label><button type="submit">Apply Filters</button></form>';

        return $form;
    }

    private function api_metadata(array $card){
        $tags = '';
        foreach ($card['tags'] as $tag) {
            $tags .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(['tag' => sanitize_title($tag)])) . '">' . esc_html($tag) . '</a>';
        }

        $rows = [
            'Category' => $card['category_label'] ?: 'Other',
            'Authentication' => $card['authentication_label'],
            'Status' => $card['public_status_label'],
            'Version' => $card['version_label'],
            'Last updated' => $card['updated_label'],
        ];

        $html = '<dl class="apiplatform-portal-meta-list">';
        foreach ($rows as $label => $value) {
            $html .= '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        if ($tags) {
            $html .= '<div><dt>Tags</dt><dd class="apiplatform-portal-tags">' . $tags . '</dd></div>';
        }

        return $html . '</dl>';
    }

    private function runtime_label($runtime_type){
        $runtime_type = sanitize_key($runtime_type);
        return [
            'internal' => 'Internal FreedomAPI Response',
            'rest_proxy' => 'REST Proxy',
            'webhook_proxy' => 'Webhook Proxy',
            'external_http' => 'External HTTP Proxy',
        ][$runtime_type] ?? 'Internal FreedomAPI Response';
    }

    private function favorite_api_ids($user_id){
        $favorites = get_user_meta(absint($user_id), 'apiplatform_developer_favorite_apis', true);
        return array_values(array_unique(array_filter(array_map('absint', is_array($favorites) ? $favorites : []))));
    }

    private function favorite_cards($user_id){
        $cards = [];
        foreach ($this->favorite_api_ids($user_id) as $api_id) {
            $post = get_post($api_id);
            if (!$post || $post->post_type !== 'user_api') {
                continue;
            }
            $card = APIPlatform_Developer_Portal_Query::api_card($post);
            if (($card['visibility'] ?? '') === 'public' && ($card['status'] ?? '') === 'active') {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    private function favorite_form(array $card){
        if (!is_user_logged_in() || ($card['visibility'] ?? '') !== 'public') {
            return '';
        }

        $api_id = absint($card['id'] ?? 0);
        $favorites = $this->favorite_api_ids(get_current_user_id());
        $active = in_array($api_id, $favorites, true);

        return '<form method="post" class="apiplatform-portal-favorite-form">' .
            '<input type="hidden" name="apiplatform_portal_action" value="toggle-favorite">' .
            '<input type="hidden" name="api_id" value="' . esc_attr($api_id) . '">' .
            '<input type="hidden" name="apiplatform_portal_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_portal_favorite_' . $api_id)) . '">' .
            '<button type="submit" aria-pressed="' . ($active ? 'true' : 'false') . '">' . esc_html($active ? 'Starred' : 'Star API') . '</button></form>';
    }

    private function cards(array $cards, $directory = false){
        if (!$cards) {
            $title = $directory ? 'No APIs matched your filters.' : 'No public APIs yet.';
            $description = $directory ? 'Try clearing search terms or choosing a different category, status, or authentication filter.' : 'Published APIs will appear here after owners make them public.';
            $empty_url = class_exists('APIPlatform_Routes')
                ? APIPlatform_Routes::developer_url($directory ? 'apis' : '')
                : home_url($directory ? '/developers/apis' : '/developers');
            return '<div class="apiplatform-portal-empty"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="apiplatform-portal-empty-action" href="' . esc_url($empty_url) . '">' . esc_html($directory ? 'Clear filters' : 'Return to Developer Home') . '</a></div>';
        }

        $html = '<div class="apiplatform-portal-grid">';
        foreach ($cards as $card) {
            $tags = '';
            foreach (array_slice($card['tags'], 0, 4) as $tag) {
                $tags .= '<span>' . esc_html($tag) . '</span>';
            }

            $actions = '<div class="apiplatform-portal-card-actions"><a href="' . esc_url($card['url']) . '">View API</a><a href="' . esc_url($card['docs_url']) . '">Documentation</a>';
            if (!empty($card['allow_public_tester'])) {
                $actions .= '<a href="' . esc_url($card['test_url']) . '">Test API</a>';
            }
            $actions .= $this->favorite_form($card);
            $actions .= '</div>';

            $html .= '<article class="apiplatform-portal-api-card"><a class="apiplatform-portal-card-cover-link" href="' . esc_url($card['url']) . '" aria-label="' . esc_attr('View ' . $card['title']) . '"></a><div class="apiplatform-portal-api-card-main"><span class="apiplatform-portal-api-icon">' . esc_html($card['icon']) . '</span><div><h3><a href="' . esc_url($card['url']) . '">' . esc_html($card['title']) . '</a></h3><p>' . esc_html($card['summary'] ?: 'No summary published yet.') . '</p></div></div><div class="apiplatform-portal-card-meta"><span>' . esc_html($card['category_label'] ?: 'Other') . '</span><span>' . esc_html($card['authentication_label']) . '</span><span>' . esc_html($card['public_status_label']) . '</span><span>' . esc_html($card['version_label']) . '</span><span>' . esc_html($card['updated_label']) . '</span></div>' . ($tags ? '<div class="apiplatform-portal-tags">' . $tags . '</div>' : '') . ($directory ? $actions : '') . '</article>';
        }
        return $html . '</div>';
    }

    private function api_header(array $card){
        $publisher = $card['publisher_name'] ?: 'Published through FreedomAPI';
        return '<header class="apiplatform-portal-api-header"><span class="apiplatform-portal-api-icon">' . esc_html($card['icon']) . '</span><div><p class="apiplatform-portal-eyebrow">' . esc_html($publisher) . '</p><h1>' . esc_html($card['title']) . '</h1><p>' . esc_html($card['category_label']) . ' / ' . esc_html($card['version_label']) . ' / ' . esc_html($card['public_status_label']) . '</p></div></header>';
    }

    private function quickstart_panel(){
        return '<section class="apiplatform-portal-section apiplatform-portal-quickstart"><h2>Developer Quick Start</h2>' . $this->quickstart_steps() . '</section>';
    }

    private function quickstart_steps(){
        $steps = [
            'Find an API',
            'Read the documentation',
            'Copy sample code',
            'Generate an API key',
            'Test the endpoint',
            'Integrate into your application',
        ];

        $html = '<ol>';
        foreach ($steps as $step) {
            $html .= '<li>' . esc_html($step) . '</li>';
        }

        return $html . '</ol>';
    }

    private function pagination(WP_Query $query, array $filters){
        if ($query->max_num_pages <= 1) return '';
        $html = '<nav class="apiplatform-portal-pagination" aria-label="API directory pagination">';
        if ((int) $filters['page'] > 1) {
            $html .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(array_merge($filters, ['portal_page' => (int) $filters['page'] - 1]))) . '">Previous</a>';
        }
        for ($page = 1; $page <= (int) $query->max_num_pages; $page++) {
            $html .= '<a' . ($page === (int) $filters['page'] ? ' aria-current="page"' : '') . ' href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(array_merge($filters, ['portal_page' => $page]))) . '">' . esc_html((string) $page) . '</a>';
        }
        if ((int) $filters['page'] < (int) $query->max_num_pages) {
            $html .= '<a href="' . esc_url(APIPlatform_Developer_Portal_Query::directory_url(array_merge($filters, ['portal_page' => (int) $filters['page'] + 1]))) . '">Next</a>';
        }
        return $html . '</nav>';
    }

    private function preview_api(){
        $api_id = isset($_GET['apiplatform_portal_preview']) ? absint($_GET['apiplatform_portal_preview']) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!$api_id || !is_user_logged_in() || !wp_verify_nonce($nonce, 'apiplatform_portal_preview_' . $api_id)) {
            return null;
        }

        $post = get_post($api_id);
        $can_preview = $post && $post->post_type === 'user_api' && (
            class_exists('APIPlatform_Ownership_Service')
                ? APIPlatform_Ownership_Service::can(get_current_user_id(), $api_id, 'apis.view')
                : ((int) $post->post_author === get_current_user_id() || current_user_can('manage_options'))
        );

        return $can_preview ? $post : null;
    }

    private function layout($content, $robots = 'index'){
        return '<div id="apiplatform-developer-portal" class="apiplatform-developer-portal" data-robots="' . esc_attr($robots) . '"><a class="apiplatform-portal-skip" href="#apiplatform-portal-content">Skip to content</a><div id="apiplatform-portal-content" class="apiplatform-developer-portal-container">' . $content . '</div></div>';
    }
}
