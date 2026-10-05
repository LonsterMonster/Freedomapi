<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Settings {

    public function __construct(){

        if (!class_exists('APIPlatform_Admin_Menu')) {
            add_action(
                'admin_menu',
                [$this,'menu']
            );
        }

        add_action(
            'admin_init',
            [$this,'handle_save']
        );

        /*
        |--------------------------------------------------------------------------
        | Styles
        |--------------------------------------------------------------------------
        */

        if (
            isset($_GET['page']) &&
            in_array($_GET['page'], ['apiplatform', 'apiplatform-settings', 'apiplatform-routes', 'apiplatform-auth-providers'], true)
        ){

            add_action(
                'admin_head',
                [$this,'styles']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    */

    public function menu(){

        add_submenu_page(

            'apiplatform',

            'API Platform',

            'Settings',

            'manage_options',

            'apiplatform-settings',

            [$this,'render']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save Settings
    |--------------------------------------------------------------------------
    */

    public function handle_save(){

        if (isset($_POST['apiplatform_frontend_page_action'])) {
            if (!current_user_can('manage_options') || !isset($_POST['apiplatform_frontend_pages_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apiplatform_frontend_pages_nonce'])), 'apiplatform_frontend_pages')) return;
            $identifier = sanitize_key(wp_unslash($_POST['frontend_page_identifier'] ?? ''));
            $definitions = class_exists('APIPlatform_Frontend_Layout') ? APIPlatform_Frontend_Layout::wrapper_page_definitions() : [];
            $definition = $definitions[$identifier] ?? null;
            if (!$definition) { add_settings_error('apiplatform_frontend_pages', 'invalid_page', 'That FreedomAPI frontend page is not supported.', 'error'); return; }
            if ($this->frontend_page_status($identifier, $definition)['state'] !== 'missing') { add_settings_error('apiplatform_frontend_pages', 'page_exists', 'Page creation was not performed because the expected slug is already in use.', 'error'); return; }
            $page_id = wp_insert_post(['post_title' => $definition['title'], 'post_name' => $definition['slug'], 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[api_fulllayout_page page="' . $identifier . '" size="full"]'], true);
            if (is_wp_error($page_id)) { add_settings_error('apiplatform_frontend_pages', 'page_create_failed', 'The wrapper page could not be created.', 'error'); return; }
            add_settings_error('apiplatform_frontend_pages', 'page_created', 'Frontend wrapper page created.', 'updated');
            return;
        }
        if (!isset($_POST['save_settings']) && !isset($_POST['save_route_settings'])) {
            return;
        }

        if (
            !isset($_POST['_wpnonce']) ||
            !wp_verify_nonce(
                $_POST['_wpnonce'],
                'apiplatform_settings_nonce'
            )
        ){
            return;
        }

        if (isset($_POST['save_route_settings'])) {
            if (!current_user_can('manage_options')) {
                return;
            }

            if (!class_exists('APIPlatform_Routes')) {
                add_settings_error('apiplatform_routes', 'routes_unavailable', 'Route settings are unavailable because the route helper is not loaded.', 'error');
                return;
            }

            $result = APIPlatform_Routes::save_prefixes([
                APIPlatform_Routes::GATEWAY_OPTION => isset($_POST[APIPlatform_Routes::GATEWAY_OPTION]) ? wp_unslash($_POST[APIPlatform_Routes::GATEWAY_OPTION]) : '',
                APIPlatform_Routes::DASHBOARD_OPTION => isset($_POST[APIPlatform_Routes::DASHBOARD_OPTION]) ? wp_unslash($_POST[APIPlatform_Routes::DASHBOARD_OPTION]) : '',
                APIPlatform_Routes::DEVELOPER_OPTION => isset($_POST[APIPlatform_Routes::DEVELOPER_OPTION]) ? wp_unslash($_POST[APIPlatform_Routes::DEVELOPER_OPTION]) : '',
                APIPlatform_Routes::DOCS_OPTION => isset($_POST[APIPlatform_Routes::DOCS_OPTION]) ? wp_unslash($_POST[APIPlatform_Routes::DOCS_OPTION]) : '',
                APIPlatform_Routes::AUTH_OPTION => isset($_POST[APIPlatform_Routes::AUTH_OPTION]) ? wp_unslash($_POST[APIPlatform_Routes::AUTH_OPTION]) : '',
            ]);

            if (!empty($result['ok'])) {
                add_settings_error(
                    'apiplatform_routes',
                    'routes_saved',
                    !empty($result['changed']) ? 'Routes saved and rewrite rules refreshed.' : 'Routes already matched the saved settings.',
                    'updated'
                );
            } else {
                foreach ((array) ($result['errors'] ?? []) as $error) {
                    add_settings_error('apiplatform_routes', 'routes_error', $error, 'error');
                }
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Modules
        |--------------------------------------------------------------------------
        */

        $modules =
            isset($_POST['modules']) &&
            is_array($_POST['modules'])
                ? $_POST['modules']
                : [];

        $clean = [];

        foreach($modules as $key => $val){

            $clean[
                sanitize_text_field($key)
            ] = 1;
        }

        /*
        |--------------------------------------------------------------------------
        | Always Keep Core
        |--------------------------------------------------------------------------
        */

        $clean['core'] = 1;

        update_option(
            'apiplatform_modules',
            $clean
        );

        /*
        |--------------------------------------------------------------------------
        | Experimental Data Layer
        |--------------------------------------------------------------------------
        */

        update_option(

            'apiplatform_enable_data_layer',

            isset($_POST['enable_data_layer'])
                ? 1
                : 0
        );

        /*
        |--------------------------------------------------------------------------
        | Documentation System
        |--------------------------------------------------------------------------
        */

        $documentation_was_enabled = (bool) get_option(
            'apiplatform_enable_documentation',
            false
        );

        $documentation_enabled = isset($_POST['enable_documentation_system'])
            ? 1
            : 0;

        update_option(

            'apiplatform_enable_documentation',

            $documentation_enabled
        );

        if ($documentation_was_enabled !== (bool) $documentation_enabled) {
            if (
                $documentation_enabled &&
                function_exists('freedom_api_register_documentation_system')
            ) {
                freedom_api_register_documentation_system();
            }

            if (
                $documentation_enabled &&
                function_exists('freedom_api_ensure_default_documentation_categories')
            ) {
                freedom_api_ensure_default_documentation_categories();
            }

            flush_rewrite_rules(false);
        }

        /*
        |--------------------------------------------------------------------------
        | Documentation Shortcode Alias
        |--------------------------------------------------------------------------
        */

        update_option(

            'apiplatform_docs_shortcode_alias',

            isset($_POST['enable_docs_shortcode_alias'])
                ? 1
                : 0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Main Render
    |--------------------------------------------------------------------------
    */

    public function render(){

        if (
            !current_user_can(
                'manage_options'
            )
        ){
            return;
        }

        global $wpdb;

        $tab =
            isset($_GET['tab'])
                ? sanitize_text_field(
                    $_GET['tab']
                )
                : 'dashboard';

        ?>

        <div class="wrap apiplatform-admin">

            <h1>🚀 API Platform</h1>

            <nav class="nav-tab-wrapper">

                <a
                    href="?page=apiplatform-settings&tab=dashboard"
                    class="nav-tab <?php echo $tab=='dashboard'?'nav-tab-active':''; ?>"
                >
                    Dashboard
                </a>

                <a
                    href="?page=apiplatform-settings&tab=system"
                    class="nav-tab <?php echo $tab=='system'?'nav-tab-active':''; ?>"
                >
                    System
                </a>

                <a
                    href="?page=apiplatform-settings&tab=modules"
                    class="nav-tab <?php echo $tab=='modules'?'nav-tab-active':''; ?>"
                >
                    Modules
                </a>

                <a
                    href="?page=apiplatform-settings&tab=notifications"
                    class="nav-tab <?php echo $tab=='notifications'?'nav-tab-active':''; ?>"
                >
                    Notifications
                </a>

                <a
                    href="?page=apiplatform-settings&tab=routes"
                    class="nav-tab <?php echo $tab=='routes'?'nav-tab-active':''; ?>"
                >
                    Routes
                </a>

                <a
                    href="?page=apiplatform-settings&tab=frontend-pages"
                    class="nav-tab <?php echo $tab=='frontend-pages'?'nav-tab-active':''; ?>"
                >
                    Frontend Pages
                </a>
                <a
                    href="?page=apiplatform-settings&tab=auth-providers"
                    class="nav-tab <?php echo $tab=='auth-providers'?'nav-tab-active':''; ?>"
                >
                    Authentication Providers
                </a>

                <a
                    href="?page=apiplatform-settings&tab=request-history"
                    class="nav-tab <?php echo $tab=='request-history'?'nav-tab-active':''; ?>"
                >
                    Request History
                </a>

                <a
                    href="?page=apiplatform-settings&tab=plan-simulation"
                    class="nav-tab <?php echo $tab=='plan-simulation'?'nav-tab-active':''; ?>"
                >
                    Plan Simulation
                </a>

                <a
                    href="?page=apiplatform-settings&tab=debug"
                    class="nav-tab <?php echo $tab=='debug'?'nav-tab-active':''; ?>"
                >
                    Debug
                </a>

            </nav>

            <div class="apiplatform-content">

                <?php

                switch($tab){

                    case 'dashboard':
                        $this->dashboard_tab();
                        break;

                    case 'system':
                        $this->system_tab();
                        break;

                    case 'modules':
                        $this->modules_tab();
                        break;

                    case 'notifications':
                        $this->notifications_tab();
                        break;

                    case 'routes':
                        $this->routes_tab();
                        break;

                    case 'frontend-pages':
                        $this->frontend_pages_tab();
                        break;

                    case 'auth-providers':
                        echo class_exists('APIPlatform_Auth_Settings')
                            ? APIPlatform_Auth_Settings::render()
                            : '<p>Authentication provider module is not loaded.</p>';
                        break;

                    case 'request-history':
                        if (class_exists('APIPlatform_Request_History_Retention')) {
                            APIPlatform_Request_History_Retention::render_admin_tab();
                        } else {
                            echo '<p>Request History retention is unavailable.</p>';
                        }
                        break;

                    case 'plan-simulation':
                        if (class_exists('APIPlatform_Plan_Simulation')) {
                            APIPlatform_Plan_Simulation::render_admin_control();
                        } else {
                            echo '<p>Plan simulation tools are unavailable.</p>';
                        }
                        break;

                    case 'debug':
                        $this->debug_tab();
                        break;

                    default:
                        $this->dashboard_tab();
                        break;
                }

                ?>

            </div>

        </div>

        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard Tab
    |--------------------------------------------------------------------------
    */

    public function dashboard_tab(){

        global $wpdb;

        $logs_table =
            $wpdb->prefix .
            'apiplatform_logs';

        if (
            $wpdb->get_var(
                "SHOW TABLES LIKE '$logs_table'"
            ) !== $logs_table
        ){

            echo '
            <p style="color:red;">
                Logs table missing.
            </p>
            ';

            return;
        }

        $total_requests = (int)
            $wpdb->get_var(
                "SELECT COUNT(*) FROM $logs_table"
            );

        $users =
            count_users()['total_users'];

        $apis =
            wp_count_posts(
                'user_api'
            )->publish ?? 0;

        $data = [];

        for ($i=6; $i>=0; $i--){

            $date = date(
                'Y-m-d',
                strtotime("-$i days")
            );

            $count =
                $wpdb->get_var(
                    $wpdb->prepare("
                        SELECT COUNT(*)
                        FROM $logs_table
                        WHERE DATE(created_at) = %s
                    ", $date)
                );

            $data[] = (int)$count;
        }

        ?>

        <div class="apiplatform-grid">

            <div class="apiplatform-card">

                <h3>Total Requests</h3>

                <h1>
                    <?php
                    echo number_format(
                        $total_requests
                    );
                    ?>
                </h1>

            </div>

            <div class="apiplatform-card">

                <h3>Users</h3>

                <h1>
                    <?php echo esc_html($users); ?>
                </h1>

            </div>

            <div class="apiplatform-card">

                <h3>APIs</h3>

                <h1>
                    <?php echo esc_html($apis); ?>
                </h1>

            </div>

        </div>

        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | System Tab
    |--------------------------------------------------------------------------
    */

    public function system_tab(){ ?>

        <form method="post">

            <?php
            wp_nonce_field(
                'apiplatform_settings_nonce'
            );
            ?>

            <div class="apiplatform-card">

                <h3>System Status</h3>

                <p>

                    Platform:

                    <strong style="
                        color:
                        <?php
                        echo get_option(
                            'apiplatform_disabled'
                        )
                            ? 'red'
                            : 'lime';
                        ?>
                    ;">

                        <?php
                        echo get_option(
                            'apiplatform_disabled'
                        )
                            ? 'Disabled'
                            : 'Active';
                        ?>

                    </strong>

                </p>

                <p>
                    PHP →
                    <?php echo esc_html(
                        phpversion()
                    ); ?>
                </p>

                <p>
                    WP →
                    <?php echo esc_html(
                        get_bloginfo('version')
                    ); ?>
                </p>

            </div>

            <div class="apiplatform-card">

                <h3>Experimental Features</h3>

                <label>

                    <input
                        type="checkbox"
                        name="enable_data_layer"
                        value="1"

                        <?php checked(
                            get_option(
                                'apiplatform_enable_data_layer'
                            ),
                            1
                        ); ?>
                    >

                    Enable Experimental Data Layer

                </label>

            </div>

            <div class="apiplatform-card">

                <h3>Documentation</h3>

                <p>

                    <label>

                        <input
                            type="checkbox"
                            name="enable_documentation_system"
                            value="1"

                            <?php checked(
                                get_option(
                                    'apiplatform_enable_documentation',
                                    false
                                ),
                                1
                            ); ?>
                        >

                        Enable Documentation System

                    </label>

                </p>

                <p>
                    Registers the <code>documentation</code> CPT and
                    <code>documentation_category</code> taxonomy, then uses them
                    for the frontend docs output.
                </p>

                <label>

                    <input
                        type="checkbox"
                        name="enable_docs_shortcode_alias"
                        value="1"

                        <?php checked(
                            get_option(
                                'apiplatform_docs_shortcode_alias'
                            ),
                            1
                        ); ?>
                    >

                    Enable shortcode alias
                    <code>[api_fulllayout_page page="docs"]</code>
                    for CPT documentation

                </label>

                <p>
                    When enabled, the frontend sidebar uses Docs instead of Documentation.
                    Both docs and documentation routes continue to load the CPT documentation page.
                </p>

            </div>

            <button
                class="button button-primary"
                name="save_settings"
            >
                Save Settings
            </button>

        </form>

    <?php }

    /*
    |--------------------------------------------------------------------------
    | Modules Tab
    |--------------------------------------------------------------------------
    */

    public function modules_tab(){

        $modules_json = function_exists('apiplatform_get_modules')
            ? apiplatform_get_modules()
            : [];

        $saved =
            get_option(
                'apiplatform_modules',
                []
            );

        ?>

        <form method="post">

            <?php
            wp_nonce_field(
                'apiplatform_settings_nonce'
            );
            ?>

            <div class="apiplatform-card">

                <h3>Modules</h3>

                <?php
                foreach($modules_json as $key => $mod):

                    $enabled =
                        isset($saved[$key])
                            ? $saved[$key]
                            : ($mod['enabled'] ?? 1);

                ?>

                <div class="apiplatform-module">

                    <label>

                        <?php if($key === 'core'): ?>

                            <input
                                type="checkbox"
                                checked
                                disabled
                            >

                        <?php else: ?>

                            <input
                                type="checkbox"
                                name="modules[<?php echo esc_attr($key); ?>]"
                                value="1"

                                <?php checked($enabled); ?>
                            >

                        <?php endif; ?>

                        <strong>
                            <?php
                            echo esc_html(
                                ucfirst($key)
                            );
                            ?>
                        </strong>

                    </label>

                    <span class="
                        status
                        <?php echo $enabled ? 'on':'off'; ?>
                    ">

                        <?php
                        echo $enabled
                            ? 'ON'
                            : 'OFF';
                        ?>

                    </span>

                </div>

                <?php endforeach; ?>

            </div>

            <button
                class="button button-primary"
                name="save_settings"
            >
                Save Modules
            </button>

        </form>

    <?php }

    /*
    |--------------------------------------------------------------------------
    | Notifications Tab
    |--------------------------------------------------------------------------
    */

    public function notifications_tab(){ ?>

        <div class="apiplatform-card">

            <h3>Notifications</h3>

            <p>
                Twilio:
                <?php
                echo get_option(
                    'apiplatform_twilio_sid'
                )
                    ? 'Connected'
                    : 'Not configured';
                ?>
            </p>

            <p>
                Firebase:
                <?php
                echo get_option(
                    'apiplatform_firebase_key'
                )
                    ? 'Connected'
                    : 'Not configured';
                ?>
            </p>

            <p>
                Pusher:
                <?php
                echo get_option(
                    'apiplatform_pusher_key'
                )
                    ? 'Connected'
                    : 'Not configured';
                ?>
            </p>

        </div>

    <?php }

    /*
    |--------------------------------------------------------------------------
    | Routes Tab
    |--------------------------------------------------------------------------
    */

    public function routes_tab(){

        $prefixes = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::prefixes() : [
            'apiplatform_gateway_prefix' => 'gateway',
            'apiplatform_dashboard_prefix' => 'dashboard',
            'apiplatform_developer_prefix' => 'developers',
            'apiplatform_docs_prefix' => 'docs',
            'apiplatform_auth_prefix' => 'auth',
        ];

        settings_errors('apiplatform_routes');

        ?>

        <form method="post">

            <?php wp_nonce_field('apiplatform_settings_nonce'); ?>

            <div class="apiplatform-card">

                <h3>Routes</h3>

                <p>Configure the public URL prefixes used by FreedomAPI. Prefixes must be unique, URL-safe, and cannot conflict with reserved WordPress routes.</p>

                <?php
                $fields = [
                    'apiplatform_gateway_prefix' => ['Gateway', 'Public runtime endpoint prefix. Example: /gateway/{publisher}/{api}'],
                    'apiplatform_dashboard_prefix' => ['Dashboard', 'Publisher dashboard page prefix. Example: /dashboard'],
                    'apiplatform_developer_prefix' => ['Developer Portal', 'Public developer portal prefix. Example: /developers'],
                    'apiplatform_docs_prefix' => ['Documentation', 'Frontend documentation library prefix. Example: /docs'],
                    'apiplatform_auth_prefix' => ['Authentication', 'External login route prefix. Example: /auth/google/callback'],
                ];

                foreach ($fields as $name => $field):
                ?>

                    <p>
                        <label>
                            <strong><?php echo esc_html($field[0]); ?></strong><br>
                            <input
                                class="regular-text"
                                type="text"
                                name="<?php echo esc_attr($name); ?>"
                                value="<?php echo esc_attr($prefixes[$name] ?? ''); ?>"
                                autocomplete="off"
                            >
                        </label><br>
                        <span><?php echo esc_html($field[1]); ?></span>
                    </p>

                <?php endforeach; ?>

                <?php if (class_exists('APIPlatform_Routes')): ?>
                    <h4>Current URLs</h4>
                    <p><code><?php echo esc_html(APIPlatform_Routes::gateway_url('{publisher}', '{api}')); ?></code></p>
                    <p><code><?php echo esc_html(APIPlatform_Routes::dashboard_url()); ?></code></p>
                    <p><code><?php echo esc_html(APIPlatform_Routes::developer_url()); ?></code></p>
                    <p><code><?php echo esc_html(APIPlatform_Routes::docs_url()); ?></code></p>
                    <p><code><?php echo esc_html(APIPlatform_Routes::auth_callback_url('google')); ?></code></p>
                <?php endif; ?>

            </div>

            <button
                class="button button-primary"
                name="save_route_settings"
            >
                Save Routes
            </button>

        </form>

    <?php }

    /*
    |--------------------------------------------------------------------------
    | Frontend Wrapper Pages Tab
    |--------------------------------------------------------------------------
    */
    public function frontend_pages_tab(){
        if (!class_exists('APIPlatform_Frontend_Layout')) { echo '<div class="notice notice-error"><p>The frontend layout module is not loaded.</p></div>'; return; }
        $definitions = APIPlatform_Frontend_Layout::wrapper_page_definitions();
        settings_errors('apiplatform_frontend_pages');
        ?>
        <div class="apiplatform-card">
            <h3>Frontend Pages</h3>
            <p>Normal users reach existing FreedomAPI renderers through published WordPress wrapper pages. A matching slug alone is not enough: the page must contain the expected <code>[api_fulllayout_page]</code> shortcode and page identifier.</p>
            <table class="widefat striped"><thead><tr><th>Page</th><th>Path</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($definitions as $identifier => $definition): $status = $this->frontend_page_status($identifier, $definition); ?>
                <tr><td><?php echo esc_html($definition['title']); ?></td><td><code>/<?php echo esc_html($definition['slug']); ?></code></td><td><?php echo esc_html($status['label']); ?></td><td>
                <?php if ($status['state'] === 'missing'): ?><form method="post"><?php wp_nonce_field('apiplatform_frontend_pages', 'apiplatform_frontend_pages_nonce'); ?><input type="hidden" name="apiplatform_frontend_page_action" value="create"><input type="hidden" name="frontend_page_identifier" value="<?php echo esc_attr($identifier); ?>"><button class="button button-secondary">Create Page</button></form><?php else: ?><span class="description">No automatic overwrite</span><?php endif; ?>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    <?php }

    private function frontend_page_status($identifier, array $definition){
        $pages = get_posts(['post_type' => 'page', 'name' => $definition['slug'], 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1, 'no_found_rows' => true]);
        if (!$pages) return ['state' => 'missing', 'label' => 'Missing'];
        if (count($pages) > 1) return ['state' => 'conflict', 'label' => 'Duplicate/Conflict'];
        $page = $pages[0];
        if ($page->post_status === 'trash') return ['state' => 'trashed', 'label' => 'Trashed'];
        if ($page->post_status !== 'publish') return ['state' => 'draft', 'label' => 'Draft'];
        if (!has_shortcode($page->post_content, 'api_fulllayout_page')) return ['state' => 'wrong_shortcode', 'label' => 'Wrong Shortcode'];
        $matches = [];
        preg_match_all('/' . get_shortcode_regex(['api_fulllayout_page']) . '/', $page->post_content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) { $atts = shortcode_parse_atts($match[3]); if (sanitize_key($atts['page'] ?? '') === $identifier) return ['state' => 'configured', 'label' => 'Configured']; }
        return ['state' => 'wrong_identifier', 'label' => 'Wrong Page Identifier'];
    }
    /*
    |--------------------------------------------------------------------------
    | Debug Tab
    |--------------------------------------------------------------------------
    */

    public function debug_tab(){

        global $wpdb;

        $table =
            $wpdb->prefix .
            'apiplatform_logs';

        ?>

        <div class="apiplatform-card">

            <h3>Debug Info</h3>

            <pre>
<?php
print_r(
    get_option(
        'apiplatform_modules'
    )
);
?>
            </pre>

            <h4>System Health</h4>

            <p>

                Logs Table:

                <?php
                echo $wpdb->get_var(
                    "SHOW TABLES LIKE '$table'"
                ) === $table
                    ? 'OK'
                    : 'Missing';
                ?>

            </p>

        </div>

    <?php }

    /*
    |--------------------------------------------------------------------------
    | Styles
    |--------------------------------------------------------------------------
    */

    public function styles(){ ?>

        <style>

        .apiplatform-card {

            background:#1e1e1e;
            color:#fff;
            padding:20px;
            margin:20px 0;
            border-radius:10px;
        }

        .apiplatform-card h3 {
            color:white;
        }

        .apiplatform-card h1 {
            color:lightblue;
        }

        .apiplatform-module {

            display:flex;
            justify-content:space-between;
            padding:10px 0;
            border-bottom:1px solid #333;
        }

        .status.on {
            color:lime;
        }

        .status.off {
            color:red;
        }

        .apiplatform-grid {

            display:grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(200px,1fr)
                );

            gap:20px;
        }

        </style>

    <?php }
}
