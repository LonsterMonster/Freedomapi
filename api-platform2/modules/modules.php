<?php
if (!defined('ABSPATH')) exit;

function apiplatform_get_modules(){

    $base = plugin_dir_path(__FILE__);
    $modules = [];

    foreach (glob($base . '*/module.json') as $file) {

        $json = json_decode(file_get_contents($file), true);
        if (!$json || empty($json['name'])) continue;

        $name = sanitize_key($json['name']);

        $modules[$name] = [
            'name'          => $name,
            'label'         => $json['label'] ?? $name,
            'enabled'       => isset($json['enabled']) ? (int)$json['enabled'] : 1,
            'autoload'      => isset($json['autoload']) ? (int)$json['autoload'] : 1,
            'bootstrap'     => $json['bootstrap'] ?? ($name . '.php'),
            'plan_required' => $json['plan_required'] ?? [],
            'addon'         => !empty($json['addon']),
            'price'         => $json['price'] ?? 0,
            'path'          => dirname($file) . '/',
        ];
    }

    return $modules;
}

function apiplatform_load_modules_once(){

    static $loaded = [];

    $modules = apiplatform_get_modules();
    $saved   = get_option('apiplatform_modules', []);
    $user_id = get_current_user_id();

    foreach ($modules as $name => $module) {

        if (isset($loaded[$name])) {
            continue;
        }

        $enabled = ($name === 'core')
            ? true
            : (isset($saved[$name]) ? (bool) $saved[$name] : (bool) $module['enabled']);

        if (!$enabled || empty($module['autoload'])) {
            continue;
        }

        if (
            !current_user_can('manage_options') &&
            !empty($module['plan_required']) &&
            function_exists('apiplatform_user_can_access_module') &&
            !apiplatform_user_can_access_module($user_id, $module)
        ) {
            continue;
        }

        $bootstrap = $module['path'] . $module['bootstrap'];

        if (!file_exists($bootstrap)) {
            continue;
        }

        require_once $bootstrap;
        $loaded[$name] = $bootstrap;
    }

    return $loaded;
}
