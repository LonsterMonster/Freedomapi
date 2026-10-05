<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Routes {
    const GATEWAY_OPTION = 'apiplatform_gateway_prefix';
    const DASHBOARD_OPTION = 'apiplatform_dashboard_prefix';
    const DEVELOPER_OPTION = 'apiplatform_developer_prefix';
    const DOCS_OPTION = 'apiplatform_docs_prefix';
    const AUTH_OPTION = 'apiplatform_auth_prefix';
    const VERSION_OPTION = 'apiplatform_routes_version';
    const VERSION = '1-0';

    private static $defaults = [
        self::GATEWAY_OPTION => 'gateway',
        self::DASHBOARD_OPTION => 'dashboard',
        self::DEVELOPER_OPTION => 'developers',
        self::DOCS_OPTION => 'docs',
        self::AUTH_OPTION => 'auth',
    ];

    public static function gateway_prefix(){ return self::prefix(self::GATEWAY_OPTION); }
    public static function dashboard_prefix(){ return self::prefix(self::DASHBOARD_OPTION); }
    public static function developer_prefix(){ return self::prefix(self::DEVELOPER_OPTION); }
    public static function docs_prefix(){ return self::prefix(self::DOCS_OPTION); }
    public static function auth_prefix(){ return self::prefix(self::AUTH_OPTION); }

    public static function gateway_url($publisher = '', $api = '', $tail = ''){
        return home_url('/' . self::join([self::gateway_prefix(), $publisher, $api, $tail]));
    }

    public static function dashboard_url($path = ''){
        return site_url('/' . self::join([self::dashboard_prefix(), $path]));
    }

    public static function developer_url($path = ''){
        return home_url('/' . self::join([self::developer_prefix(), $path]));
    }

    public static function docs_url($publisher = '', $api = ''){
        if ($publisher !== '' && $api !== '') {
            return self::developer_url('apis/' . sanitize_title($api) . '/docs');
        }

        return home_url('/' . self::docs_prefix());
    }

    public static function auth_start_url($provider){
        return home_url('/' . self::join([self::auth_prefix(), sanitize_key($provider)]));
    }

    public static function auth_callback_url($provider){
        return home_url('/' . self::join([self::auth_prefix(), sanitize_key($provider), 'callback']));
    }

    public static function connected_accounts_url(){
        return home_url('/' . self::join([self::auth_prefix(), 'accounts']));
    }

    public static function prefixes(){
        return [
            self::GATEWAY_OPTION => self::gateway_prefix(),
            self::DASHBOARD_OPTION => self::dashboard_prefix(),
            self::DEVELOPER_OPTION => self::developer_prefix(),
            self::DOCS_OPTION => self::docs_prefix(),
            self::AUTH_OPTION => self::auth_prefix(),
        ];
    }

    public static function validate_prefixes(array $submitted){
        $clean = [];
        $errors = [];
        $reserved = ['wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'wp-login.php', 'feed', 'search', 'author', 'category', 'tag', 'page'];

        foreach (self::$defaults as $option => $default) {
            $value = self::sanitize_prefix($submitted[$option] ?? get_option($option, $default));
            if ($value === '') {
                $errors[] = self::label($option) . ' prefix is required.';
                $value = $default;
            }
            if (in_array($value, $reserved, true)) {
                $errors[] = self::label($option) . ' prefix uses a reserved WordPress route.';
            }
            $clean[$option] = $value;
        }

        $counts = array_count_values($clean);
        foreach ($counts as $prefix => $count) {
            if ($count > 1) {
                $errors[] = 'Route prefix "' . $prefix . '" is used more than once.';
            }
        }

        foreach ($clean as $option => $prefix) {
            $page = get_page_by_path($prefix);
            if ($page && !self::page_allowed_for_option($option, $page)) {
                $errors[] = self::label($option) . ' prefix conflicts with an existing WordPress page.';
            }
        }

        return ['ok' => empty($errors), 'prefixes' => $clean, 'errors' => $errors];
    }

    public static function save_prefixes(array $submitted){
        $validation = self::validate_prefixes($submitted);
        if (!$validation['ok']) {
            return $validation;
        }

        $changed = false;
        foreach ($validation['prefixes'] as $option => $prefix) {
            if (get_option($option, self::$defaults[$option]) !== $prefix) {
                update_option($option, $prefix);
                $changed = true;
            }
        }

        self::sync_managed_pages();

        if ($changed) {
            update_option(self::VERSION_OPTION, self::VERSION . '-' . md5(wp_json_encode($validation['prefixes'])));
            flush_rewrite_rules(false);
        }

        return ['ok' => true, 'prefixes' => $validation['prefixes'], 'changed' => $changed, 'errors' => []];
    }

    public static function sync_managed_pages(){
        self::sync_page('apiplatform_dashboard_page_id', self::dashboard_prefix(), 'Dashboard');
        self::sync_page('apiplatform_developer_page_id', self::developer_prefix(), 'Developers');
        self::sync_page('apiplatform_docs_page_id', self::docs_prefix(), 'Docs');
    }

    private static function sync_page($option, $slug, $title){
        $page_id = absint(get_option($option));
        $page = $page_id ? get_post($page_id) : null;

        if (!$page || $page->post_type !== 'page') {
            $existing = get_page_by_path($slug);
            if ($existing) {
                update_option($option, absint($existing->ID));
            }
            return;
        }

        if ($page->post_name !== $slug) {
            wp_update_post(['ID' => $page->ID, 'post_name' => $slug, 'post_title' => $page->post_title ?: $title]);
        }
    }

    private static function page_allowed_for_option($option, WP_Post $page){
        $page_option = [
            self::DASHBOARD_OPTION => 'apiplatform_dashboard_page_id',
            self::DEVELOPER_OPTION => 'apiplatform_developer_page_id',
            self::DOCS_OPTION => 'apiplatform_docs_page_id',
        ][$option] ?? '';
        $page_id = absint($page_option ? get_option($page_option) : 0);
        return $page_id && absint($page->ID) === $page_id;
    }

    private static function prefix($option){
        return self::sanitize_prefix(get_option($option, self::$defaults[$option] ?? ''));
    }

    private static function sanitize_prefix($value){
        $value = trim((string) $value);
        $value = trim($value, "/ \t\n\r\0\x0B");
        $value = sanitize_title($value);
        return $value;
    }

    private static function join(array $parts){
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part, '/');
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode('/', $clean);
    }

    private static function label($option){
        return [
            self::GATEWAY_OPTION => 'Gateway',
            self::DASHBOARD_OPTION => 'Dashboard',
            self::DEVELOPER_OPTION => 'Developer Portal',
            self::DOCS_OPTION => 'Documentation',
            self::AUTH_OPTION => 'Authentication',
        ][$option] ?? 'Route';
    }
}

add_action('init', ['APIPlatform_Routes', 'sync_managed_pages'], 30);
