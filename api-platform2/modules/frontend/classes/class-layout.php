<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Layout {

    /**
     * Canonical WordPress wrapper pages for authenticated frontend routes
     * linked as standalone paths for normal users.
     */
    public static function wrapper_page_definitions(){
        return [
            'organizations' => ['title' => 'Organizations', 'slug' => 'organizations'],
            'applications' => ['title' => 'Applications', 'slug' => 'applications'],
            'my-apis' => ['title' => 'My APIs', 'slug' => 'my-apis'],
            'logs' => ['title' => 'Logs', 'slug' => 'logs'],
            'documentation-manager' => ['title' => 'Documentation Manager', 'slug' => 'documentation-manager'],
            'versions' => ['title' => 'Versions', 'slug' => 'versions'],
            'publisher-settings' => ['title' => 'Publisher Settings', 'slug' => 'publisher-settings'],
            'docs' => ['title' => 'Docs', 'slug' => 'docs'],
            'automations' => ['title' => 'Automations', 'slug' => 'automations'],
        ];
    }
    public function __construct(){
        add_shortcode('api_fulllayout_page', [$this, 'render']);
        add_shortcode('api_sidebar', [$this, 'sidebar']);
    }

    /*
    ----------------------------------------
    MAIN LAYOUT
    ----------------------------------------
    */
    public function render($atts){

        $atts = shortcode_atts([
            'page' => 'dashboard',
            'size' => 'normal',
        ], $atts);

        $requested_page = sanitize_key(strtolower($atts['page']));

        if (!is_user_logged_in() && $requested_page !== 'developers') return 'Login required';

        $size = in_array($atts['size'], ['normal', 'wide', 'full'], true) ? $atts['size'] : 'normal';

        $is_admin = current_user_can('manage_options');

        // 🔥 ROUTING
        $path_page  = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $query_page = $_GET['apipage'] ?? null;

        if ($query_page) {
            $page = $query_page;
        } elseif ($is_admin) {
            $page = $atts['page'];
        } else {
            $page = $path_page;
        }

        if (!$page || $page === 'home') {
            $page = 'dashboard';
        }

        $page = sanitize_key(strtolower($page));

        if ($page === 'developers' && class_exists('APIPlatform_Developer_Portal')) {
            $portal = APIPlatform_Developer_Portal::instance();

            if ($portal) {
                return self::render_shell(
                    $portal->render_public('home'),
                    [
                        'layout_mode' => 'developer-portal',
                        'portal_page' => 'home',
                        'title' => 'Developer Portal',
                        'show_sidebar' => false,
                        'size' => $size,
                    ]
                );
            }
        }

        ob_start(); ?>

        <div class="apiplatform apiplatform-size-<?php echo esc_attr($size); ?>">

            <!-- 🔥 MOBILE BUTTON (GLOBAL) -->
            

            <?php echo do_shortcode('[api_sidebar]'); ?>

            <!-- Sidebar overlay is owned by the main layout so it is rendered once. -->
            <div class="apiplatform-overlay sidebar-overlay" id="apiplatformSidebarOverlay" onclick="toggleSidebar()"></div>

            <div class="apiplatform-main">

                <?php
                switch($page){
					case 'automations':
						echo do_shortcode('[api_automations_page]');
						break;
					case 'debug':
						echo do_shortcode('[api_debug_page]');
						break;
                    case 'create':
                        echo do_shortcode('[api_create_page]');
                        break;

                    case 'my-apis':
                        echo do_shortcode('[api_my_apis_page]');
                        break;

                    case 'applications':
                        echo do_shortcode('[api_applications_page]');
                        break;

                    case 'organizations':
                        echo do_shortcode('[api_organizations_page]');
                        break;

                    case 'organization':
                        echo do_shortcode('[api_organization_page]');
                        break;

                    case 'organization-invite':
                        echo do_shortcode('[api_organization_invite_page]');
                        break;

                    case 'create-application':
                        echo do_shortcode('[api_create_application_page]');
                        break;

                    case 'application':
                        echo do_shortcode('[api_application_page]');
                        break;

                    case 'api':
                    case 'manage-api':
                        echo do_shortcode('[api_publisher_api_page]');
                        break;

                    case 'edit-api':
                        echo do_shortcode('[api_edit_api_page]');
                        break;

                    case 'test-api':
                        echo do_shortcode('[api_tester_page]');
                        break;

                    case 'api-documentation':
                        echo do_shortcode('[api_documentation_detail_page]');
                        break;

                    case 'request-history':
                    case 'logs':
                        echo do_shortcode('[api_request_history_page]');
                        break;

                    case 'analytics':
                        echo do_shortcode('[api_publisher_analytics_page]');
                        break;

                    case 'usage':
                        echo do_shortcode('[api_usage_page]');
                        break;

                    case 'documentation':
                    case 'docs':
                        echo do_shortcode('[api_documentation_page]');
                        break;

                    case 'documentation-manager':
                        echo do_shortcode('[api_publisher_docs_manager_page]');
                        break;

                    case 'versions':
                        echo do_shortcode('[api_publisher_versions_page]');
                        break;

                    case 'publisher-settings':
                        echo do_shortcode('[api_publisher_settings_page]');
                        break;

                    case 'keys':
                        echo do_shortcode('[api_keys_page]');
                        break;

                    case 'profile':
                        echo do_shortcode('[api_profile_page]');
                        break;

                    case 'settings':
                        echo do_shortcode('[api_settings_page]');
                        break;

                    case 'billing':
                        echo do_shortcode('[api_billing_page]');
                        break;

                    case 'dashboard':
                    default:
                        echo do_shortcode('[api_publisher_dashboard_page]');
                        break;
                }
                ?>

            </div>

        </div>

        <!-- 🔥 GLOBAL SCRIPT -->
       

        <?php
        return ob_get_clean();
    }

    public static function render_document($content, array $context = []){
        $title = $context['title'] ?? 'FreedomAPI';
        $body_class = trim('apiplatform-full-layout-page ' . ($context['body_class'] ?? ''));

        ob_start();
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class($body_class); ?>>
<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
}
echo self::render_shell($content, $context);
wp_footer();
?>
</body>
</html><?php
        return ob_get_clean();
    }

    public static function render_shell($content, array $context = []){
        $mode = sanitize_html_class($context['layout_mode'] ?? 'dashboard');
        $size = in_array($context['size'] ?? 'full', ['normal', 'wide', 'full'], true) ? $context['size'] : 'full';
        $show_sidebar = !empty($context['show_sidebar']);
        $portal_page = sanitize_key($context['portal_page'] ?? '');

        ob_start();
        ?>
        <div class="apiplatform-full-layout apiplatform-full-layout--<?php echo esc_attr($mode); ?> apiplatform-size-<?php echo esc_attr($size); ?>">
            <?php echo self::public_header($portal_page); ?>

            <div class="apiplatform-full-layout-body">
                <?php if ($show_sidebar): ?>
                    <aside class="apiplatform-portal-sidebar" aria-label="FreedomAPI navigation">
                        <?php echo do_shortcode('[api_sidebar]'); ?>
                    </aside>
                    <div class="apiplatform-overlay sidebar-overlay" id="apiplatformSidebarOverlay" onclick="toggleSidebar()"></div>
                <?php endif; ?>

                <main class="apiplatform-full-layout-main" id="apiplatform-main-content">
                    <?php echo $content; ?>
                </main>
            </div>

            <?php echo self::public_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function public_header($portal_page = ''){
        $developer_home = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url() : home_url('/developers');
        $links = [
            'home' => ['label' => 'Developer Home', 'url' => $developer_home],
            'apis' => ['label' => 'Browse APIs', 'url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis')],
            'getting-started' => ['label' => 'Getting Started', 'url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('getting-started') : home_url('/developers/getting-started')],
        ];

        if (is_user_logged_in()) {
            $links['dashboard'] = ['label' => 'Dashboard', 'url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('dashboard') : home_url('/developers/dashboard')];
        } else {
            $links['signin'] = ['label' => 'Sign In', 'url' => wp_login_url($developer_home)];
        }

        $html = '<header class="apiplatform-full-layout-header apiplatform-public-header"><a class="apiplatform-brand" href="' . esc_url($developer_home) . '"><span class="apiplatform-brand-mark">F</span><span>FreedomAPI</span></a><nav aria-label="FreedomAPI Developer Portal">';

        foreach ($links as $key => $link) {
            $active = self::portal_nav_active($portal_page, $key);
            $html .= '<a href="' . esc_url($link['url']) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($link['label']) . '</a>';
        }

        return $html . '</nav></header>';
    }

    private static function portal_nav_active($portal_page, $key){
        if ($key === 'apis') {
            return in_array($portal_page, ['directory', 'api-detail', 'api-docs', 'api-test', 'category', 'tag'], true);
        }

        return $portal_page === $key;
    }

    private static function public_footer(){
        $developer_home = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url() : home_url('/developers');
        return '<footer class="apiplatform-full-layout-footer apiplatform-public-footer">' .
            '<div><strong>FreedomAPI</strong><p>Build, publish, test, and monitor APIs with a security-first platform.</p></div>' .
            '<div><h2>Product</h2><a href="' . esc_url(class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('apis') : home_url('/developers/apis')) . '">API Directory</a><a href="' . esc_url(class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('dashboard') : home_url('/developers/dashboard')) . '">Developer Dashboard</a></div>' .
            '<div><h2>Resources</h2><a href="' . esc_url(class_exists('APIPlatform_Routes') ? APIPlatform_Routes::developer_url('getting-started') : home_url('/developers/getting-started')) . '">Getting Started</a><a href="' . esc_url($developer_home) . '">Developer Portal</a></div>' .
            '<div><h2>Company</h2><span>&copy; ' . esc_html(date('Y')) . ' FreedomAPI</span></div>' .
            '</footer>';
    }

    /*
    ----------------------------------------
    SIDEBAR
    ----------------------------------------
    */
    public function sidebar(){

        $is_admin = current_user_can('manage_options');
    
        $path_page  = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $query_page = $_GET['apipage'] ?? null;
    
        $current = $query_page ?: $path_page ?: 'dashboard';
        $current = sanitize_key($current);
        $docs_alias_enabled = function_exists('freedom_api_docs_shortcode_alias_enabled')
            ? freedom_api_docs_shortcode_alias_enabled()
            : (bool) get_option('apiplatform_docs_shortcode_alias');
        $documentation_system_enabled = function_exists('freedom_api_documentation_system_enabled')
            ? freedom_api_documentation_system_enabled()
            : (bool) get_option('apiplatform_enable_documentation', false);
        $docs_slug = ($docs_alias_enabled || $documentation_system_enabled) ? 'docs' : 'documentation';

        if ($current === 'documentation' || $current === 'docs') {
            $current = $docs_slug;
        }

        $selected_api_label = '';
        $selected_api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;
        if ($selected_api_id && in_array($current, ['api', 'manage-api'], true)) {
            $selected_api = get_post($selected_api_id);
            if (
                $selected_api &&
                $selected_api->post_type === 'user_api' &&
                (
                    class_exists('APIPlatform_Ownership_Service')
                        ? APIPlatform_Ownership_Service::can(get_current_user_id(), $selected_api->ID, 'apis.view')
                        : ((int) $selected_api->post_author === get_current_user_id() || current_user_can('manage_options'))
                )
            ) {
                $selected_api_label = get_the_title($selected_api);
            }
        }
    
        $links = [
            'dashboard' => ['label'=>'Dashboard','icon'=>'dashboard'],
            'organizations' => ['label'=>'Organizations','icon'=>'groups'],
            'applications' => ['label'=>'Applications','icon'=>'id'],
            'my-apis'   => ['label'=>'My APIs','icon'=>'portfolio'],
            'create'    => ['label'=>'Create API','icon'=>'plus-alt'],
            'analytics' => ['label'=>'Analytics','icon'=>'chart-line'],
            'logs'      => ['label'=>'Logs','icon'=>'media-text'],
            'documentation-manager' => ['label'=>'Documentation','icon'=>'book'],
            'versions'  => ['label'=>'Versions','icon'=>'networking'],
            'publisher-settings' => ['label'=>'API Settings','icon'=>'admin-generic'],
            $docs_slug => ['label'=>'Docs Library','icon'=>'book-alt'],
            'usage'     => ['label'=>'Usage','icon'=>'chart-bar'],
            'keys'      => ['label'=>'Legacy API Keys','icon'=>'admin-network'],
            'profile'   => ['label'=>'Profile','icon'=>'admin-users'],
            'billing'   => ['label'=>'Billing','icon'=>'money-alt'],
            'settings'  => ['label'=>'Account Settings','icon'=>'admin-settings'],
			'automations' => ['label'=>'Automations','icon'=>'controls-repeat'],
            'debug'     => ['label'=>'Debug','icon'=>'warning'],
        ];
    
        ob_start(); ?>
    
        <div class="apiplatform-sidebar" id="apiSidebar">
    
            <div class="sidebar-header">
                ⚡ <span>API Platform</span>
            </div>
    
            <?php if ($selected_api_label): ?>
                <div class="apiplatform-sidebar-context">
                    <span>My APIs</span>
                    <strong><?php echo esc_html($selected_api_label); ?></strong>
                </div>
            <?php endif; ?>

            <nav>
    
            <?php foreach($links as $slug => $data): 
    
                // 🔥 FIX: hide debug for non-admin
                if ($slug === 'debug' && !$is_admin) continue;
    
                $active = ($current === $slug || (in_array($current, ['api', 'manage-api'], true) && $slug === 'my-apis')) ? 'active' : '';
    
                $url = $is_admin
                    ? add_query_arg('apipage', $slug, class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))
                    : site_url('/' . $slug);
    
            ?>
    
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($active); ?>">
                    <span class="icon dashicons dashicons-<?php echo esc_attr($data['icon']); ?>" aria-hidden="true"></span>
                    <span class="label"><?php echo esc_html($data['label']); ?></span>
                </a>
    
            <?php endforeach; ?>
    
            </nav>
        </div>
    	
        <?php
        return ob_get_clean();
    }
}
