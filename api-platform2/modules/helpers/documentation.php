<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'freedom_api_register_documentation_system');
add_filter('query_vars', 'freedom_api_register_documentation_query_vars');

function freedom_api_documentation_system_enabled(){

    return (bool) get_option('apiplatform_enable_documentation', false);
}

function freedom_api_docs_shortcode_alias_enabled(){

    return (bool) get_option('apiplatform_docs_shortcode_alias');
}

function freedom_api_register_documentation_system(){

    if (!freedom_api_documentation_system_enabled()) {
        freedom_api_documentation_debug('[DOCS CPT] Documentation system disabled');
        return;
    }

    register_post_type('documentation', [
        'label' => 'Documentation',
        'labels' => [
            'name' => 'Documentation',
            'singular_name' => 'Documentation',
            'add_new_item' => 'Add New Documentation',
            'edit_item' => 'Edit Documentation',
            'all_items' => 'All Documentation'
        ],
        'public' => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'hierarchical' => true,
        'rewrite' => [
            'slug' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::docs_prefix() : 'documentation',
            'with_front' => false
        ],
        'supports' => ['title', 'editor', 'excerpt', 'page-attributes', 'revisions'],
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-media-document'
    ]);

    register_taxonomy('documentation_category', ['documentation'], [
        'label' => 'Documentation Categories',
        'labels' => [
            'name' => 'Documentation Categories',
            'singular_name' => 'Documentation Category',
            'add_new_item' => 'Add New Documentation Category',
            'edit_item' => 'Edit Documentation Category'
        ],
        'hierarchical' => true,
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => false
    ]);

    register_post_meta('documentation', 'doc_method', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'sanitize_key',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_path', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_parameters', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'wp_kses_post',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_request', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'wp_kses_post',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_request_language', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'sanitize_key',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_response', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'wp_kses_post',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_response_language', [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'sanitize_callback' => 'sanitize_key',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    register_post_meta('documentation', 'doc_is_endpoint', [
        'single' => true,
        'type' => 'boolean',
        'show_in_rest' => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'auth_callback' => 'freedom_api_documentation_meta_auth'
    ]);

    $docs_prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::docs_prefix() : 'documentation';

    add_rewrite_rule(
        '^' . $docs_prefix . '/([^/]+)/([^/]+)/?$',
        'index.php?pagename=' . $docs_prefix . '&apiplatform_doc_category=$matches[1]&apiplatform_doc_post=$matches[2]',
        'top'
    );

    add_rewrite_rule(
        '^' . $docs_prefix . '/([^/]+)/?$',
        'index.php?pagename=' . $docs_prefix . '&apiplatform_doc_category=$matches[1]',
        'top'
    );

    freedom_api_documentation_debug('[DOCS CPT] documentation CPT, taxonomy, meta, and rewrite rules registered');
}

function freedom_api_documentation_meta_auth(){

    return current_user_can('edit_posts');
}

function freedom_api_register_documentation_query_vars($vars){

    $vars[] = 'apiplatform_doc_category';
    $vars[] = 'apiplatform_doc_post';

    return $vars;
}

function freedom_api_get_documentation_sections(){

    if (!freedom_api_documentation_system_enabled()) {
        return [];
    }

    $terms = get_terms([
        'taxonomy' => 'documentation_category',
        'hide_empty' => false,
        'orderby' => 'term_order',
        'order' => 'ASC'
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        freedom_api_documentation_debug('[DOCS CPT] No documentation categories found');
        return [];
    }

    $sections = [];

    foreach ($terms as $term) {
        $posts = get_posts([
            'post_type' => 'documentation',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => 'documentation_category',
                    'field' => 'term_id',
                    'terms' => $term->term_id
                ]
            ],
            'no_found_rows' => true
        ]);

        if (empty($posts)) {
            continue;
        }

        $section = [
            'id' => $term->slug,
            'label' => $term->name,
            'description' => $term->description,
            'posts' => array_map('freedom_api_prepare_documentation_post', $posts),
            'endpoints' => []
        ];

        foreach ($section['posts'] as $post) {
            if (!empty($post['is_endpoint'])) {
                $section['endpoints'][] = $post['endpoint'];
            }
        }

        $sections[] = $section;

        freedom_api_documentation_debug('[DOCS CPT] Category loaded: ' . $term->slug . ' posts: ' . count($posts));
    }

    return $sections;
}

function freedom_api_ensure_default_documentation_categories(){

    if (!freedom_api_documentation_system_enabled()) {
        return;
    }

    $categories = [
        'Getting Started',
        'Authentication',
        'API Keys',
        'Rate Limits',
        'API Reference',
        'Marketplace',
        'Analytics',
        'Billing'
    ];

    foreach ($categories as $category) {
        if (!term_exists($category, 'documentation_category')) {
            wp_insert_term($category, 'documentation_category', [
                'slug' => sanitize_title($category)
            ]);
        }
    }

    freedom_api_documentation_debug('[DOCS CPT] Default documentation categories ensured');
}

function freedom_api_prepare_documentation_post($post){

    $parameters = freedom_api_decode_documentation_json_meta($post->ID, 'doc_parameters', []);
    $is_endpoint = (bool) get_post_meta($post->ID, 'doc_is_endpoint', true);
    $method = strtoupper(get_post_meta($post->ID, 'doc_method', true) ?: 'GET');
    $path = get_post_meta($post->ID, 'doc_path', true);

    if ($path) {
        $is_endpoint = true;
    }

    return [
        'id' => $post->post_name,
        'post_id' => $post->ID,
        'parent_id' => $post->post_parent,
        'title' => get_the_title($post),
        'description' => has_excerpt($post) ? get_the_excerpt($post) : '',
        'content' => apply_filters('the_content', $post->post_content),
        'is_endpoint' => $is_endpoint,
        'endpoint' => [
            'id' => $post->post_name,
            'method' => $method,
            'title' => get_the_title($post),
            'path' => $path,
            'description' => has_excerpt($post) ? get_the_excerpt($post) : wp_strip_all_tags($post->post_content),
            'parameters' => is_array($parameters) ? $parameters : [],
            'request' => get_post_meta($post->ID, 'doc_request', true),
            'request_language' => get_post_meta($post->ID, 'doc_request_language', true) ?: 'json',
            'response' => get_post_meta($post->ID, 'doc_response', true),
            'response_language' => get_post_meta($post->ID, 'doc_response_language', true) ?: 'json'
        ]
    ];
}

function freedom_api_decode_documentation_json_meta($post_id, $meta_key, $default){

    $raw = get_post_meta($post_id, $meta_key, true);

    if (!$raw) {
        return $default;
    }

    $decoded = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        freedom_api_documentation_debug('[DOCS CPT] Invalid JSON meta ' . $meta_key . ' for post ' . $post_id . ': ' . json_last_error_msg());
        return $default;
    }

    return $decoded;
}

function freedom_api_documentation_debug($message){

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($message);
    }
}
