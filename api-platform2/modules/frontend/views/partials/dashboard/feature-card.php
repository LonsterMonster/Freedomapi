<?php

$feature = $feature ?? [];

$status = $feature['display_status'] ?? $feature['status'] ?? 'Coming Soon';

$content = '<div class="apiplatform-feature-card">';

$content .= '<div class="apiplatform-feature-card-head">';

$content .= '<span class="apiplatform-feature-icon">' . esc_html($feature['icon'] ?? 'Feature') . '</span>';

$content .= '<span class="apiplatform-feature-status">' . esc_html($status) . '</span>';

$content .= '</div>';

$content .= '<h4>' . esc_html($feature['title'] ?? '') . '</h4>';

$content .= '<p>' . esc_html($feature['description'] ?? '') . '</p>';

$content .= '</div>';

echo $content;
?>
