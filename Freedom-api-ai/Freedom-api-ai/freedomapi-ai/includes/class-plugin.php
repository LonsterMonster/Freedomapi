<?php
if (!defined('ABSPATH')) exit;

final class FreedomAPI_AI_Plugin {
    public static function compatible() {
        foreach (['freedomapi_extension_version', 'freedomapi_can_manage_api', 'freedomapi_get_api_configuration',
            'freedomapi_preview_transformation', 'freedomapi_update_api_configuration', 'freedomapi_rollback_api_configuration',
            'freedomapi_validate_transformation'] as $function) if (!function_exists($function)) return false;
        $version = freedomapi_extension_version();
        return version_compare($version, '1.0.0', '>=') && version_compare($version, '2.0.0', '<');
    }

    public static function boot() {
        if (!self::compatible() || !FreedomAPI_AI_Connection::available()) {
            add_action('admin_notices', [self::class, 'dependency_notice']);
            add_action('network_admin_notices', [self::class, 'dependency_notice']);
            return;
        }
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('freedomapi_api_management_sections', [self::class, 'render'], 10, 3);
    }

    public static function dependency_notice() {
        $message = !self::compatible()
            ? 'FreedomAPI AI requires an active FreedomAPI Core (API Platform2) with extension interface 1.x. Install or update Core to enable AI features.'
            : 'FreedomAPI AI requires the PHP OpenSSL extension with AES-256-GCM support. AI features are disabled.';
        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }

    public static function routes() {
        foreach (['configuration' => 'GET', 'connection' => ['POST', 'DELETE'], 'proposals' => 'POST', 'approve' => 'POST', 'rollback' => 'POST'] as $operation => $methods) {
            register_rest_route('freedomapi-ai/v1', '/apis/(?P<api_id>[1-9][0-9]*)/' . $operation, [
                'methods' => $methods, 'permission_callback' => [self::class, 'permission'],
                'callback' => function ($request) use ($operation) {
                    $result = self::dispatch($operation, $request);
                    return is_wp_error($result) ? $result : new WP_REST_Response($result, 200, ['Cache-Control' => 'no-store, private']);
                },
            ]);
        }
    }

    public static function permission($request) {
        if (!self::compatible()) return new WP_Error('core_unavailable', 'Compatible FreedomAPI Core is required.', ['status' => 503]);
        if (!get_current_user_id() || !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) return new WP_Error('forbidden', 'A valid signed-in session is required.', ['status' => 403]);
        if (!freedomapi_can_manage_api(absint($request['api_id']))) return new WP_Error('forbidden', 'You cannot manage this API.', ['status' => 403]);
        return true;
    }

    public static function dispatch($operation, $request) {
        $permission = self::permission($request);
        if (is_wp_error($permission)) return $permission;
        $api_id = absint($request['api_id']);
        if (strlen($request->get_body()) > 8192) return new WP_Error('payload_too_large', 'Request is too large.', ['status' => 413]);
        $data = $request->get_json_params() ?: [];
        if (!is_array($data)) return new WP_Error('invalid_request', 'Expected a JSON object.', ['status' => 400]);
        switch ($operation) {
            case 'configuration':
                $config = freedomapi_get_api_configuration($api_id);
                if (is_wp_error($config)) return $config;
                $config['connection'] = FreedomAPI_AI_Connection::status();
                return $config;
            case 'connection':
                return $request->get_method() === 'DELETE' ? FreedomAPI_AI_Connection::disconnect()
                    : FreedomAPI_AI_Connection::save($data['key'] ?? null, $data['model'] ?? null);
            case 'proposals': return self::propose($api_id, $data);
            case 'approve': return self::approve($api_id, $data);
            case 'rollback':
                if (($data['approved'] ?? false) !== true) return new WP_Error('approval_required', 'Explicit rollback approval is required.', ['status' => 400]);
                return freedomapi_rollback_api_configuration($api_id, $data['fingerprint'] ?? '');
        }
        return new WP_Error('invalid_operation', 'Unknown operation.', ['status' => 400]);
    }

    private static function propose($api_id, $data) {
        if (($data['consent'] ?? false) !== true) return new WP_Error('consent_required', 'Confirm sending the source JSON and instruction to OpenAI.', ['status' => 400]);
        $user = get_current_user_id();
        $lock = 'freedomapi_ai_generation_' . $user;
        if (!add_option($lock, time(), '', false)) return new WP_Error('generation_busy', 'A generation is already in progress.', ['status' => 429]);
        try {
            if (get_transient($lock . '_cooldown')) return new WP_Error('rate_limited', 'Wait a few seconds before generating again.', ['status' => 429]);
            $config = freedomapi_get_api_configuration($api_id);
            if (is_wp_error($config)) return $config;
            if (!is_string($data['fingerprint'] ?? null) || !hash_equals($config['fingerprint'], $data['fingerprint'])) return new WP_Error('conflict', 'Configuration changed. Reload the editor.', ['status' => 409]);
            set_transient($lock . '_cooldown', true, 10);
            $plan = FreedomAPI_AI_OpenAI::generate($data['instruction'] ?? null, $config);
            if (is_wp_error($plan)) return $plan;
            $preview = freedomapi_preview_transformation($api_id, $plan, $config['fingerprint']);
            if (is_wp_error($preview)) return $preview;
            $id = bin2hex(random_bytes(24));
            $proposal = ['user_id' => $user, 'api_id' => $api_id, 'expires' => time() + 900, 'preview' => $preview];
            if (!set_transient('freedomapi_ai_proposal_' . $id, $proposal, 900)) return new WP_Error('storage_error', 'Could not save the proposal.', ['status' => 500]);
            return array_merge($preview, ['proposal_id' => $id, 'expires' => $proposal['expires']]);
        } finally { delete_option($lock); }
    }

    private static function approve($api_id, $data) {
        if (($data['approved'] ?? false) !== true) return new WP_Error('approval_required', 'Explicit approval is required.', ['status' => 400]);
        $id = $data['proposal_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id)) return new WP_Error('invalid_proposal', 'Invalid proposal.', ['status' => 400]);
        $proposal = get_transient('freedomapi_ai_proposal_' . $id);
        if (!$proposal || $proposal['expires'] < time()) return new WP_Error('proposal_expired', 'Proposal expired. Generate a new preview.', ['status' => 410]);
        if ($proposal['api_id'] !== $api_id || $proposal['user_id'] !== get_current_user_id()) return new WP_Error('forbidden', 'This proposal belongs to another user or API.', ['status' => 403]);
        $preview = $proposal['preview'];
        $result = freedomapi_update_api_configuration($api_id, $preview['plan'], $preview['fingerprint']);
        if (!is_wp_error($result)) delete_transient('freedomapi_ai_proposal_' . $id);
        return $result;
    }

    public static function render($html, $api_id, $user_id) {
        if ($user_id !== get_current_user_id() || !freedomapi_can_manage_api($api_id)) return $html;
        $url = plugin_dir_url(FREEDOMAPI_AI_FILE);
        wp_enqueue_style('freedomapi-ai', $url . 'assets/editor.css', [], FREEDOMAPI_AI_VERSION);
        wp_enqueue_script('freedomapi-ai', $url . 'assets/editor.js', [], FREEDOMAPI_AI_VERSION, true);
        ob_start();
        // Shortcodes can render after wp_head; print the queued stylesheet once here.
        wp_print_styles('freedomapi-ai');
        include dirname(__DIR__) . '/views/editor.php';
        return $html . ob_get_clean();
    }
}
