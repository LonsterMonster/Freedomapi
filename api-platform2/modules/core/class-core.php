<?PHP class APIPlatform_Core {

    public function __construct(){
        add_action('init', [$this,'register_post_type']);
        add_action('save_post_user_api', [$this,'save_api'],10,3);
    }

    public function register_post_type(){
        register_post_type('user_api', [
            'label'=>'APIs',
            'public'=>true,
            'show_ui'=>true,
            'show_in_menu'=>true,
            'supports'=>['title'],
            'rewrite'=>['slug'=>'api'],
            'capability_type'=>'post'
        ]);
    }

    public static function generate_key($prefix='apk_live_'){
        return $prefix . wp_generate_password(32,false,false);
    }

    public static function track($id){

        $count=(int)get_post_meta($id,'api_usage',true);
        update_post_meta($id,'api_usage',$count+1);

        $today=date('Y-m-d');
        $daily=get_post_meta($id,'api_usage_daily',true) ?: [];

        if(!isset($daily[$today])) $daily[$today]=0;
        $daily[$today]++;

        update_post_meta($id,'api_usage_daily',$daily);

        $owner_id = get_post_field('post_author', $id);
        if ($owner_id) {
            delete_transient('apiplatform_usage_' . $owner_id);
        }
    }

    public function save_api($post_id){
		
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)){
            $this->debug_log('[API STORAGE] Skipped revision/autosave for Post ID: ' . $post_id);
            return;
        }

        if (!current_user_can('edit_post',$post_id)){
            $this->debug_log('[API AUTH] Permission Check Failed: edit_post for Post ID ' . $post_id);
            return;
        }

        if (isset($_POST['apiplatform_edit_api_action'])){
            return;
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'apiplatform_save_api')){
            $this->debug_log('[API AUTH] Permission Check Failed: save nonce for Post ID ' . $post_id);
            return;
        }

        if(isset($_POST['api_json'])){
            $json=wp_unslash($_POST['api_json']);
            json_decode($json);

            if(json_last_error()===JSON_ERROR_NONE){
                update_post_meta($post_id,'api_json',$json);
                $this->debug_storage($post_id, 'api_json', $json);
                $this->debug_log('[API JSON VALIDATION] API ID: ' . $post_id);
                $this->debug_log('[API JSON VALIDATION] Validation Result: success');
                $this->debug_log('[API JSON SAVE] API ID: ' . $post_id);
                $this->debug_log('[API JSON SAVE] Save Success');
            } else {
                $this->debug_log('[API STORAGE] Storage Failed: invalid api_json for Post ID ' . $post_id);
                $this->debug_log('[API JSON VALIDATION] API ID: ' . $post_id);
                $this->debug_log('[API JSON VALIDATION] Validation Error: ' . json_last_error_msg());
                $this->debug_log('[API JSON SAVE] Save Failure');
            }
        }

        $keys = get_post_meta($post_id, 'api_keys', true);
        if(!is_array($keys) || empty($keys)){
            if (!function_exists('apiplatform_create_hashed_key_entry')) {
                $this->debug_log('[API STORAGE] Secure key storage unavailable for Post ID ' . $post_id);
                return;
            }

            $key = function_exists('apiplatform_generate_api_key_secret')
                ? apiplatform_generate_api_key_secret()
                : self::generate_key('apk_live_');

            $this->debug_log('[API CREATE] Generated API Key: created');
            $this->debug_log('[API CREATE] Post ID: ' . $post_id);
            $this->debug_log('[API CREATE] User ID: ' . get_post_field('post_author', $post_id));
            $this->debug_log('[API CREATE] API Name: ' . get_the_title($post_id));
            $this->debug_log('[API CREATE] Generated Slug: ' . get_post_field('post_name', $post_id));

            $display_key = function_exists('apiplatform_mask_api_key')
                ? apiplatform_mask_api_key($key)
                : '';

            $default_keys = [apiplatform_create_hashed_key_entry($key, 'Default Key')];

            $display_updated = update_post_meta($post_id,'api_key',$display_key);
            $this->debug_storage($post_id, 'api_key', $display_key);
            $keys_updated = update_post_meta($post_id,'api_keys',$default_keys);
            $this->debug_storage($post_id, 'api_keys', $default_keys);

            if (
                $display_updated === false ||
                $keys_updated === false ||
                get_post_meta($post_id, 'api_keys', true) !== $default_keys
            ) {
                $this->debug_log('[API STORAGE] Secure key storage failed for Post ID ' . $post_id);
                return;
            }

            $this->debug_log('[API CREATE] Creation Successful');
        }
    }

    private function debug_log($message){

        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log($message);
        }
    }

    private function debug_storage($post_id, $meta_key, $value){

        if (defined('WP_DEBUG') && WP_DEBUG){
            error_log('[API STORAGE] Post ID: ' . $post_id);
            error_log('[API STORAGE] Meta Key: ' . $meta_key);
            $stored_value = in_array($meta_key, ['api_key', 'api_keys'], true)
                ? 'redacted'
                : print_r($value, true);
            error_log('[API STORAGE] Saved Value: ' . $stored_value);
            error_log('[API STORAGE] Storage Result: ' . (get_post_meta($post_id, $meta_key, true) === $value ? 'success' : 'verify manually'));
        }
    }
}
