<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Category_Page {

    public function __construct(){
        add_shortcode('category_page', [$this, 'render']);
    }

    public function render($atts){
        $atts = shortcode_atts([
            'taxonomy'         => '',
            'term'             => '',
            'post_type'        => '',
            'show_description' => 'true',
            'show_count'       => 'true',
            'show_excerpt'     => 'true',
            'layout'           => 'list',
        ], $atts, 'category_page');

        $taxonomy = sanitize_key($atts['taxonomy']);
        $term_slug = sanitize_key($atts['term']);
        $post_type = $atts['post_type'] ? sanitize_key($atts['post_type']) : '';
        $show_description = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
        $show_excerpt = filter_var($atts['show_excerpt'], FILTER_VALIDATE_BOOLEAN);
        $layout = in_array($atts['layout'], ['list', 'cards'], true) ? $atts['layout'] : 'list';

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return '<p>' . esc_html__('Taxonomy not found.', 'apiplatform') . '</p>';
        }

        $term = get_term_by('slug', $term_slug, $taxonomy);

        if (!$term || is_wp_error($term)) {
            return '<p>' . esc_html__('Category not found.', 'apiplatform') . '</p>';
        }

        $query_args = [
            'post_type'      => $post_type ?: get_post_types(['public' => true], 'names'),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'tax_query'      => [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $term_slug,
                ],
            ],
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $posts_query = new WP_Query($query_args);
        $posts = $posts_query->posts;

        $data = [
            'term_name'        => $term->name,
            'term_description' => term_description($term),
            'post_count'       => $term->count,
            'show_description' => $show_description,
            'show_count'       => $show_count,
            'show_excerpt'     => $show_excerpt,
            'layout'           => $layout,
            'posts'            => $posts,
        ];

        return APIPlatform_Renderer::partial('category-page/page', $data);
    }
}
