<?php
/*
Plugin Name: API Platform2
Version: 3.2
*/

if (!defined('ABSPATH')) exit;

if (!defined('APIPLATFORM_PATH')) {
    define('APIPLATFORM_PATH', plugin_dir_path(__FILE__));
}

if (!defined('APIPLATFORM_URL')) {
    define('APIPLATFORM_URL', plugin_dir_url(__FILE__));
}

if (!defined('APIPLATFORM_VERSION')) {
    define('APIPLATFORM_VERSION', '3.2');
}

if (!function_exists('apiplatform_asset_version')) {
    function apiplatform_asset_version($relative_path){
        $path = APIPLATFORM_PATH . ltrim((string) $relative_path, '/\\');

        return file_exists($path)
            ? (string) filemtime($path)
            : APIPLATFORM_VERSION;
    }
}

$base = plugin_dir_path(__FILE__) . 'modules/';

/*
----------------------------------------
LOAD CORE HELPERS
----------------------------------------
*/
require_once $base . 'helpers.php';
require_once $base . 'modules.php';

/*
----------------------------------------
BOOT SYSTEM
----------------------------------------
*/
if (!function_exists('apiplatform_bootstrap_class_once')) {
    /**
     * Instantiate module classes explicitly and only once.
     */
    function apiplatform_bootstrap_class_once($class){
        static $instances = [];

        if (!is_string($class) || isset($instances[$class]) || !class_exists($class)) {
            return $instances[$class] ?? null;
        }

        $instances[$class] = new $class();
        return $instances[$class];
    }
}

add_action('plugins_loaded', 'apiplatform_load_modules_once', 20);

add_action('init', function(){

    register_post_type('user_api', [
        'label' => 'APIs',
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'supports' => ['title'],
        'rewrite' => ['slug' => 'api'],
        'capability_type' => 'post'
    ]);
});

/*
----------------------------------------
MODULE ACCESS CHECK
----------------------------------------
*/
function apiplatform_user_can_access_module($user_id, $module){

    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    if (empty($module['plan_required'])) {
        return true;
    }

    $plan = strtolower(strip_tags(do_shortcode('[arm_member_plan]')));

    foreach ($module['plan_required'] as $allowed){
        if (strpos($plan, strtolower($allowed)) !== false){
            return true;
        }
    }

    return false;
}

/*
----------------------------------------
ACTIVATION
----------------------------------------
*/
register_activation_hook(__FILE__, function(){

    if (function_exists('apiplatform_install_database')) {
        apiplatform_install_database(true);
    }
    if (class_exists('APIPlatform_Request_History_Retention')) {
        APIPlatform_Request_History_Retention::schedule();
    }
});

register_deactivation_hook(__FILE__, function(){
    if (class_exists('APIPlatform_Request_History_Retention')) {
        APIPlatform_Request_History_Retention::unschedule();
    }
});
