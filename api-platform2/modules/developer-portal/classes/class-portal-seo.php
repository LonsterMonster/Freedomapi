<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal_SEO {

    public function __construct(){
        add_action('wp_head', [$this, 'render_meta'], 2);
    }

    public function render_meta(){
        $route = get_query_var('apiplatform_portal') ?: (isset($_GET['apiplatform_portal']) ? sanitize_key(wp_unslash($_GET['apiplatform_portal'])) : '');

        if (!$route && class_exists('APIPlatform_Developer_Portal') && APIPlatform_Developer_Portal::instance()) {
            $route = APIPlatform_Developer_Portal::instance()->current_route();
        }

        if (!$route) {
            return;
        }

        $title = 'FreedomAPI Developer Portal';
        $description = 'Browse public APIs, read documentation, and test approved FreedomAPI endpoints.';
        $robots = 'index,follow';
        $developer_prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_prefix() : 'developers';
        $canonical = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url() : home_url('/developers');
        $slug = sanitize_title(get_query_var('apiplatform_portal_slug') ?: ($_GET['portal_slug'] ?? ''));

        if (!$slug && preg_match('#/' . preg_quote($developer_prefix, '#') . '/apis/([^/]+)(?:/docs|/test)?/?$#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $matches)) {
            $slug = sanitize_title($matches[1]);
        }

        if ($slug) {
            $api = APIPlatform_Developer_Portal_Query::resolve_api($slug, true);

            if ($api) {
                $card = APIPlatform_Developer_Portal_Query::api_card($api);
                $title = $card['title'] . ' - FreedomAPI';
                $description = $card['summary'] ?: $description;
                $canonical = $route === 'docs'
                    ? trailingslashit($card['url']) . 'docs'
                    : ($route === 'test' ? trailingslashit($card['url']) . 'test' : $card['url']);
                $robots = $card['visibility'] === 'public' ? 'index,follow' : 'noindex,follow';
            } else {
                $robots = 'noindex,nofollow';
            }
        } elseif ($route !== 'home') {
            $canonical = class_exists('APIPlatform_Routes')
                ? APIPlatform_Routes::developer_url($route === 'directory' ? 'apis' : sanitize_title($route))
                : home_url('/developers/' . ($route === 'directory' ? 'apis' : sanitize_title($route)));
        }

        echo "\n" . '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";
        echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(wp_strip_all_tags($title)) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags($description)) . '">' . "\n";
    }
}
