<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Module {

    private static $booted = false;

    public function __construct(){
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        APIPlatform_Component_Registry::instance()->load_components_from_directory(APIPLATFORM_BUILDER_PATH . 'components/');

        add_action('admin_menu', [$this, 'register_admin_page'], 30);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_admin_page(){
        add_submenu_page(
            'apiplatform',
            __('API Builder', 'apiplatform'),
            __('API Builder', 'apiplatform'),
            'manage_options',
            'apiplatform-builder',
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets(){
        if (!is_admin() || ($_GET['page'] ?? '') !== 'apiplatform-builder') {
            return;
        }

        wp_enqueue_style(
            'apiplatform-builder',
            APIPLATFORM_URL . 'modules/builder/assets/css/builder.css',
            [],
            apiplatform_asset_version('modules/builder/assets/css/builder.css')
        );

        wp_enqueue_script(
            'apiplatform-builder',
            APIPLATFORM_URL . 'modules/builder/assets/js/builder.js',
            [],
            apiplatform_asset_version('modules/builder/assets/js/builder.js'),
            true
        );

        wp_localize_script('apiplatform-builder', 'APIPlatformBuilderData', [
            'components' => APIPlatform_Component_Registry::instance()->get_all_components(),
            'i18n' => [
                'selectComponent' => __('Select a component', 'apiplatform'),
                'addComponent'    => __('Add Component', 'apiplatform'),
                'remove'          => __('Remove', 'apiplatform'),
            ],
        ]);
    }

    public function render_admin_page(){
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'apiplatform'));
        }

        $api_id = isset($_GET['api_id']) ? absint($_GET['api_id']) : 0;
        $api_name = '';
        $component_tree = ['version' => 1, 'components' => []];

        if ($api_id) {
            $api = get_post($api_id);

            if ($api && $api->post_type === 'user_api') {
                $api_name = $api->post_title;
                $saved_tree = get_post_meta($api_id, 'api_builder_components', true);
                $decoded_tree = apiplatform_builder_decode_json($saved_tree, []);

                if (apiplatform_builder_is_component_tree($decoded_tree)) {
                    $component_tree = apiplatform_builder_normalize_component_tree($decoded_tree);
                } elseif (class_exists('APIPlatform_API_Migrator')) {
                    $migrator = new APIPlatform_API_Migrator();
                    $migrated = $migrator->migrate_old_api($api_id);

                    if (apiplatform_builder_is_component_tree($migrated)) {
                        $component_tree = apiplatform_builder_normalize_component_tree($migrated);
                    }
                }
            }
        }

        $components = APIPlatform_Component_Registry::instance()->get_all_components();
        include APIPLATFORM_BUILDER_PATH . 'views/admin-builder-ui.php';
    }
}
