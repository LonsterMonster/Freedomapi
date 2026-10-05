<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Template_Builder {

    public function __construct(){
        add_shortcode('api_builder', [$this,'render']);
        add_action('init', [$this,'handle_save']);
    }

    /*
    ----------------------------------------
    SAVE API
    ----------------------------------------
    */
    public function handle_save(){

        if (!is_user_logged_in()) return;
        if (!isset($_POST['create_api'])) return;

        $user_id = get_current_user_id();

        $nonce = isset($_POST['apiplatform_template_builder_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['apiplatform_template_builder_nonce']))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'apiplatform_template_builder')) {
            return;
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

        if (function_exists('apiplatform_get_plan_limits')) {
            $limits = apiplatform_get_plan_limits($user_id);
            $api_limit = $limits['api_limit'] ?? INF;

            if (!APIPlatform_Membership_Panel::is_unlimited($api_limit)) {
                $api_count = count(get_posts([
                    'post_type' => 'user_api',
                    'author' => $user_id,
                    'post_status' => ['publish', 'draft', 'pending', 'private'],
                    'fields' => 'ids',
                    'numberposts' => -1,
                    'no_found_rows' => true,
                ]));

                if ($api_count >= (int) $api_limit) {
                    return;
                }
            }
        }

        $api_name = isset($_POST['api_name'])
            ? sanitize_text_field(wp_unslash($_POST['api_name']))
            : '';

        if ($api_name === '') {
            return;
        }

        $api_id = wp_insert_post([
            'post_type'=>'user_api',
            'post_title'=>$api_name,
            'post_status'=>'publish',
            'post_author'=>$user_id
        ], true);

        if (is_wp_error($api_id) || !$api_id) {
            return;
        }

        if (class_exists('APIPlatform_Ownership_Service')) {
            APIPlatform_Ownership_Service::assign($api_id, 'personal', $user_id, $user_id);
        } else {
            update_post_meta($api_id, 'apiplatform_owner_type', 'personal');
            update_post_meta($api_id, 'apiplatform_owner_id', $user_id);
            update_post_meta($api_id, 'apiplatform_created_by_user_id', $user_id);
        }

        $template = isset($_POST['template'])
            ? sanitize_key(wp_unslash($_POST['template']))
            : '';

        $category = isset($_POST['category'])
            ? sanitize_key(wp_unslash($_POST['category']))
            : '';

        $fields = isset($_POST['fields']) && is_array($_POST['fields'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['fields']))
            : [];

        // SAVE TEMPLATE + FIELDS
        update_post_meta($api_id,'api_template',$template);
        update_post_meta($api_id,'api_category',$category);
        update_post_meta($api_id,'api_fields',$fields);
        update_post_meta($api_id,'apiplatform_status','active');
        update_post_meta($api_id,'api_logic',wp_json_encode([
            'steps' => [
                [
                    'type' => 'response',
                    'data' => [
                        'template' => $template,
                        'category' => $category,
                        'fields' => $fields,
                    ],
                ],
            ],
        ]));

        if (!$this->ensure_default_api_key($api_id)) {
            wp_die(esc_html__('Unable to securely create API key.', 'apiplatform'));
        }

    }

    /*
    ----------------------------------------
    RENDER BUILDER
    ----------------------------------------
    */
    public function render(){

        if (!is_user_logged_in()) return 'Login required';

        $templates = apiplatform_get_templates();

        ob_start(); ?>

        <div class="apiplatform-builder">

            <h2>Create API</h2>

            <form method="post">

                <?php wp_nonce_field('apiplatform_template_builder', 'apiplatform_template_builder_nonce'); ?>

                <input type="text" name="api_name" placeholder="API Name" required>

                <h3>Choose Category</h3>

                <select name="category" id="category_select">
                    <?php foreach($templates as $key => $cat): ?>
                        <option value="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($cat['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <h3>Choose Template</h3>

                <select name="template" id="template_select">
                    <?php foreach($templates as $cat_key => $cat): ?>
                        <?php foreach($cat['templates'] as $tpl_key => $tpl): ?>
                            <option value="<?php echo esc_attr($tpl_key); ?>">
                                <?php echo esc_html($tpl['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>

                <div id="template_fields">

                    <?php foreach($templates as $cat): ?>
                        <?php foreach($cat['templates'] as $tpl_key => $tpl): ?>

                            <div class="tpl-fields" data-template="<?php echo esc_attr($tpl_key); ?>" style="display:none;">

                                <?php foreach($tpl['fields'] as $field_key => $field): ?>

                                    <label><?php echo esc_html($field['label']); ?></label>
                                    <input type="text" name="fields[<?php echo esc_attr($field_key); ?>]">

                                <?php endforeach; ?>

                            </div>

                        <?php endforeach; ?>
                    <?php endforeach; ?>

                </div>

                <button name="create_api" class="button button-primary">Create API</button>

            </form>

        </div>

        <script>
        document.getElementById('template_select').addEventListener('change', function(){

            document.querySelectorAll('.tpl-fields').forEach(el=>el.style.display='none');

            let selected = this.value;
            let target = document.querySelector('[data-template="'+selected+'"]');

            if(target) target.style.display='block';

        });
        </script>

        <?php
        return ob_get_clean();
    }

    private function ensure_default_api_key($api_id){

        $keys = get_post_meta($api_id, 'api_keys', true);
        if (is_array($keys) && !empty($keys)) {
            return true;
        }

        if (!function_exists('apiplatform_create_hashed_key_entry')) {
            return false;
        }

        do {
            $key = function_exists('apiplatform_generate_api_key_secret')
                ? apiplatform_generate_api_key_secret()
                : (class_exists('APIPlatform_Core') && method_exists('APIPlatform_Core', 'generate_key')
                    ? APIPlatform_Core::generate_key('apk_live_')
                    : 'apk_live_' . wp_generate_password(48, false, false));
        } while ($this->api_key_exists($key));

        $keys = [apiplatform_create_hashed_key_entry($key, 'Default Key')];
        $keys_updated = update_post_meta($api_id, 'api_keys', $keys);
        if ($keys_updated === false || get_post_meta($api_id, 'api_keys', true) !== $keys) {
            return false;
        }

        $display_key = function_exists('apiplatform_mask_api_key') ? apiplatform_mask_api_key($key) : '';
        $display_updated = update_post_meta($api_id, 'api_key', $display_key);
        if ($display_updated === false || get_post_meta($api_id, 'api_key', true) !== $display_key) {
            return false;
        }

        return true;
    }

    private function api_key_exists($key){

        $legacy = get_posts([
            'post_type' => 'user_api',
            'post_status' => 'any',
            'meta_key' => 'api_key',
            'meta_value' => $key,
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);

        if (!empty($legacy)) {
            return true;
        }

        $apis = get_posts([
            'post_type' => 'user_api',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);

        foreach ($apis as $api_id) {
            if (function_exists('apiplatform_validate_api_key') && apiplatform_validate_api_key($api_id, $key)) {
                return true;
            }
        }

        return false;
    }
}
