<?php
/**
 * Plugin Name: FreedomAPI AI
 * Description: User-owned OpenAI connections and approved declarative response transformations for FreedomAPI Core.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: freedomapi-ai
 */
if (!defined('ABSPATH')) exit;
define('FREEDOMAPI_AI_VERSION', '1.0.0');
define('FREEDOMAPI_AI_FILE', __FILE__);
require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-openai.php';
require_once __DIR__ . '/includes/class-plugin.php';
// Core loads modules at priority 20 and advertises its extension interface at 25.
add_action('plugins_loaded', ['FreedomAPI_AI_Plugin', 'boot'], 30);
