<?php

$title = $title ?? 'Documentation';
$intro = $intro ?? '';
$sections = is_array($sections ?? null) ? $sections : [];
$active_section = is_array($active_section ?? null) ? $active_section : [];
$active_post = is_array($active_post ?? null) ? $active_post : [];
$docs_base_url = $docs_base_url ?? '';
$breadcrumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
$platform_docs = !empty($platform_docs);

$cpt_enabled = !$platform_docs && function_exists('freedom_api_documentation_system_enabled') && freedom_api_documentation_system_enabled();

$content = '<div class="apiplatform-docs">';

$content .= '<header class="apiplatform-docs-header">';
$content .= '<p class="apiplatform-docs-kicker">Developer Docs</p>';
$content .= '<h1>' . esc_html($title) . '</h1>';
if ($intro) {
    $content .= '<p>' . esc_html($intro) . '</p>';
}
$content .= '</header>';

$content .= '<div class="apiplatform-docs-layout">';

$content .= '<div class="apiplatform-docs-nav" role="navigation" aria-label="Documentation sections">';
$content .= '<div class="apiplatform-docs-nav-title">Sections</div>';
$content .= '<ul>';

foreach ($sections as $section) {
    $section_id = $section['id'] ?? '';
    $section_url = $section['url'] ?? add_query_arg('doc', $section_id, $docs_base_url);
    if ($platform_docs && !empty($section['posts'][0]['url'])) {
        $section_url = $section['posts'][0]['url'];
    }
    $section_label = $section['label'] ?? '';
    $is_current = $section_id === ($current_doc ?? '');

    $content .= '<li><a href="' . esc_url($section_url) . '"' . ($is_current ? ' class="is-active" aria-current="page"' : '') . '>' . esc_html($section_label) . '</a>';

    if ($cpt_enabled) {
        if (!empty($section['posts']) && is_array($section['posts'])) {
            if (class_exists('APIPlatform_Navigation_Builder')) {
                $post_tree = APIPlatform_Navigation_Builder::build_tree($section['posts']);
                if (!empty($post_tree)) {
                    $content .= APIPlatform_Navigation_Builder::render_tree($post_tree, $current_post ?? '');
                }
            }
        }
    } else {
        $nav_posts = [];

        if (!empty($section['posts']) && is_array($section['posts'])) {
            $nav_posts = $section['posts'];
        } elseif (!empty($section['endpoints']) && is_array($section['endpoints'])) {
            $nav_posts = $section['endpoints'];
        }

        if ($is_current && $nav_posts) {
            $content .= '<ul>';
            foreach ($nav_posts as $doc_post) {
                $post_id = $doc_post['id'] ?? '';
                $post_url = $doc_post['url'] ?? '#' . $post_id;
                $is_current_post = $post_id === ($current_post ?? '');
                $post_link_class = $is_current_post ? ' class="is-active" aria-current="page"' : '';

                $content .= '<li><a href="' . esc_url($post_url) . '"' . $post_link_class . '>' . esc_html($doc_post['title']) . '</a></li>';
            }
            $content .= '</ul>';
        }
    }

    $content .= '</li>';
}
$content .= '</ul>';
$content .= '</div>';

$content .= '<div class="apiplatform-docs-content">';

if ($breadcrumbs) {
    $content .= '<nav class="apiplatform-docs-breadcrumbs" aria-label="Documentation breadcrumbs">';
    $content .= esc_html(implode(' > ', $breadcrumbs));
    $content .= '</nav>';
}

$content .= APIPlatform_Renderer::partial('documentation/section', [
    'section' => $active_section,
    'active_post' => $active_post
]);

$content .= '</div>';
$content .= '</div>';
$content .= '</div>';

echo $content;
