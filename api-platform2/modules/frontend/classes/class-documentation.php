<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Documentation {

    public function __construct(){
        add_shortcode('api_documentation_page', [$this, 'render']);
    }

    public function render(){
        if (!is_user_logged_in()) {
            return APIPlatform_Renderer::component('alert', [
                'type' => 'warning',
                'content' => 'Login required to view documentation.'
            ]);
        }

        APIPlatform_Frontend_Assets::enqueue_documentation();

        if (class_exists('APIPlatform_Platform_Documentation')) {
            return $this->render_platform_documentation();
        }

        $sections = $this->documentation_sections();
        $sections = $this->with_navigation_urls($sections);
        $current_doc = $this->current_doc($sections);
        $active_section = $this->section_by_id($sections, $current_doc);
        $current_post = $this->current_post();
        $active_post = $this->post_by_id($active_section, $current_post);
        $breadcrumbs = $this->breadcrumbs($active_section, $active_post);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[DOCS PAGE] Sections loaded: ' . count($sections));
            error_log('[DOCS PAGE] Active doc: ' . $current_doc);
            error_log('[DOCS PAGE] Active post: ' . ($current_post ?: 'none'));
        }

        $content = APIPlatform_Renderer::partial('documentation/documentation-page', [
            'title' => 'Freedom API Documentation',
            'intro' => 'Build, secure, and monitor user-created JSON APIs from the Freedom API developer portal.',
            'sections' => $sections,
            'active_section' => $active_section,
            'active_post' => $active_post,
            'current_doc' => $current_doc,
            'current_post' => $current_post,
            'breadcrumbs' => $breadcrumbs,
            'docs_base_url' => $this->docs_base_url()
        ]);

        return APIPlatform_Renderer::component('section', [
            'class' => 'apiplatform-documentation-section',
            'content' => $content
        ]);
    }

    /**
     * Render FreedomAPI's version-controlled platform documentation.
     * Publisher-created API documentation continues through the legacy/CPT
     * and Documentation Workspace paths below and in the Developer Portal.
     */
    private function render_platform_documentation(){
        $base_url = $this->docs_base_url();
        $sections = APIPlatform_Platform_Documentation::sections($base_url);
        $requested = isset($_GET['doc']) ? sanitize_key(wp_unslash($_GET['doc'])) : '';
        $active_section = [];
        $active_post = [];
        $current_doc = '';
        $current_post = '';

        foreach ($sections as $section) {
            foreach (($section['posts'] ?? []) as $post) {
                if ($requested && ($post['id'] ?? '') === $requested) {
                    $active_section = $section;
                    $current_doc = $section['id'] ?? '';
                    $current_post = $post['id'] ?? '';
                    $active_post = $post;
                    break 2;
                }
            }
        }

        if ($requested && $current_post === '') {
            $active_section = [
                'id' => 'not-found',
                'label' => 'Documentation',
                'posts' => [],
            ];
            $active_post = [
                'id' => 'document-not-found',
                'title' => 'Document not found',
                'markdown' => true,
                'content' => '<p>The requested platform document is not available.</p>',
                'toc' => [],
            ];
            $current_doc = 'not-found';
            $current_post = 'document-not-found';
        } elseif (!$requested) {
            $active_post = [
                'id' => 'documentation-home',
                'title' => 'FreedomAPI Documentation',
                'markdown' => true,
                'landing' => true,
                'content' => $this->platform_documentation_landing($sections),
                'toc' => [],
            ];
        }

        if ($current_post !== '') {
            $rendered = APIPlatform_Platform_Documentation::document($current_post);
            if (is_array($rendered)) {
                $active_post = array_merge($active_post, $rendered);
            }
            $active_post['adjacent'] = APIPlatform_Platform_Documentation::adjacent($current_post);
        }

        $breadcrumbs = $this->breadcrumbs($active_section, $active_post);
        $content = APIPlatform_Renderer::partial('documentation/documentation-page', [
            'title' => 'Freedom API Documentation',
            'intro' => 'Platform guides for building, publishing, securing, and operating Freedom API integrations.',
            'sections' => $sections,
            'active_section' => $active_section,
            'active_post' => $active_post,
            'current_doc' => $current_doc,
            'current_post' => $current_post,
            'breadcrumbs' => $breadcrumbs,
            'docs_base_url' => $base_url,
            'platform_docs' => true,
        ]);

        return APIPlatform_Renderer::component('section', [
            'class' => 'apiplatform-documentation-section',
            'content' => $content
        ]);
    }

    private function platform_documentation_landing(array $sections){
        $content = '<div class="apiplatform-docs-landing">';
        $content .= '<p class="apiplatform-docs-landing-lead">Learn how to create, publish, secure, test, and operate APIs with FreedomAPI.</p>';
        $content .= '<div class="apiplatform-docs-landing-grid">';

        foreach ($sections as $section) {
            $posts = is_array($section['posts'] ?? null) ? $section['posts'] : [];
            if (empty($posts[0]['url'])) continue;
            $content .= '<a class="apiplatform-docs-landing-card" href="' . esc_url($posts[0]['url']) . '">';
            $content .= '<strong>' . esc_html($section['label'] ?? '') . '</strong>';
            $content .= '<span>' . esc_html(($posts[0]['description'] ?? 'Open this documentation section.')) . '</span>';
            $content .= '</a>';
        }

        $content .= '</div></div>';
        return $content;
    }

    private function documentation_sections(){
        if (function_exists('freedom_api_documentation_system_enabled') && freedom_api_documentation_system_enabled()) {
            if (function_exists('freedom_api_get_documentation_sections')) {
                return freedom_api_get_documentation_sections();
            }
        }

        return $this->fallback_documentation_sections();
    }

    private function fallback_documentation_sections(){
        $base_endpoint = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url('{publisher}', '{api}') : home_url('/gateway/{publisher}/{api}');

        return [
            [
                'id' => 'getting-started',
                'label' => 'Getting Started',
                'description' => 'Create an API, define its JSON response, copy the generated endpoint, and call it with your API key.',
                'items' => [
                    'Create an API from the Create page.',
                    'Set a unique slug. The slug becomes the public endpoint path.',
                    'Save a valid JSON response body.',
                    'Use the Keys page to copy the endpoint, API key, or full request URL.'
                ],
                'examples' => [
                    [
                        'title' => 'Endpoint format',
                        'language' => 'text',
                        'code' => $base_endpoint
                    ],
                    [
                        'title' => 'Example response JSON',
                        'language' => 'json',
                        'code' => json_encode(['message' => 'Hello'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ],
            [
                'id' => 'authentication',
                'label' => 'Authentication',
                'description' => 'Freedom API validates requests with the API key stored on the matching user_api post.',
                'items' => [
                    'Send the API key with the Authorization Bearer header. X-API-Key is supported as an alternative; query-string keys remain compatibility-only.',
                    'Bearer tokens and the legacy api_key query parameter remain supported for existing clients.',
                    'Regenerating a key immediately replaces the stored key.'
                ],
                'examples' => [
                    [
                        'title' => 'Authenticated request',
                        'language' => 'bash',
                        'code' => 'curl -H "Authorization: Bearer YOUR_API_KEY" "' . (class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url('{publisher}', 'weather-api') : home_url('/gateway/{publisher}/weather-api')) . '"'
                    ]
                ]
            ],
            [
                'id' => 'api-keys',
                'label' => 'API Keys',
                'description' => 'The Keys page is the copy/paste hub for endpoints and key regeneration.',
                'items' => [
                    'Copy Endpoint copies the route without credentials.',
                    'Full API keys are shown only once after creation or regeneration.',
                    'Stored keys are displayed in masked form after the one-time reveal.',
                    'Regenerate Key creates a secure replacement and invalidates the old key.'
                ]
            ],
            [
                'id' => 'rate-limits',
                'label' => 'Rate Limits',
                'description' => 'Request limits are resolved from the user membership plan, with administrators receiving Admin Access.',
                'items' => [
                    'Normal users continue to use ARMember plan limits.',
                    'Administrators receive unlimited requests and APIs.',
                    'Usage and request logs are visible from the Usage page.'
                ]
            ],
            [
                'id' => 'api-reference',
                'label' => 'API Reference',
                'description' => 'Core user workflows and REST access patterns for Freedom API.',
                'endpoints' => [
                    [
                        'id' => 'create-api',
                        'method' => 'POST',
                        'title' => 'Create API',
                        'path' => '/create',
                        'description' => 'Create a user-owned API, generate its slug and key, and store the JSON response metadata.',
                        'parameters' => [
                            ['name' => 'api_name', 'type' => 'string', 'required' => true, 'description' => 'Readable API name.'],
                            ['name' => 'api_slug', 'type' => 'string', 'required' => true, 'description' => 'Unique endpoint slug.'],
                            ['name' => 'response_json', 'type' => 'json', 'required' => true, 'description' => 'Valid JSON returned by the endpoint.']
                        ],
                        'request' => json_encode([
                            'api_name' => 'Weather API',
                            'api_slug' => 'weather-api',
                            'response_json' => ['message' => 'Hello']
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'response' => json_encode([
                            'success' => true,
                            'api_id' => 123,
                            'endpoint' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url('{publisher}', 'weather-api') : home_url('/gateway/{publisher}/weather-api'),
                            'api_key' => 'apk_live_xxxxx'
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ],
                    [
                        'id' => 'update-api',
                        'method' => 'POST',
                        'title' => 'Update API',
                        'path' => '/edit-api?api_id={id}',
                        'description' => 'Update an existing API owned by the current user, including name, slug, status, and JSON response.',
                        'parameters' => [
                            ['name' => 'api_name', 'type' => 'string', 'required' => true, 'description' => 'Updated API name.'],
                            ['name' => 'api_slug', 'type' => 'string', 'required' => true, 'description' => 'Updated unique slug.'],
                            ['name' => 'api_status', 'type' => 'string', 'required' => true, 'description' => 'API status value.'],
                            ['name' => 'response_json', 'type' => 'json', 'required' => true, 'description' => 'Valid JSON response body.']
                        ],
                        'request' => json_encode([
                            'api_name' => 'Weather API',
                            'api_slug' => 'weather-api-v2',
                            'api_status' => 'active',
                            'response_json' => ['message' => 'Updated']
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        'response' => json_encode([
                            'success' => true,
                            'api_id' => 123,
                            'endpoint' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::gateway_url('{publisher}', 'weather-api-v2') : home_url('/gateway/{publisher}/weather-api-v2'),
                            'status' => 'active'
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ],
                    [
                        'id' => 'usage-stats',
                        'method' => 'GET',
                        'title' => 'Usage Stats',
                        'path' => '/usage',
                        'description' => 'Review total requests, recent logs, plan limits, and API-level usage information.',
                        'parameters' => [
                            ['name' => 'api_id', 'type' => 'integer', 'required' => false, 'description' => 'Optional API filter where supported.']
                        ],
                        'request' => 'GET ' . site_url('/usage?api_id=123'),
                        'request_language' => 'text',
                        'response' => json_encode([
                            'total_requests' => 124,
                            'last_request' => '2026-06-03T12:00:00Z',
                            'status' => 'active'
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ]
        ];
    }

    private function current_doc(array $sections){
        $pretty_doc = function_exists('get_query_var')
            ? get_query_var('apiplatform_doc_category')
            : '';
        $doc = $pretty_doc ?: (isset($_GET['doc']) ? sanitize_key(wp_unslash($_GET['doc'])) : '');
        $valid = array_map(function ($section) {
            return $section['id'] ?? '';
        }, $sections);

        if (!$doc || !in_array($doc, $valid, true)) {
            return $sections[0]['id'] ?? '';
        }

        return $doc;
    }

    private function current_post(){
        $pretty_post = function_exists('get_query_var')
            ? get_query_var('apiplatform_doc_post')
            : '';

        return $pretty_post ?: (isset($_GET['doc_post']) ? sanitize_key(wp_unslash($_GET['doc_post'])) : '');
    }

    private function section_by_id(array $sections, $id){
        foreach ($sections as $section) {
            if (($section['id'] ?? '') === $id) {
                return $section;
            }
        }

        return $sections[0] ?? [];
    }

    private function post_by_id(array $section, $id){
        if (!$id || empty($section['posts']) || !is_array($section['posts'])) {
            return [];
        }

        foreach ($section['posts'] as $post) {
            if (($post['id'] ?? '') === $id) {
                return $post;
            }
        }

        return [];
    }

    private function breadcrumbs(array $section, array $post){
        $items = ['Documentation'];

        if (!empty($section['label'])) {
            $items[] = $section['label'];
        }

        if (!empty($post['title'])) {
            $items[] = $post['title'];
        }

        return $items;
    }

    private function with_navigation_urls(array $sections){
        $base_url = $this->docs_base_url();
        $dynamic_pretty_urls = function_exists('freedom_api_documentation_system_enabled') &&
            freedom_api_documentation_system_enabled() &&
            !current_user_can('manage_options');

        foreach ($sections as &$section) {
            $section_id = $section['id'] ?? '';

            $section['url'] = $dynamic_pretty_urls
                ? trailingslashit(trailingslashit($base_url) . $section_id)
                : add_query_arg('doc', $section_id, $base_url);

            if (!empty($section['posts']) && is_array($section['posts'])) {
                foreach ($section['posts'] as &$post) {
                    $post_id = $post['id'] ?? '';
                    $post['url'] = $dynamic_pretty_urls
                        ? trailingslashit(trailingslashit($section['url']) . $post_id)
                        : add_query_arg([
                            'doc' => $section_id,
                            'doc_post' => $post_id
                        ], $base_url);
                }
                unset($post);
            }
        }
        unset($section);

        return $sections;
    }

    private function docs_base_url(){
        if (current_user_can('manage_options')) {
            $page = (
                (
                    function_exists('freedom_api_docs_shortcode_alias_enabled') &&
                    freedom_api_docs_shortcode_alias_enabled()
                ) ||
                (
                    function_exists('freedom_api_documentation_system_enabled') &&
                    freedom_api_documentation_system_enabled()
                )
            )
                ? 'docs'
                : 'documentation';

            return add_query_arg('apipage', $page, class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'));
        }

        return class_exists('APIPlatform_Routes') ? APIPlatform_Routes::docs_url() : site_url('/documentation');
    }
}
