<?php
if (!defined('ABSPATH')) exit;

$api_id = isset($api_id) ? absint($api_id) : 0;
$api_name = isset($api_name) ? $api_name : '';
$component_tree = isset($component_tree) && is_array($component_tree) ? $component_tree : ['version' => 1, 'components' => []];
$components = isset($components) && is_array($components) ? $components : [];
$endpoint_url = $api_id
    ? (
        function_exists('apiplatform_public_gateway_url')
            ? apiplatform_public_gateway_url($api_id)
            : (
                class_exists('APIPlatform_Routes')
                    ? APIPlatform_Routes::gateway_url(get_the_author_meta('user_nicename', (int) get_post_field('post_author', $api_id)), get_post_field('post_name', $api_id))
                    : rest_url('platform/v1/api/' . get_post_field('post_name', $api_id))
            )
    )
    : '';
$api_key = $api_id ? get_post_meta($api_id, 'api_key', true) : '';
$api_key_display = $api_key && function_exists('apiplatform_mask_api_key')
    ? apiplatform_mask_api_key($api_key)
    : $api_key;
$component_count = isset($component_tree['components']) && is_array($component_tree['components']) ? count($component_tree['components']) : 0;
?>
<div class="wrap apiplatform-builder-wrap">
    <h1><?php esc_html_e('API Builder', 'apiplatform'); ?></h1>

    <?php if (!empty($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('API saved successfully.', 'apiplatform'); ?></p></div>
    <?php endif; ?>

    <div class="apiplatform-builder-grid">
        <section class="apiplatform-builder-main">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="apiplatform-builder-form">
                <?php wp_nonce_field('apiplatform_builder_save_api', 'apiplatform_builder_nonce'); ?>
                <input type="hidden" name="action" value="apiplatform_builder_save_api">
                <input type="hidden" name="api_id" value="<?php echo esc_attr($api_id); ?>">
                <input type="hidden" name="component_tree" id="apiplatform-component-tree" value="<?php echo esc_attr(wp_json_encode($component_tree)); ?>">

                <div class="apiplatform-builder-card">
                    <label for="apiplatform-api-name"><strong><?php esc_html_e('API Name', 'apiplatform'); ?></strong></label>
                    <input type="text" id="apiplatform-api-name" name="api_name" class="regular-text" value="<?php echo esc_attr($api_name); ?>" placeholder="<?php esc_attr_e('Example: User Profile API', 'apiplatform'); ?>">
                    <p class="description"><?php esc_html_e('Build an API response by stacking one or more reusable components. Saving keeps the old api_logic format for runtime compatibility.', 'apiplatform'); ?></p>
                </div>

                <div class="apiplatform-builder-card">
                    <div class="apiplatform-builder-toolbar">
                        <div>
                            <h2><?php esc_html_e('Response Stack', 'apiplatform'); ?></h2>
                            <p class="description" id="apiplatform-component-count">
                                <?php printf(esc_html(_n('%d component configured.', '%d components configured.', $component_count, 'apiplatform')), $component_count); ?>
                            </p>
                        </div>
                        <div class="apiplatform-toolbar-actions">
                            <button type="button" class="button" id="apiplatform-add-component"><?php esc_html_e('Add Component', 'apiplatform'); ?></button>
                            <button type="button" class="button" id="apiplatform-clear-components"><?php esc_html_e('Clear Stack', 'apiplatform'); ?></button>
                        </div>
                    </div>
                    <div id="apiplatform-component-canvas" class="apiplatform-component-canvas" aria-live="polite"></div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save API', 'apiplatform'); ?></button>
                </p>
            </form>
        </section>

        <aside class="apiplatform-builder-sidebar">
            <div class="apiplatform-builder-card">
                <h2><?php esc_html_e('Available Components', 'apiplatform'); ?></h2>
                <select id="apiplatform-component-picker" class="widefat">
                    <?php foreach ($components as $component): ?>
                        <option value="<?php echo esc_attr($component['name']); ?>"><?php echo esc_html($component['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Choose a component and add it to the response stack. Components can be reordered, edited, or removed before saving.', 'apiplatform'); ?></p>
            </div>

            <div class="apiplatform-builder-card">
                <h2><?php esc_html_e('Live Response Preview', 'apiplatform'); ?></h2>
                <pre id="apiplatform-response-preview" class="apiplatform-response-preview">{}</pre>
                <p class="description"><?php esc_html_e('The preview is generated from component metadata in the browser. The saved API is still rendered server-side.', 'apiplatform'); ?></p>
            </div>

            <?php if ($api_id): ?>
                <div class="apiplatform-builder-card">
                    <h2><?php esc_html_e('Endpoint', 'apiplatform'); ?></h2>
                    <p><code><?php echo esc_html($endpoint_url); ?></code></p>
                    <?php if ($api_key_display): ?>
                        <p><strong><?php esc_html_e('API Key', 'apiplatform'); ?></strong></p>
                        <p><code><?php echo esc_html($api_key_display); ?></code></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
