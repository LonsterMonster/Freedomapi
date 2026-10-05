<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal_Query {

    public static function meta_keys(){
        return [
            'visibility' => 'apiplatform_portal_visibility',
            'title' => 'apiplatform_portal_title',
            'summary' => 'apiplatform_portal_summary',
            'description' => 'apiplatform_portal_description',
            'category' => 'apiplatform_portal_category',
            'tags' => 'apiplatform_portal_tags',
            'tag_slugs' => 'apiplatform_portal_tag_slugs',
            'icon' => 'apiplatform_portal_icon',
            'docs_status' => 'apiplatform_portal_docs_status',
            'published_at' => 'apiplatform_portal_published_at',
            'updated_at' => 'apiplatform_portal_updated_at',
            'portal_slug' => 'apiplatform_portal_slug',
            'support_url' => 'apiplatform_portal_support_url',
            'terms_url' => 'apiplatform_portal_terms_url',
            'privacy_url' => 'apiplatform_portal_privacy_url',
            'website_url' => 'apiplatform_portal_website_url',
            'deprecation_notice' => 'apiplatform_portal_deprecation_notice',
            'deprecated_at' => 'apiplatform_portal_deprecated_at',
            'sunset_date' => 'apiplatform_portal_sunset_date',
            'migration_url' => 'apiplatform_portal_migration_url',
            'allow_public_tester' => 'apiplatform_portal_allow_public_tester',
            'allow_anonymous_testing' => 'apiplatform_portal_allow_anonymous_testing',
            'authentication_type' => 'apiplatform_portal_authentication_type',
            'publisher_name' => 'apiplatform_portal_publisher_name',
            'version_label' => 'apiplatform_portal_version_label',
            'version_status' => 'apiplatform_portal_version_status',
            'release_date' => 'apiplatform_portal_release_date',
            'changelog' => 'apiplatform_portal_changelog',
        ];
    }

    public static function table(){
        global $wpdb;
        return $wpdb->prefix . 'apiplatform_portal_apis';
    }

    public static function categories(){
        return apply_filters('apiplatform_developer_portal_categories', [
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
        ]);
    }

    public static function visibility_options(){
        return class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::portal_visibilities()
            : ['private', 'unlisted', 'public'];
    }

    public static function reserved_portal_slugs(){
        return apply_filters('apiplatform_developer_portal_reserved_slugs', [
            'apis',
            'api',
            'docs',
            'documentation',
            'test',
            'tester',
            'categories',
            'category',
            'tags',
            'tag',
            'getting-started',
            'developer-home',
            'dashboard',
            'browse',
            'search',
            'support',
        ]);
    }

    public static function portal_slug_is_reserved($slug){
        $slug = sanitize_title($slug);

        return $slug !== '' && in_array($slug, self::reserved_portal_slugs(), true);
    }

    public static function authentication_options(){
        return [
            'api-key' => 'API Key',
            'bearer' => 'Bearer',
            'none' => 'None',
        ];
    }

    public static function directory_status_options(){
        return [
            'active' => 'Active',
            'beta' => 'Beta',
            'deprecated' => 'Deprecated',
        ];
    }

    public static function get_settings($api_id){
        $api_id = absint($api_id);
        $keys = self::meta_keys();
        $settings = [];

        foreach ($keys as $field => $meta_key) {
            $settings[$field] = get_post_meta($api_id, $meta_key, true);
        }

        $settings['visibility'] = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'])
            : (in_array($settings['visibility'], self::visibility_options(), true) ? $settings['visibility'] : 'private');
        $settings['title'] = $settings['title'] ?: get_the_title($api_id);
        $settings['portal_slug'] = $settings['portal_slug'] ?: sanitize_title(get_post_field('post_name', $api_id));
        $settings['tags'] = self::normalize_tags($settings['tags']);
        $settings['tag_slugs'] = self::tag_slugs($settings['tags']);
        $settings['allow_public_tester'] = (bool) $settings['allow_public_tester'];
        $settings['allow_anonymous_testing'] = (bool) $settings['allow_anonymous_testing'];
        $settings['authentication_type'] = self::normalize_authentication_type($settings['authentication_type']);
        $settings['version_label'] = $settings['version_label'] ?: 'v1';
        $settings['version_status'] = $settings['version_status'] ?: 'current';
        $settings['icon'] = $settings['icon'] ?: self::initials($settings['title']);
        $settings['status'] = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status(get_post_meta($api_id, 'apiplatform_status', true) ?: 'active')
            : (get_post_meta($api_id, 'apiplatform_status', true) ?: 'active');
        $settings['lifecycle'] = class_exists('APIPlatform_Lifecycle_Policy')
            ? APIPlatform_Lifecycle_Policy::get_state($api_id)
            : self::infer_lifecycle_from_settings($settings);

        return $settings;
    }

    public static function normalize_tags($tags){
        if (is_string($tags)) {
            $tags = preg_split('/[,#]+/', $tags);
        }

        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];

        foreach ($tags as $tag) {
            $tag = sanitize_text_field((string) $tag);
            $tag = trim(preg_replace('/\s+/', ' ', $tag));

            if ($tag === '') {
                continue;
            }

            $normalized[sanitize_title($tag)] = substr($tag, 0, 40);
        }

        return array_slice(array_values($normalized), 0, 12);
    }

    public static function tag_slugs(array $tags){
        return array_map('sanitize_title', $tags);
    }

    public static function initials($name){
        $words = preg_split('/[^A-Za-z0-9]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }

        return strtoupper(substr($words[0] ?? 'API', 0, 2));
    }

    public static function normalize_authentication_type($auth){
        $auth = sanitize_key(str_replace('_', '-', (string) $auth));
        return isset(self::authentication_options()[$auth]) ? $auth : 'api-key';
    }

    public static function public_status(array $settings){
        $lifecycle = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_lifecycle($settings['lifecycle'] ?? '')
            : sanitize_key($settings['lifecycle'] ?? '');

        if ($lifecycle === 'deprecated') {
            return 'deprecated';
        }

        $version_status = sanitize_key($settings['version_status'] ?? '');

        if (in_array($version_status, ['beta', 'deprecated'], true)) {
            return $version_status;
        }

        $runtime_status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status($settings['status'] ?? 'active')
            : sanitize_key($settings['status'] ?? 'active');

        return $runtime_status === 'active' ? 'active' : 'deprecated';
    }

    private static function infer_lifecycle_from_settings(array $settings){
        $status = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_runtime_status($settings['status'] ?? 'active')
            : sanitize_key($settings['status'] ?? 'active');
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? 'private')
            : sanitize_key($settings['visibility'] ?? 'private');

        if ($status === 'archived') {
            return 'archived';
        }

        if (($settings['version_status'] ?? '') === 'deprecated') {
            return 'deprecated';
        }

        if ($visibility === 'public' && $status === 'active') {
            return 'public';
        }

        if ($visibility === 'unlisted' && $status === 'active') {
            return 'testing';
        }

        return 'private';
    }

    public static function api_card($post){
        $settings = self::get_settings($post->ID);
        $lifecycle = $settings['lifecycle'] ?? 'private';
        $owner = class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($post->ID) : null;
        $owner_id = $owner ? absint($owner['owner_id']) : (function_exists('apiplatform_get_team_owner') ? absint(apiplatform_get_team_owner($post->post_author)) : absint($post->post_author));
        $publisher = (!$owner || ($owner['owner_type'] ?? 'personal') === 'personal') ? get_userdata($owner_id) : null;
        $publisher_slug = $owner && ($owner['owner_type'] ?? '') === 'organization'
            ? sanitize_title($owner['owner_slug'] ?? '')
            : ($publisher ? sanitize_title($publisher->user_nicename ?: $publisher->user_login) : 'publisher');
        $publisher_name = $settings['publisher_name'];
        if ($publisher_name === '' && $owner && ($owner['owner_type'] ?? '') === 'organization') {
            $publisher_name = $owner['owner_name'] ?? '';
        }

        return [
            'id' => (int) $post->ID,
            'slug' => $settings['portal_slug'],
            'runtime_slug' => $post->post_name,
            'title' => $settings['title'],
            'summary' => $settings['summary'],
            'description' => $settings['description'],
            'category' => $settings['category'],
            'category_label' => self::categories()[$settings['category']] ?? ucwords(str_replace('-', ' ', $settings['category'])),
            'tags' => $settings['tags'],
            'icon' => $settings['icon'],
            'visibility' => class_exists('APIPlatform_API_Status') ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility']) : $settings['visibility'],
            'docs_status' => $settings['docs_status'],
            'status' => class_exists('APIPlatform_API_Status') ? APIPlatform_API_Status::normalize_runtime_status($settings['status']) : $settings['status'],
            'lifecycle' => class_exists('APIPlatform_API_Status') ? APIPlatform_API_Status::normalize_lifecycle($lifecycle) : $lifecycle,
            'lifecycle_label' => class_exists('APIPlatform_Lifecycle_Policy') ? APIPlatform_Lifecycle_Policy::label($lifecycle) : ucfirst($lifecycle),
            'public_status' => self::public_status($settings),
            'public_status_label' => self::directory_status_options()[self::public_status($settings)] ?? 'Active',
            'authentication_type' => $settings['authentication_type'],
            'authentication_label' => self::authentication_options()[$settings['authentication_type']] ?? 'API Key',
            'version_label' => $settings['version_label'],
            'version_status' => $settings['version_status'],
            'updated_at' => $settings['updated_at'] ?: get_post_modified_time('Y-m-d', false, $post),
            'updated_label' => self::updated_label($settings['updated_at'] ?: get_post_modified_time('Y-m-d H:i:s', false, $post)),
            'published_at' => $settings['published_at'],
            'allow_public_tester' => !empty($settings['allow_public_tester']),
            'allow_anonymous_testing' => !empty($settings['allow_anonymous_testing']),
            'runtime_type' => sanitize_key(get_post_meta($post->ID, 'apiplatform_gateway_runtime_type', true) ?: 'internal'),
            'cache_enabled' => (bool) get_post_meta($post->ID, 'apiplatform_gateway_cache_enabled', true),
            'response_validation' => sanitize_key(get_post_meta($post->ID, 'apiplatform_gateway_response_validation', true) ?: 'off'),
            'endpoint_url' => function_exists('apiplatform_public_gateway_url') ? apiplatform_public_gateway_url($post) : (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url($publisher_slug, $post->post_name) : home_url('/gateway/' . $publisher_slug . '/' . $post->post_name)),
            'legacy_endpoint_url' => rest_url('platform/v1/api/' . $post->post_name),
            'url' => self::api_url($settings['portal_slug']),
            'docs_url' => trailingslashit(self::api_url($settings['portal_slug'])) . 'docs',
            'test_url' => trailingslashit(self::api_url($settings['portal_slug'])) . 'test',
            'support_url' => $settings['support_url'],
            'terms_url' => $settings['terms_url'],
            'privacy_url' => $settings['privacy_url'],
            'website_url' => $settings['website_url'],
            'publisher_name' => $publisher_name,
            'deprecation_notice' => $settings['deprecation_notice'],
            'deprecated_at' => $settings['deprecated_at'],
            'sunset_date' => $settings['sunset_date'],
            'migration_url' => $settings['migration_url'],
            'changelog' => $settings['changelog'],
        ];
    }

    public static function api_url($slug){
        return class_exists('APIPlatform_Routes')
            ? APIPlatform_Routes::developer_url('apis/' . sanitize_title($slug))
            : home_url('/developers/apis/' . sanitize_title($slug));
    }

    public static function directory_url(array $args = []){
        unset($args['page']);

        if (isset($args['portal_page']) && (int) $args['portal_page'] <= 1) {
            unset($args['portal_page']);
        }

        if (isset($args['per_page']) && (int) $args['per_page'] === 12) {
            unset($args['per_page']);
        }

        return add_query_arg(array_filter($args, function($value){
            return $value !== '' && $value !== null;
        }), class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis'));
    }

    public static function resolve_api($slug, $include_unlisted = true){
        global $wpdb;

        $slug = sanitize_title($slug);

        if ($slug === '') {
            return null;
        }

        $allowed = $include_unlisted ? ['public', 'unlisted'] : ['public'];
        $normalized_allowed = array_map('strtolower', $allowed);
        $placeholders = implode(',', array_fill(0, count($normalized_allowed), '%s'));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT api_id FROM " . self::table() . " WHERE portal_slug = %s AND LOWER(visibility) IN ($placeholders) LIMIT 1",
            array_merge([$slug], $normalized_allowed)
        ));

        if ($row) {
            $post = get_post((int) $row->api_id);
            return $post && $post->post_type === 'user_api' ? $post : null;
        }

        $posts = get_posts([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 1,
            'meta_query' => [
                [
                    'key' => self::meta_keys()['portal_slug'],
                    'value' => $slug,
                    'compare' => '=',
                ],
            ],
        ]);

        if (!$posts) {
            return null;
        }

        $settings = self::get_settings($posts[0]->ID);
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? '')
            : sanitize_key($settings['visibility'] ?? '');

        return in_array($visibility, $normalized_allowed, true) ? $posts[0] : null;
    }

    public static function public_apis(array $filters = []){
        $filters = self::sanitize_directory_filters($filters);
        $result = self::public_api_ids($filters);

        if (empty($result['ids'])) {
            $query = new WP_Query([
                'post_type' => 'user_api',
                'post__in' => [0],
                'posts_per_page' => $filters['per_page'],
                'no_found_rows' => true,
            ]);
            $query->found_posts = 0;
            $query->max_num_pages = 0;
            return $query;
        }

        $query = new WP_Query([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $filters['per_page'],
            'post__in' => array_map('absint', $result['ids']),
            'orderby' => 'post__in',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $query->found_posts = (int) $result['total'];
        $query->max_num_pages = (int) ceil($result['total'] / max(1, $filters['per_page']));

        return $query;
    }

    public static function recently_published_apis($limit = 6){
        return self::public_apis([
            'sort' => 'newest',
            'per_page' => absint($limit) ?: 6,
        ]);
    }

    public static function featured_apis($limit = 6){
        return self::public_apis([
            'sort' => 'popular',
            'per_page' => absint($limit) ?: 6,
        ]);
    }

    public static function sanitize_directory_filters(array $input){
        $categories = self::categories();
        $sort = sanitize_key($input['sort'] ?? 'recent');

        if (!in_array($sort, ['recent', 'newest', 'oldest', 'alphabetical', 'popular'], true)) {
            $sort = 'recent';
        }

        $category = sanitize_title($input['category'] ?? '');

        if ($category !== '' && !isset($categories[$category])) {
            $category = '';
        }

        $status = sanitize_key($input['status'] ?? '');

        if (!in_array($status, array_merge([''], array_keys(self::directory_status_options())), true)) {
            $status = '';
        }

        $auth = self::normalize_authentication_type($input['auth'] ?? '');

        if (empty($input['auth'])) {
            $auth = '';
        }

        return [
            'search' => sanitize_text_field($input['search'] ?? ($input['s'] ?? '')),
            'category' => $category,
            'tag' => sanitize_title($input['tag'] ?? ''),
            'auth' => $auth,
            'status' => $status,
            'sort' => $sort,
            'page' => max(1, absint($input['portal_page'] ?? 1)),
            'per_page' => min(24, max(1, absint($input['per_page'] ?? 12))),
        ];
    }

    public static function public_directory_stats(){
        global $wpdb;

        $since = date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS * 30);
        $public_ids = self::all_public_api_ids();
        $category_counts = self::category_counts();
        $tag_counts = self::tag_counts(999);
        $recently_updated = 0;

        foreach ($public_ids as $api_id) {
            $settings = self::get_settings($api_id);
            $updated = $settings['updated_at'] ?: get_post_modified_time('Y-m-d H:i:s', false, $api_id);
            if ($updated && strtotime($updated) >= strtotime($since)) {
                $recently_updated++;
            }
        }

        return [
            'public_apis' => count($public_ids),
            'categories' => count($category_counts),
            'tags' => count($tag_counts),
            'recently_updated' => $recently_updated,
            'endpoints' => self::endpoint_count($public_ids),
            'publishers' => self::publisher_count($public_ids),
            'models' => self::model_count($public_ids),
            'sdks' => self::sdk_enabled_count($public_ids),
        ];
    }

    public static function category_counts(){
        $counts = [];

        foreach (self::all_public_api_ids() as $api_id) {
            $settings = self::get_settings($api_id);
            $category = sanitize_title($settings['category'] ?? '');
            if ($category !== '') {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        arsort($counts);
        return $counts;
    }

    public static function tag_counts($limit = 24){
        $counts = [];

        foreach (self::all_public_api_ids() as $api_id) {
            $settings = self::get_settings($api_id);
            foreach ((array) ($settings['tag_slugs'] ?? []) as $tag) {
                $tag = sanitize_title((string) $tag);
                if ($tag !== '') {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        return array_slice($counts, 0, absint($limit) ?: 24, true);
    }

    private static function public_api_ids(array $filters){
        global $wpdb;

        $table = self::table();
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $logs = $wpdb->prefix . 'apiplatform_logs';
        $keys = self::meta_keys();

        $joins = [
            "LEFT JOIN {$table} r ON r.api_id = p.ID",
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_title ON pm_title.post_id = p.ID AND pm_title.meta_key = %s", $keys['title']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_summary ON pm_summary.post_id = p.ID AND pm_summary.meta_key = %s", $keys['summary']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_description ON pm_description.post_id = p.ID AND pm_description.meta_key = %s", $keys['description']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_tags ON pm_tags.post_id = p.ID AND pm_tags.meta_key = %s", $keys['tags']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_tag_slugs ON pm_tag_slugs.post_id = p.ID AND pm_tag_slugs.meta_key = %s", $keys['tag_slugs']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_auth ON pm_auth.post_id = p.ID AND pm_auth.meta_key = %s", $keys['authentication_type']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_version_status ON pm_version_status.post_id = p.ID AND pm_version_status.meta_key = %s", $keys['version_status']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_visibility ON pm_visibility.post_id = p.ID AND pm_visibility.meta_key = %s", $keys['visibility']),
            $wpdb->prepare("LEFT JOIN {$postmeta} pm_category ON pm_category.post_id = p.ID AND pm_category.meta_key = %s", $keys['category']),
            "LEFT JOIN {$postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = 'apiplatform_status'",
            "LEFT JOIN {$postmeta} pm_lifecycle ON pm_lifecycle.post_id = p.ID AND pm_lifecycle.meta_key = 'apiplatform_lifecycle_status'",
        ];

        if ($filters['sort'] === 'popular') {
            $joins[] = "LEFT JOIN {$logs} l ON l.api_id = p.ID";
        }

        $where = [
            "LOWER(COALESCE(NULLIF(pm_visibility.meta_value, ''), r.visibility)) = 'public'",
            "p.post_type = 'user_api'",
            "p.post_status IN ('publish', 'draft', 'pending', 'private')",
            "LOWER(COALESCE(NULLIF(pm_status.meta_value, ''), 'active')) = 'active'",
            "LOWER(COALESCE(NULLIF(pm_lifecycle.meta_value, ''), CASE WHEN LOWER(COALESCE(NULLIF(pm_visibility.meta_value, ''), r.visibility)) = 'public' THEN 'public' ELSE 'private' END)) <> 'archived'",
        ];
        $args = [];

        if ($filters['category'] !== '') {
            $where[] = "COALESCE(NULLIF(pm_category.meta_value, ''), r.category) = %s";
            $args[] = $filters['category'];
        }

        if ($filters['tag'] !== '') {
            $where[] = 'pm_tag_slugs.meta_value LIKE %s';
            $args[] = '%' . $wpdb->esc_like('"' . $filters['tag'] . '"') . '%';
        }

        if ($filters['auth'] !== '') {
            $where[] = "REPLACE(COALESCE(NULLIF(pm_auth.meta_value, ''), 'api-key'), '_', '-') = %s";
            $args[] = $filters['auth'];
        }

        if ($filters['status'] !== '') {
            $where[] = "CASE WHEN LOWER(pm_version_status.meta_value) IN ('beta', 'deprecated') THEN LOWER(pm_version_status.meta_value) WHEN LOWER(COALESCE(NULLIF(pm_status.meta_value, ''), 'active')) = 'active' THEN 'active' ELSE 'deprecated' END = %s";
            $args[] = $filters['status'];
        }

        if ($filters['search'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $slug_like = '%' . $wpdb->esc_like(sanitize_title($filters['search'])) . '%';
            $where[] = '(p.post_title LIKE %s OR p.post_name LIKE %s OR pm_title.meta_value LIKE %s OR pm_summary.meta_value LIKE %s OR pm_description.meta_value LIKE %s OR pm_tags.meta_value LIKE %s OR pm_tag_slugs.meta_value LIKE %s OR COALESCE(NULLIF(pm_category.meta_value, \'\'), r.category) LIKE %s)';
            array_push($args, $like, $like, $like, $like, $like, $like, $slug_like, $slug_like);
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $join_sql = implode(' ', $joins);

        $order = 'COALESCE(r.updated_at, p.post_modified) DESC, p.ID DESC';
        if ($filters['sort'] === 'alphabetical') {
            $order = "COALESCE(NULLIF(pm_title.meta_value, ''), p.post_title) ASC, p.ID DESC";
        } elseif ($filters['sort'] === 'newest') {
            $order = 'p.post_date DESC, p.ID DESC';
        } elseif ($filters['sort'] === 'oldest') {
            $order = 'p.post_date ASC, p.ID ASC';
        } elseif ($filters['sort'] === 'popular') {
            $order = 'COUNT(l.id) DESC, COALESCE(r.updated_at, p.post_modified) DESC, p.ID DESC';
        }

        $id_sql = "SELECT p.ID FROM {$posts} p {$join_sql} {$where_sql} GROUP BY p.ID ORDER BY {$order}";
        $candidate_ids = $wpdb->get_col($args ? $wpdb->prepare($id_sql, $args) : $id_sql);
        $eligible_ids = self::filter_public_candidate_ids(array_map('absint', $candidate_ids), 'directory');
        $total = count($eligible_ids);
        $ids = array_slice($eligible_ids, ($filters['page'] - 1) * $filters['per_page'], $filters['per_page']);

        return [
            'ids' => array_map('absint', $ids),
            'total' => $total,
        ];
    }

    private static function filter_public_candidate_ids(array $ids, $route){
        $eligible = [];

        foreach (array_values(array_unique(array_filter(array_map('absint', $ids)))) as $api_id) {
            $post = get_post($api_id);
            if (!$post || $post->post_type !== 'user_api') {
                continue;
            }

            $card = self::api_card($post);
            $allowed = class_exists('APIPlatform_Lifecycle_Policy')
                ? APIPlatform_Lifecycle_Policy::public_route_allowed($card, $route)
                : (($card['visibility'] ?? '') === 'public' && ($card['status'] ?? '') === 'active');

            if ($allowed) {
                $eligible[] = $api_id;
            }
        }

        return $eligible;
    }

    private static function all_public_api_ids(){
        global $wpdb;

        $table = self::table();
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $visibility_key = self::meta_keys()['visibility'];
        $where = "LOWER(COALESCE(NULLIF(visibility.meta_value, ''), r.visibility)) = 'public' AND p.post_type = 'user_api' AND LOWER(COALESCE(NULLIF(status.meta_value, ''), 'active')) = 'active' AND LOWER(COALESCE(NULLIF(life.meta_value, ''), CASE WHEN LOWER(COALESCE(NULLIF(visibility.meta_value, ''), r.visibility)) = 'public' THEN 'public' ELSE 'private' END)) <> 'archived'";

        $candidate_ids = array_map('absint', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$posts} p LEFT JOIN {$table} r ON r.api_id = p.ID LEFT JOIN {$postmeta} visibility ON visibility.post_id = p.ID AND visibility.meta_key = %s LEFT JOIN {$postmeta} life ON life.post_id = p.ID AND life.meta_key = 'apiplatform_lifecycle_status' LEFT JOIN {$postmeta} status ON status.post_id = p.ID AND status.meta_key = 'apiplatform_status' WHERE {$where}", $visibility_key)));

        return self::filter_public_candidate_ids($candidate_ids, 'directory');
    }

    private static function endpoint_count(array $api_ids){
        $count = 0;
        foreach ($api_ids as $api_id) {
            $schema = class_exists('APIPlatform_Endpoint_Schema_Service')
                ? APIPlatform_Endpoint_Schema_Service::current_schema($api_id)
                : [];
            $count += count(is_array($schema['endpoints'] ?? null) ? $schema['endpoints'] : []);
        }
        return $count;
    }

    private static function publisher_count(array $api_ids){
        $publishers = [];
        foreach ($api_ids as $api_id) {
            if (class_exists('APIPlatform_Ownership_Service')) {
                $owner = APIPlatform_Ownership_Service::owner($api_id);
                if (!empty($owner['owner_type']) && !empty($owner['owner_id'])) {
                    $publishers[$owner['owner_type'] . ':' . absint($owner['owner_id'])] = true;
                }
            } else {
                $author = absint(get_post_field('post_author', $api_id));
                $owner = function_exists('apiplatform_get_team_owner') ? absint(apiplatform_get_team_owner($author)) : $author;
                if ($owner) {
                    $publishers['personal:' . $owner] = true;
                }
            }
        }
        return count($publishers);
    }

    private static function model_count(array $api_ids){
        $count = 0;
        foreach ($api_ids as $api_id) {
            if (!class_exists('APIPlatform_Endpoint_Schema_Service')) {
                continue;
            }
            $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api_id);
            $models = APIPlatform_Endpoint_Schema_Service::models($api_id, $schema['version'] ?? '');
            $count += count(is_array($models) ? $models : []);
        }
        return $count;
    }

    private static function sdk_enabled_count(array $api_ids){
        $count = 0;
        foreach ($api_ids as $api_id) {
            if (!class_exists('APIPlatform_SDK_Generator_Service')) {
                continue;
            }
            $settings = APIPlatform_SDK_Generator_Service::settings($api_id);
            if (!empty($settings['enabled'])) {
                $count++;
            }
        }
        return $count;
    }

    private static function updated_label($date){
        $timestamp = strtotime((string) $date);

        if (!$timestamp) {
            return 'Updated recently';
        }

        return sprintf('Updated %s', human_time_diff($timestamp, current_time('timestamp')) . ' ago');
    }

    public static function sync_registry($api_id, array $settings){
        global $wpdb;

        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? 'private')
            : (in_array($settings['visibility'] ?? 'private', self::visibility_options(), true) ? $settings['visibility'] : 'private');
        $slug = sanitize_title($settings['portal_slug'] ?? '');

        if ($slug === '') {
            return false;
        }

        $row = [
            'api_id' => absint($api_id),
            'portal_slug' => $slug,
            'visibility' => $visibility,
            'category' => sanitize_title($settings['category'] ?? ''),
            'updated_at' => current_time('mysql'),
            'published_at' => $settings['published_at'] ?? null,
        ];

        $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE api_id = %d LIMIT 1", $api_id));

        if ($existing_id) {
            return (bool) $wpdb->update(self::table(), $row, ['id' => (int) $existing_id]);
        }

        return (bool) $wpdb->insert(self::table(), $row);
    }

    public static function portal_slug_is_unique($api_id, $slug){
        global $wpdb;

        $slug = sanitize_title($slug);

        if ($slug === '') {
            return false;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT api_id FROM " . self::table() . " WHERE portal_slug = %s LIMIT 1",
            $slug
        ));

        if ($existing && (int) $existing !== (int) $api_id) {
            return false;
        }

        $posts = get_posts([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'apiplatform_portal_slug',
                    'value' => $slug,
                    'compare' => '=',
                ],
            ],
        ]);

        return empty($posts) || (int) $posts[0] === (int) $api_id;
    }
}
