<?php
/** FreedomAPI public extension contract, version 1.0.0. */
if (!defined('ABSPATH')) exit;

function freedomapi_extension_version() { return '1.0.0'; }

function freedomapi_can_manage_api($api_id) {
    $post = get_post(absint($api_id));
    $user = get_current_user_id();
    return $user && $post && $post->post_type === 'user_api'
        && APIPlatform_Ownership_Service::can($user, $post->ID, 'apis.edit')
        && APIPlatform_Ownership_Service::can($user, $post->ID, 'apis.manage_schema');
}

function freedomapi_extension_error($message, $code = 'invalid_transformation', $status = 400) {
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Internal persistence is deliberately confined to Core. */
function freedomapi_extension_state($api_id) {
    $raw = get_post_meta($api_id, '_freedomapi_extension_configuration', true);
    if ($raw === '') return ['revision' => 0, 'active' => null, 'history' => []];
    $decoded = json_decode((string) $raw);
    if (!is_object($decoded) || !isset($decoded->revision, $decoded->history) || !property_exists($decoded, 'active')) {
        throw new RuntimeException('FreedomAPI extension configuration is malformed.');
    }
    $state = (array) $decoded;
    $restore = function ($active) {
        if ($active === null) return null;
        $active = (array) $active;
        $active['plan'] = json_decode(wp_json_encode($active['plan']), true);
        $active['schema'] = (array) $active['schema'];
        return $active;
    };
    $state['active'] = $restore($state['active']);
    $state['history'] = array_map($restore, $state['history']);
    return $state;
}

function freedomapi_get_api_configuration($api_id) {
    if (!freedomapi_can_manage_api($api_id)) return freedomapi_extension_error('You cannot manage this API.', 'forbidden', 403);
    $schema = APIPlatform_Endpoint_Schema_Service::current_schema($api_id, false);
    unset($schema['updated_at']); // Core normalizes this timestamp on every read.
    $runtime = get_post_meta($api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal';
    $raw = get_post_meta($api_id, 'api_json', true);
    if ($raw === '') {
        $logic = json_decode((string) get_post_meta($api_id, 'api_logic', true), true);
        foreach (($logic['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') === 'response') { $raw = wp_json_encode($step['data']); break; }
        }
    }
    // Never execute an endpoint or contact an upstream service to obtain a preview.
    if ($raw === '' && $runtime === 'internal') {
        if (get_post_meta($api_id, 'api_builder_components', true) !== '') return freedomapi_extension_error('Save a static JSON response before editing this component-only API.');
        $raw = '{"message":"Hello"}';
    }
    if ($runtime !== 'internal') $raw = wp_json_encode($schema['endpoints'][0]['responses'][0]['example'] ?? null);
    if (strlen((string) $raw) > 262144) return freedomapi_extension_error('Source exceeds the 256 KiB preview limit.');
    $source = json_decode((string) $raw, false, 32);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($source)) {
        return freedomapi_extension_error('Version 1 requires a JSON object response or a documented object example.');
    }
    $state = freedomapi_extension_state($api_id);
    $fingerprint = hash('sha256', wp_json_encode([$schema, $raw, $runtime, $state['revision'],
        get_post_meta($api_id, 'api_builder_components', true), get_post_meta($api_id, 'apiplatform_gateway_proxy_url', true)]));
    return ['api_id' => (int) $api_id, 'revision' => $state['revision'], 'fingerprint' => $fingerprint,
        'schema' => $schema, 'source' => $source, 'runtime' => $runtime,
        'active' => $state['active'], 'can_rollback' => !empty($state['history'])];
}

/** Paths are arrays of object property names. No code, templates, URL fetches or expressions. */
function freedomapi_validate_transformation($plan) {
    if (!is_array($plan) || array_diff(array_keys($plan), ['version', 'operations']) || ($plan['version'] ?? null) !== 1
        || !isset($plan['operations']) || !is_array($plan['operations']) || !array_is_list($plan['operations'])
        || count($plan['operations']) > 32 || strlen(wp_json_encode($plan)) > 32768) {
        return freedomapi_extension_error('Invalid or oversized transformation.');
    }
    foreach ($plan['operations'] as $op) {
        if (!is_array($op) || array_diff(array_keys($op), ['op', 'path', 'from', 'value_json'])
            || !in_array($op['op'] ?? '', ['set', 'remove', 'copy', 'move'], true)) return freedomapi_extension_error('Unsupported operation.');
        foreach (['path', 'from'] as $key) {
            $path = $op[$key] ?? null;
            if (!is_array($path) || !array_is_list($path) || count($path) > 12
                || ($key === 'path' && !$path)
                || ($key === 'from' && in_array($op['op'], ['copy', 'move'], true) && !$path)) return freedomapi_extension_error('Invalid property path.');
            foreach ($path as $part) if (!is_string($part) || $part === '' || strlen($part) > 128 || preg_match('/[\x00-\x1f]/', $part)
                || in_array($part, ['__proto__', 'prototype', 'constructor'], true)) return freedomapi_extension_error('Invalid property name.');
        }
        if (!isset($op['value_json']) || !is_string($op['value_json'])) return freedomapi_extension_error('A JSON literal string is required.');
        json_decode($op['value_json'], false, 16);
        if (json_last_error() !== JSON_ERROR_NONE) return freedomapi_extension_error('Invalid JSON literal.');
        if ($op['op'] === 'move' && array_slice($op['path'], 0, count($op['from'])) === $op['from']) return freedomapi_extension_error('Cannot move a property into itself.');
    }
    return true;
}

function freedomapi_extension_parent(&$value, $path, &$ok) {
    $cursor =& $value;
    array_pop($path);
    foreach ($path as $part) {
        if (!is_object($cursor) || !property_exists($cursor, $part)) { $ok = false; return null; }
        $cursor =& $cursor->{$part};
    }
    if (!is_object($cursor)) { $ok = false; return null; }
    return $cursor;
}

function freedomapi_execute_transformation($source, $plan) {
    $valid = freedomapi_validate_transformation($plan);
    if (is_wp_error($valid)) return $valid;
    $encoded = wp_json_encode($source);
    if ($encoded === false || strlen($encoded) > 262144) return freedomapi_extension_error('Response exceeds the 256 KiB transformation limit.');
    $output = json_decode($encoded, false, 32); // Deep copy; never mutate the original preview.
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($output)) return freedomapi_extension_error('Expected a JSON object.');
    foreach ($plan['operations'] as $op) {
        $ok = true;
        $parent = freedomapi_extension_parent($output, $op['path'], $ok);
        $name = end($op['path']);
        if (!$ok) return freedomapi_extension_error('Destination parent does not exist.');
        if ($op['op'] === 'remove') {
            if (!property_exists($parent, $name)) return freedomapi_extension_error('Property to remove does not exist.');
            unset($parent->{$name});
        } elseif ($op['op'] === 'set') {
            $parent->{$name} = json_decode($op['value_json']);
        } else {
            $from = freedomapi_extension_parent($output, $op['from'], $ok);
            $key = end($op['from']);
            if (!$ok || !property_exists($from, $key)) return freedomapi_extension_error('Source property does not exist.');
            $parent->{$name} = json_decode(wp_json_encode($from->{$key}));
            if ($op['op'] === 'move') unset($from->{$key});
        }
        if (strlen(wp_json_encode($output)) > 262144) return freedomapi_extension_error('Transformed response is too large.');
    }
    return $output;
}

/** Bounded JSON Schema subset used as the approved output contract. */
function freedomapi_infer_response_schema($value, $depth = 0) {
    if ($depth > 12) return freedomapi_extension_error('Response nesting exceeds 12 levels.');
    if (is_object($value)) {
        if (count(get_object_vars($value)) > 128) return freedomapi_extension_error('An object may have at most 128 properties.');
        $properties = [];
        foreach (get_object_vars($value) as $key => $child) {
            $properties[$key] = freedomapi_infer_response_schema($child, $depth + 1);
            if (is_wp_error($properties[$key])) return $properties[$key];
        }
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
    if (is_array($value)) {
        $variants = [];
        foreach ($value as $child) {
            $item = freedomapi_infer_response_schema($child, $depth + 1);
            if (is_wp_error($item)) return $item;
            $variants[wp_json_encode($item)] = $item;
            if (count($variants) > 32) return freedomapi_extension_error('An array may have at most 32 distinct item schemas.');
        }
        return ['type' => 'array', 'items' => $variants ? ['anyOf' => array_values($variants)] : new stdClass()];
    }
    return ['type' => $value === null ? 'null' : (is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'number' : 'string')))];
}

function freedomapi_validate_response_schema($value, $schema, $depth = 0) {
    $schema = (array) $schema;
    if ($depth > 16) return false;
    if (!$schema) return true;
    if (isset($schema['anyOf'])) {
        foreach ($schema['anyOf'] as $variant) if (freedomapi_validate_response_schema($value, $variant, $depth + 1)) return true;
        return false;
    }
    switch ($schema['type'] ?? '') {
        case 'object':
            if (!is_object($value)) return false;
            $properties = (array) $schema['properties'];
            foreach ($schema['required'] as $key) if (!property_exists($value, $key)) return false;
            foreach (get_object_vars($value) as $key => $child) {
                if (!array_key_exists($key, $properties) || !freedomapi_validate_response_schema($child, $properties[$key], $depth + 1)) return false;
            }
            return true;
        case 'array':
            if (!is_array($value)) return false;
            foreach ($value as $child) if (!freedomapi_validate_response_schema($child, $schema['items'], $depth + 1)) return false;
            return true;
        case 'null': return $value === null;
        case 'boolean': return is_bool($value);
        case 'integer': return is_int($value);
        case 'number': return is_int($value) || is_float($value);
        case 'string': return is_string($value);
    }
    return false;
}

function freedomapi_preview_transformation($api_id, $plan, $fingerprint) {
    $config = freedomapi_get_api_configuration($api_id);
    if (is_wp_error($config)) return $config;
    if (!is_string($fingerprint) || !hash_equals($config['fingerprint'], $fingerprint)) return freedomapi_extension_error('Configuration changed. Generate a new preview.', 'conflict', 409);
    $proposed = freedomapi_execute_transformation($config['source'], $plan);
    if (is_wp_error($proposed)) return $proposed;
    $schema = freedomapi_infer_response_schema($proposed);
    if (is_wp_error($schema)) return $schema;
    $original = $config['source'];
    if (!empty($config['active']) && $config['active']['version_label'] === $config['schema']['version']) {
        $original = freedomapi_execute_transformation($original, $config['active']['plan']);
        if (is_wp_error($original)) return $original;
    }
    return ['original' => $original, 'source' => $config['source'], 'proposed' => $proposed, 'output_schema' => $schema,
        'plan' => $plan, 'fingerprint' => $fingerprint, 'revision' => $config['revision'], 'version_label' => $config['schema']['version']];
}

/** An atomic compare-and-swap of a single Core-owned record includes history. */
function freedomapi_update_api_configuration($api_id, $plan, $fingerprint) {
    $preview = freedomapi_preview_transformation($api_id, $plan, $fingerprint);
    if (is_wp_error($preview)) return $preview;
    $old = freedomapi_extension_state($api_id);
    if ($old['revision'] !== $preview['revision']) return freedomapi_extension_error('Configuration changed.', 'conflict', 409);
    $new = $old;
    $new['history'][] = $old['active'];
    $new['history'] = array_slice($new['history'], -10);
    $new['active'] = ['plan' => $plan, 'schema' => $preview['output_schema'], 'example' => $preview['proposed'], 'version_label' => $preview['version_label']];
    return freedomapi_extension_commit($api_id, $old, $new, 'approved', $fingerprint);
}

function freedomapi_rollback_api_configuration($api_id, $fingerprint) {
    $config = freedomapi_get_api_configuration($api_id);
    if (is_wp_error($config)) return $config;
    if (!is_string($fingerprint) || !hash_equals($config['fingerprint'], $fingerprint)) return freedomapi_extension_error('Configuration changed. Reload before rollback.', 'conflict', 409);
    $old = freedomapi_extension_state($api_id);
    if (!$old['history']) return freedomapi_extension_error('No previous configuration.');
    $new = $old;
    $new['active'] = array_pop($new['history']);
    if ($new['active'] && $new['active']['version_label'] === $config['schema']['version']) {
        $restored = freedomapi_execute_transformation($config['source'], $new['active']['plan']);
        if (is_wp_error($restored) || !freedomapi_validate_response_schema($restored, $new['active']['schema'])) {
            return freedomapi_extension_error('The previous transformation no longer matches the source JSON. Restore the source in Core before rolling back.');
        }
    }
    return freedomapi_extension_commit($api_id, $old, $new, 'rolled_back', $fingerprint);
}

function freedomapi_extension_commit($api_id, $old, $new, $event, $fingerprint) {
    // add_option has a unique database key: serialize first-use initialization too.
    $lock = 'freedomapi_extension_lock_' . absint($api_id);
    if (!add_option($lock, time(), '', false)) return freedomapi_extension_error('Another update is in progress.', 'conflict', 409);
    try {
        $fresh = freedomapi_get_api_configuration($api_id);
        if (is_wp_error($fresh)) return $fresh;
        if (!hash_equals($fresh['fingerprint'], $fingerprint)) return freedomapi_extension_error('Configuration changed.', 'conflict', 409);
        if (serialize(freedomapi_extension_state($api_id)) !== serialize($old)) return freedomapi_extension_error('Configuration changed.', 'conflict', 409);
        $new['revision'] = $old['revision'] + 1;
        $new['updated_by'] = get_current_user_id();
        $new['updated_at'] = time();
        $stored = get_post_meta($api_id, '_freedomapi_extension_configuration', true);
        // Encode once before slashing: wp_slash does not recurse into stdClass,
        // whereas WordPress's metadata unslashing does. JSON preserves {} and [].
        $encoded = wp_json_encode($new);
        $ok = $stored === '' ? add_post_meta($api_id, '_freedomapi_extension_configuration', wp_slash($encoded), true)
            : update_post_meta($api_id, '_freedomapi_extension_configuration', wp_slash($encoded), $stored);
        if (!$ok) return freedomapi_extension_error('Configuration could not be saved.', 'storage_error', 500);
    } finally { delete_option($lock); }
    do_action('freedomapi_api_configuration_changed', (int) $api_id, $new['revision'], $event, get_current_user_id());
    return ['revision' => $new['revision'], 'can_rollback' => !empty($new['history'])];
}

function freedomapi_has_response_transformation($api_id, $version = '') {
    $active = freedomapi_extension_state($api_id)['active'];
    return !empty($active) && ($version === '' || $active['version_label'] === $version);
}

function freedomapi_transform_response($api_id, $body, $version, $status = 200) {
    $state = freedomapi_extension_state($api_id);
    $active = $state['active'];
    if (!$active || $status !== 200 || $active['version_label'] !== $version) return $body;
    $result = freedomapi_execute_transformation($body, $active['plan']);
    if (is_wp_error($result) || !freedomapi_validate_response_schema($result, $active['schema'])) {
        return freedomapi_extension_error('Response transformation or schema validation failed.', 'response_transformation_failed', 500);
    }
    return $result;
}

/** Expose the approved response contract while leaving requests/error contracts untouched. */
function freedomapi_extension_schema($api_id, $schema) {
    $active = freedomapi_extension_state($api_id)['active'];
    if ($active && $active['version_label'] === $schema['version']) {
        foreach ($schema['endpoints'] as &$endpoint) {
            foreach ($endpoint['responses'] as &$response) {
                if ((int) $response['status'] === 200) {
                    $response['freedomapi_output_schema'] = $active['schema'];
                    $response['example'] = $active['example'];
                }
            }
            unset($response);
        }
        unset($endpoint);
    }
    return apply_filters('freedomapi_endpoint_schema', $schema, $api_id);
}

add_action('plugins_loaded', function () { do_action('freedomapi_extensions_ready', freedomapi_extension_version()); }, 25);
