<?php

$section = is_array($section ?? null) ? $section : [];
$id = $section['id'] ?? '';
$label = $section['label'] ?? '';
$description = $section['description'] ?? '';
$items = is_array($section['items'] ?? null) ? $section['items'] : [];
$examples = is_array($section['examples'] ?? null) ? $section['examples'] : [];
$posts = is_array($section['posts'] ?? null) ? $section['posts'] : [];
$endpoints = is_array($section['endpoints'] ?? null) ? $section['endpoints'] : [];
$active_post = is_array($active_post ?? null) ? $active_post : [];

$platform_docs = !empty($active_post['markdown']);
$cpt_enabled = !$platform_docs && function_exists('freedom_api_documentation_system_enabled') && freedom_api_documentation_system_enabled();

if ($active_post) {
    if ($platform_docs) {
        $content = '<article class="apiplatform-docs-post apiplatform-docs-markdown" id="' . esc_attr($active_post['id'] ?? '') . '">';
        $is_landing = !empty($active_post['landing']);
        if (!$is_landing) {
            $content .= '<header class="apiplatform-docs-article-header">';
            $content .= '<p class="apiplatform-docs-article-kicker">FreedomAPI platform guide</p>';
            $content .= '<h1>' . esc_html($active_post['title'] ?? '') . '</h1>';
            if (!empty($active_post['description'])) {
                $content .= '<p class="apiplatform-docs-article-description">' . esc_html($active_post['description']) . '</p>';
            }
            $content .= '</header>';
        }
        if (!$is_landing && !empty($active_post['toc']) && is_array($active_post['toc'])) {
            $content .= '<nav class="apiplatform-docs-toc" aria-label="On this page"><strong>On this page</strong><ul>';
            foreach ($active_post['toc'] as $toc_item) {
                $content .= '<li class="apiplatform-docs-toc-level-' . absint($toc_item['level'] ?? 1) . '"><a href="#' . esc_attr($toc_item['id'] ?? '') . '">' . esc_html($toc_item['title'] ?? '') . '</a></li>';
            }
            $content .= '</ul></nav>';
        }
        $content .= '<div class="apiplatform-docs-post-body">' . wp_kses_post($active_post['content'] ?? '') . '</div>';
        $adjacent = !$is_landing && is_array($active_post['adjacent'] ?? null) ? $active_post['adjacent'] : [];
        $previous = is_array($adjacent['previous'] ?? null) ? $adjacent['previous'] : [];
        $next = is_array($adjacent['next'] ?? null) ? $adjacent['next'] : [];
        if ($previous || $next) {
            $content .= '<nav class="apiplatform-docs-prev-next" aria-label="Document navigation">';
            if ($previous) {
                $content .= '<a class="apiplatform-docs-prev-next-link is-previous" href="' . esc_url($previous['url'] ?? '#') . '"><span>← Previous</span><strong>' . esc_html($previous['title'] ?? '') . '</strong></a>';
            } else {
                $content .= '<span></span>';
            }
            if ($next) {
                $content .= '<a class="apiplatform-docs-prev-next-link is-next" href="' . esc_url($next['url'] ?? '#') . '"><span>Next →</span><strong>' . esc_html($next['title'] ?? '') . '</strong></a>';
            }
            $content .= '</nav>';
        }
        $content .= '</article>';
        echo APIPlatform_Renderer::component('card', [
            'class' => 'apiplatform-docs-card apiplatform-docs-post-card',
            'content' => $content
        ]);
    } elseif (!empty($active_post['is_endpoint'])) {
        echo APIPlatform_Renderer::partial('documentation/endpoint', [
            'endpoint' => $active_post['endpoint']
        ]);
    } else {
        echo APIPlatform_Renderer::partial('documentation/post-card', [
            'post' => $active_post
        ]);
    }

    return;
}

$content = '<article class="apiplatform-docs-block" id="' . esc_attr($id) . '">';
$content .= '<div class="apiplatform-docs-block-heading">';
$content .= '<h2>' . esc_html($label) . '</h2>';
if ($description) {
    $content .= '<p>' . esc_html($description) . '</p>';
}
$content .= '</div>';

if ($cpt_enabled && $posts) {
    $content .= '<ul class="apiplatform-docs-section-post-list">';

    foreach ($posts as $doc_post) {
        $post_url = $doc_post['url'] ?? '#';
        $post_title = $doc_post['title'] ?? '';
        $post_description = $doc_post['description'] ?? '';

        $content .= '<li class="apiplatform-docs-section-post-item">';
        $content .= '<a href="' . esc_url($post_url) . '" class="apiplatform-docs-section-post-link">' . esc_html($post_title) . '</a>';

        if ($post_description) {
            $content .= '<p class="apiplatform-docs-section-post-desc">' . esc_html($post_description) . '</p>';
        }

        $content .= '</li>';
    }

    $content .= '</ul>';
} else {
    if ($items) {
        $content .= '<ul class="apiplatform-docs-list">';
        foreach ($items as $item) {
            $content .= '<li>' . esc_html($item) . '</li>';
        }
        $content .= '</ul>';
    }

    foreach ($examples as $index => $example) {
        $content .= APIPlatform_Renderer::partial('documentation/code-example', [
            'example' => $example,
            'id' => $id . '-example-' . $index
        ]);
    }
}

$content .= '</article>';

echo APIPlatform_Renderer::component('card', [
    'class' => 'apiplatform-docs-card',
    'content' => $content
]);

if (!$cpt_enabled && $endpoints) {
    echo '<div class="apiplatform-docs-endpoint-list">';

    foreach ($endpoints as $endpoint) {
        echo APIPlatform_Renderer::partial('documentation/endpoint', [
            'endpoint' => $endpoint
        ]);
    }

    echo '</div>';
}
