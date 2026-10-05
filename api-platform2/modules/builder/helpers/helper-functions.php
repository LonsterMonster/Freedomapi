<?php
if (!defined('ABSPATH')) exit;

/**
 * Decode JSON into an array without throwing notices.
 */
function apiplatform_builder_decode_json($json, $fallback = []){
    if (!is_string($json) || trim($json) === '') {
        return $fallback;
    }

    $decoded = json_decode($json, true);

    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $fallback;
}

/**
 * Detect whether saved API logic is the new component-tree format.
 */
function apiplatform_builder_is_component_tree($logic){
    if (is_string($logic)) {
        $logic = apiplatform_builder_decode_json($logic, []);
    }

    return is_array($logic) && isset($logic['components']) && is_array($logic['components']);
}

/**
 * Recursively sanitize component props before saving.
 */
function apiplatform_builder_sanitize_props($value){
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $clean[sanitize_key($key)] = apiplatform_builder_sanitize_props($item);
        }
        return $clean;
    }

    if (is_scalar($value)) {
        return sanitize_textarea_field((string) $value);
    }

    return '';
}

/**
 * Normalize a component tree before saving it to post meta.
 */
function apiplatform_builder_normalize_component_tree($tree){
    if (!is_array($tree)) {
        return ['version' => 1, 'components' => []];
    }

    $normalized = [
        'version' => isset($tree['version']) ? absint($tree['version']) : 1,
        'components' => [],
    ];

    if (empty($tree['components']) || !is_array($tree['components'])) {
        return $normalized;
    }

    foreach ($tree['components'] as $component) {
        if (!is_array($component) || empty($component['type'])) {
            continue;
        }

        $normalized['components'][] = [
            'type'  => sanitize_key($component['type']),
            'props' => apiplatform_builder_sanitize_props($component['props'] ?? []),
        ];
    }

    return $normalized;
}
