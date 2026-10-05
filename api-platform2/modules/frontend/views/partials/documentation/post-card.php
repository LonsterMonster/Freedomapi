<?php

$post = is_array($post ?? null) ? $post : [];
$id = $post['id'] ?? '';
$title = $post['title'] ?? '';
$description = $post['description'] ?? '';
$body = $post['content'] ?? '';

$content = '<article class="apiplatform-docs-post" id="' . esc_attr($id) . '">';
$content .= '<h2 class="apiplatform-docs-post-title">' . esc_html($title) . '</h2>';

if ($description) {
    $content .= '<p>' . esc_html($description) . '</p>';
}

if ($body) {
    $content .= '<div class="apiplatform-docs-post-body">' . wp_kses_post($body) . '</div>';
}

$content .= '</article>';

echo APIPlatform_Renderer::component('card', [
    'class' => 'apiplatform-docs-card apiplatform-docs-post-card',
    'content' => $content
]);
