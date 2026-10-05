<?php

$term_name = $term_name ?? '';
$term_description = $term_description ?? '';
$post_count = $post_count ?? 0;
$show_description = !empty($show_description);
$show_count = !empty($show_count);
$show_excerpt = !empty($show_excerpt);
$layout = ($layout ?? 'list') === 'cards' ? 'cards' : 'list';
$posts = is_array($posts ?? null) ? $posts : [];

$content = '';

if ($term_name) {
    $content .= '<h1>' . esc_html($term_name) . '</h1>';
}

if ($show_description && $term_description) {
    $content .= wp_kses_post(wpautop($term_description));
}

if ($show_count) {
    $content .= '<p>' . esc_html(sprintf(
        _n('%d article', '%d articles', $post_count, 'apiplatform'),
        $post_count
    )) . '</p>';
}

if (empty($posts)) {
    $content .= '<p>' . esc_html__('No content is available in this category yet.', 'apiplatform') . '</p>';
    echo $content;
    return;
}

if ($layout === 'cards') {
    $content .= '<div class="apiplatform-category-page-grid">';
    foreach ($posts as $post) {
        $post_title = get_the_title($post);
        $post_url = get_permalink($post);
        $post_excerpt = $show_excerpt ? get_the_excerpt($post) : '';
        $modified_date = get_the_modified_date('', $post);

        $card_content = '<h3><a href="' . esc_url($post_url) . '">' . esc_html($post_title) . '</a></h3>';

        if ($post_excerpt) {
            $card_content .= '<p>' . esc_html($post_excerpt) . '</p>';
        }

        if ($modified_date) {
            $card_content .= '<small>' . esc_html(sprintf(
                /* translators: %s: last modified date */
                __('Last modified: %s', 'apiplatform'),
                $modified_date
            )) . '</small>';
        }

        $content .= APIPlatform_Renderer::component('card', [
            'class' => 'apiplatform-category-page-card',
            'content' => $card_content,
        ]);
    }
    $content .= '</div>';
} else {
    $content .= '<ul class="apiplatform-category-page-list">';
    foreach ($posts as $post) {
        $post_title = get_the_title($post);
        $post_url = get_permalink($post);
        $post_excerpt = $show_excerpt ? get_the_excerpt($post) : '';
        $modified_date = get_the_modified_date('', $post);

        $item = '<a href="' . esc_url($post_url) . '">' . esc_html($post_title) . '</a>';

        if ($post_excerpt) {
            $item .= '<p>' . esc_html($post_excerpt) . '</p>';
        }

        if ($modified_date) {
            $item .= '<small>' . esc_html(sprintf(
                __('Last modified: %s', 'apiplatform'),
                $modified_date
            )) . '</small>';
        }

        $content .= '<li>' . $item . '</li>';
    }
    $content .= '</ul>';
}

echo $content;
