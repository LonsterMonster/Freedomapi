<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Auth_Controller {
    const ROUTES_VERSION = '1-0';
    const BUILD_MARKER = '10a-callback-fix-1';

    public function __construct(){
        add_action('init', [$this, 'register_routes'], 12);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'dispatch'], 0);
        add_action('login_form', [$this, 'render_login_buttons']);
        add_shortcode('apiplatform_connected_accounts', [$this, 'connected_accounts']);
    }

    public function register_routes(){
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_prefix() : 'auth';

        add_rewrite_rule('^' . $prefix . '/accounts/?$', 'index.php?apiplatform_auth_action=accounts', 'top');
        add_rewrite_rule('^' . $prefix . '/([^/]+)/callback/?$', 'index.php?apiplatform_auth_action=callback&apiplatform_auth_provider=$matches[1]', 'top');
        add_rewrite_rule('^' . $prefix . '/([^/]+)/?$', 'index.php?apiplatform_auth_action=start&apiplatform_auth_provider=$matches[1]', 'top');

        $version = self::ROUTES_VERSION . '-' . md5($prefix);
        if (get_option('apiplatform_auth_routes_version') !== $version) {
            flush_rewrite_rules(false);
            update_option('apiplatform_auth_routes_version', $version);
        }
    }

    public function query_vars($vars){
        $vars[] = 'apiplatform_auth_action';
        $vars[] = 'apiplatform_auth_provider';
        return $vars;
    }

    public function dispatch(){
        $action = sanitize_key(get_query_var('apiplatform_auth_action'));
        $provider_id = sanitize_key(get_query_var('apiplatform_auth_provider'));

        if (!$action) {
            $matched = $this->route_from_path();
            $action = $matched['action'];
            $provider_id = $matched['provider'];
        }

        if (!$action) return;

        $this->emit_build_marker($action);

        if ($action === 'accounts') {
            $this->render_accounts_page();
        }

        if (!in_array($action, ['start', 'callback'], true)) {
            $this->debug_auth_event($action, '', [
                'branch_name' => 'invalid_auth_action',
                'final_error_code' => 'missing_provider',
                'final_http_status' => 404,
            ]);
            $this->safe_error('This authentication provider does not exist.', 404, 'missing_provider');
        }

        if (!APIPlatform_Auth_Providers::valid_slug($provider_id)) {
            $this->debug_auth_event($action, $provider_id, [
                'branch_name' => 'invalid_provider_slug',
                'final_error_code' => 'missing_provider',
                'final_http_status' => 404,
            ]);
            $this->safe_error('This authentication provider does not exist.', 404, 'missing_provider');
        }

        $provider = APIPlatform_Auth_Providers::get($provider_id);
        if (!$provider) {
            $this->debug_auth_event($action, $provider_id, [
                'branch_name' => 'provider_not_found',
                'final_error_code' => 'missing_provider',
                'final_http_status' => 404,
            ]);
            $this->safe_error('This authentication provider does not exist.', 404, 'missing_provider');
        }

        $settings = APIPlatform_Auth_Providers::settings($provider_id);
        if (!APIPlatform_Auth_Providers::is_enabled($provider_id)) {
            APIPlatform_Auth_Audit::record('external_login_failed', ['provider' => $provider_id, 'reason' => 'disabled']);
            $this->debug_auth_event($action, $provider_id, [
                'branch_name' => 'provider_disabled',
                'provider_enabled' => 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'final_error_code' => 'provider_disabled',
                'final_http_status' => 403,
            ]);
            $this->safe_error($provider->get_name() . ' sign-in is currently disabled.', 403, 'provider_disabled');
        }

        if (!$this->rate_limit($action, $provider_id)) {
            $this->debug_auth_event($action, $provider_id, [
                'branch_name' => 'auth_rate_limited',
                'provider_enabled' => 'yes',
                'final_error_code' => 'rate_limited',
                'final_http_status' => 429,
            ]);
            $this->safe_error('Authentication is temporarily unavailable.', 429, 'rate_limited');
        }

        if ($action === 'start') {
            $this->start($provider);
        }

        $this->callback($provider);
    }

    public function render_login_buttons(){
        $buttons = class_exists('APIPlatform_Auth_Buttons')
            ? APIPlatform_Auth_Buttons::render([
                'context' => 'wp_login',
                'return_url' => sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? '')),
                'show_separator' => true,
            ])
            : $this->provider_buttons();
        if ($buttons !== '') {
            echo $buttons;
        }
    }

    public function connected_accounts(){
        if (!is_user_logged_in()) {
            return '<p>Login required.</p>';
        }

        $linked = APIPlatform_External_Identity_Service::linked_for_user(get_current_user_id());
        $by_provider = [];
        foreach ($linked as $row) {
            $by_provider[$row['provider']] = $row;
        }

        $notice = $this->notice_html();
        $html = '<div class="apiplatform-connected-accounts"><h2>Connected accounts</h2>' . $notice;
        foreach (APIPlatform_Auth_Providers::all() as $provider) {
            $meta = APIPlatform_Auth_Providers::public_metadata($provider);
            $row = $by_provider[$provider->get_id()] ?? null;
            $provider_enabled = APIPlatform_Auth_Providers::is_enabled($provider->get_id());
            if (!$row && !$provider_enabled) {
                continue;
            }

            $html .= '<article class="apiplatform-connected-account"><h3>' . esc_html($provider->get_name()) . '</h3>';
            if ($row) {
                $html .= '<p>Status: <strong>' . esc_html($provider_enabled ? 'Connected' : 'Connected - provider currently unavailable') . '</strong></p>';
                $html .= '<p>Account: ' . esc_html($row['provider_email'] ?: $row['display_name'] ?: $row['provider_user_id']) . '</p>';
                $html .= '<p>Connected: ' . esc_html($row['created_at']) . '</p>';
                $html .= '<p>Last used: ' . esc_html($row['last_login_at'] ?: 'Not used yet') . '</p>';
                $html .= '<p>Disconnect is planned for a later security pass.</p>';
            } elseif ($provider_enabled) {
                $html .= '<p>Status: <strong>Not connected</strong></p>';
                $html .= APIPlatform_Auth_Buttons::render([
                    'context' => 'link_account',
                    'return_url' => class_exists('APIPlatform_Routes') ? APIPlatform_Routes::connected_accounts_url() : home_url('/auth/accounts'),
                    'show_separator' => false,
                    'provider_ids' => [$provider->get_id()],
                ]);
            } else {
                $html .= '<p>Status: Not available</p>';
            }
            $html .= '</article>';
        }

        return $html . '</div>';
    }

    private function start($provider){
        $provider_id = $provider->get_id();
        $submitted_action = sanitize_key($_GET['auth_action'] ?? $_GET['mode'] ?? '');
        $requested_action = APIPlatform_Auth_State::normalize_action($submitted_action ?: 'login');
        $source_context = $this->normalize_source_context($_GET['source_context'] ?? '');
        $settings = APIPlatform_Auth_Providers::settings($provider_id);
        $redirect_uri = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_callback_url($provider_id) : home_url('/auth/' . $provider_id . '/callback');

        if ($requested_action === 'link_account' && !is_user_logged_in()) {
            $this->debug_auth_event('start', $provider_id, [
                'source_context' => $source_context,
                'submitted_action' => $submitted_action ?: 'missing',
                'normalized_action' => $requested_action,
                'current_user_logged_in' => 'no',
                'state_created' => 'no',
                'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'redirect_uri' => $redirect_uri,
                'branch_name' => 'link_login_required_start',
                'final_error_code' => 'link_login_required',
                'final_http_status' => 403,
            ]);
            $this->safe_error('You must be signed in before connecting this account.', 403, 'link_login_required');
        }

        $state = APIPlatform_Auth_State::create($provider_id, [
            'redirect_to' => isset($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '',
            'action' => $requested_action,
            'user_id' => $requested_action === 'link_account' ? get_current_user_id() : 0,
        ]);

        $url = $provider->get_authorization_url($state);
        if (is_wp_error($url)) {
            $this->debug_auth_event('start', $provider_id, [
                'source_context' => $source_context,
                'submitted_action' => $submitted_action ?: 'missing',
                'normalized_action' => $requested_action,
                'current_user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
                'state_created' => 'yes',
                'stored_state_action' => $state['action'] ?? '',
                'stored_linking_user_id_present' => !empty($state['user_id']) ? 'yes' : 'no',
                'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'redirect_uri' => $redirect_uri,
                'branch_name' => 'authorization_url_failed',
                'final_error_code' => $url->get_error_code(),
                'final_http_status' => 503,
            ]);
            $this->safe_error('Authentication is temporarily unavailable.', 503, 'authorization_url_failed');
        }

        $this->debug_auth_event('start', $provider_id, [
            'source_context' => $source_context,
            'submitted_action' => $submitted_action ?: 'missing',
            'normalized_action' => $requested_action,
            'current_user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
            'state_created' => 'yes',
            'stored_state_action' => $state['action'] ?? '',
            'stored_linking_user_id_present' => !empty($state['user_id']) ? 'yes' : 'no',
            'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
            'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
            'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
            'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
            'redirect_uri' => $redirect_uri,
            'branch_name' => 'redirect_to_provider',
        ]);
        APIPlatform_Auth_Audit::record('external_login_started', ['provider' => $provider_id, 'action' => $state['action']]);
        do_action('apiplatform_auth_login_started', $provider_id, ['action' => $state['action'], 'mode' => $state['mode']]);
        wp_redirect($url);
        exit;
    }

    private function callback($provider){
        $provider_id = $provider->get_id();
        $state = APIPlatform_Auth_State::consume($_GET['state'] ?? '', $provider_id);
        if (is_wp_error($state)) {
            $error_data = (array) $state->get_error_data();
            $state_error_settings = APIPlatform_Auth_Providers::settings($provider_id);
            $this->debug_auth_event('callback', $provider_id, [
                'state_found' => $error_data['state_found'] ?? 'no',
                'state_action' => $error_data['state_action'] ?? '',
                'state_expired' => $error_data['state_expired'] ?? '',
                'state_consumed' => $error_data['state_consumed'] ?? '',
                'current_user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
                'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($state_error_settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($state_error_settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($state_error_settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'branch_name' => 'state_rejected',
                'final_error_code' => $state->get_error_code(),
                'final_http_status' => 400,
            ]);
            $this->safe_error($state->get_error_message(), 400, $state->get_error_code());
        }

        $action = APIPlatform_Auth_State::action_from_state($state);
        $settings = APIPlatform_Auth_Providers::settings($provider_id);
        $this->debug_auth_event('callback', $provider_id, [
            'state_found' => 'yes',
            'state_action' => $action,
            'state_expired' => ((int) ($state['expires_at'] ?? 0) < time()) ? 'yes' : 'no',
            'state_consumed' => 'yes',
            'current_user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
            'stored_linking_user_id_present' => !empty($state['user_id']) ? 'yes' : 'no',
            'callback_branch_selected' => $action,
            'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
            'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
            'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
            'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
            'branch_name' => 'state_loaded',
        ]);

        $identity = $provider->handle_callback(wp_unslash($_GET), $state);
        if (is_wp_error($identity)) {
            APIPlatform_Auth_Audit::record('external_login_failed', ['provider' => $provider_id, 'reason' => $identity->get_error_code()]);
            do_action('apiplatform_auth_failure', $provider_id, $identity->get_error_code());
            $status = $this->status_for_error($identity->get_error_code(), 'provider');
            $this->debug_auth_event('callback', $provider_id, [
                'provider_callback_validation_passed' => 'no',
                'normalized_identity_created' => 'no',
                'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'branch_name' => 'provider_validation_failed',
                'final_error_code' => $identity->get_error_code(),
                'final_http_status' => $status,
            ]);
            $this->safe_error($identity->get_error_message(), $status, $identity->get_error_code());
        }

        $external_identity_exists = APIPlatform_External_Identity_Service::find_by_provider_identity($identity['provider'], $identity['provider_user_id']) ? 'found' : 'not_found';
        $email_conflict_possible = 'no';
        $identity_email = sanitize_email((string) ($identity['email'] ?? ''));
        if ($external_identity_exists === 'not_found' && $identity_email && email_exists($identity_email)) {
            $email_conflict_possible = 'yes';
        }

        $result = APIPlatform_External_Identity_Service::resolve($identity, $settings, $state);
        if (is_wp_error($result)) {
            APIPlatform_Auth_Audit::record('external_login_failed', ['provider' => $provider_id, 'reason' => $result->get_error_code()]);
            do_action('apiplatform_auth_failure', $provider_id, $result->get_error_code());
            $status = $this->status_for_error($result->get_error_code(), 'resolve');
            $this->debug_auth_event('callback', $provider_id, [
                'provider_callback_validation_passed' => 'yes',
                'normalized_identity_created' => 'yes',
                'identity_lookup_result' => $external_identity_exists,
                'email_conflict_detected' => $result->get_error_code() === 'email_conflict' ? 'yes' : $email_conflict_possible,
                'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
                'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
                'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
                'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
                'branch_name' => 'account_resolution_' . $result->get_error_code(),
                'final_error_code' => $result->get_error_code(),
                'final_http_status' => $status,
            ]);
            $this->safe_error($result->get_error_message(), $status, $result->get_error_code());
        }

        $redirect = APIPlatform_Auth_State::safe_redirect($state['redirect_to'] ?? '');
        $this->debug_auth_event('callback', $provider_id, [
            'provider_callback_validation_passed' => 'yes',
            'normalized_identity_created' => 'yes',
            'identity_lookup_result' => $external_identity_exists,
            'email_conflict_detected' => $email_conflict_possible,
            'provider_enabled' => APIPlatform_Auth_Providers::is_enabled($provider_id) ? 'yes' : 'no',
            'account_creation_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_account_creation'] ?? false) ? 'yes' : 'no',
            'linking_enabled' => APIPlatform_Auth_Providers::normalize_bool($settings['allow_linking'] ?? false) ? 'yes' : 'no',
            'require_verified_email' => APIPlatform_Auth_Providers::normalize_bool($settings['require_verified_email'] ?? false) ? 'yes' : 'no',
            'branch_name' => $action === 'link_account' ? 'link_account_success' : 'login_success',
            'final_error_code' => '',
            'final_http_status' => 302,
        ]);

        if ($action === 'link_account') {
            $redirect = add_query_arg('auth_notice', !empty($result['already_connected']) ? 'already_connected' : 'connected', class_exists('APIPlatform_Routes') ? APIPlatform_Routes::connected_accounts_url() : home_url('/auth/accounts'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function provider_buttons(){
        $html = '';
        foreach (APIPlatform_Auth_Providers::enabled() as $provider) {
            $meta = APIPlatform_Auth_Providers::public_metadata($provider);
            $url = add_query_arg([
                'auth_action' => 'login',
                'source_context' => 'wp_login',
                'redirect_to' => sanitize_text_field(wp_unslash($_GET['redirect_to'] ?? '')),
            ], $meta['start_url']);
            $html .= '<p><a class="button button-secondary apiplatform-auth-provider-button apiplatform-auth-provider-' . esc_attr($meta['id']) . '" href="' . esc_url($url) . '">' . esc_html($meta['button_label']) . '</a></p>';
        }
        return $html;
    }

    private function notice_html(){
        $notice = sanitize_key($_GET['auth_notice'] ?? '');
        $messages = [
            'connected' => 'Google connected successfully.',
            'already_connected' => 'This Google account is already connected.',
            'login_success' => 'Google login completed successfully.',
        ];

        if (!$notice || empty($messages[$notice])) {
            return '';
        }

        return '<div class="apiplatform-auth-notice apiplatform-auth-notice--success">' . esc_html($messages[$notice]) . '</div>';
    }

    private function render_accounts_page(){
        if (!is_user_logged_in()) {
            auth_redirect();
        }

        status_header(200);
        nocache_headers();
        $content = $this->connected_accounts();

        if (class_exists('APIPlatform_Frontend_Layout')) {
            echo APIPlatform_Frontend_Layout::render_document('<main class="apiplatform-auth-accounts-page">' . $content . '</main>', [
                'layout_mode' => 'developer-portal',
                'portal_page' => 'accounts',
                'title' => 'Connected Accounts',
                'show_sidebar' => false,
                'size' => 'full',
            ]);
        } else {
            echo $content;
        }
        exit;
    }

    private function route_from_path(){
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $prefix = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::auth_prefix() : 'auth';
        $quoted = preg_quote($prefix, '#');

        if ($path === $prefix . '/accounts') {
            return ['action' => 'accounts', 'provider' => ''];
        }

        if (preg_match('#^' . $quoted . '/([^/]+)/callback$#', $path, $matches)) {
            return ['action' => 'callback', 'provider' => sanitize_key($matches[1])];
        }

        if (preg_match('#^' . $quoted . '/([^/]+)$#', $path, $matches)) {
            return ['action' => 'start', 'provider' => sanitize_key($matches[1])];
        }

        return ['action' => '', 'provider' => ''];
    }

    private function rate_limit($action, $provider){
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'apiplatform_auth_rate_' . md5($action . '|' . $provider . '|' . $ip . '|' . (is_user_logged_in() ? get_current_user_id() : 0));
        $count = absint(get_transient($key));
        if ($count >= 20) {
            return false;
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private function status_for_error($code, $stage = ''){
        $code = sanitize_key($code);

        if (in_array($code, ['missing_code', 'provider_error'], true)) {
            return 400;
        }

        if (in_array($code, ['invalid_id_token', 'invalid_id_token_payload', 'invalid_id_token_signature', 'invalid_issuer', 'invalid_audience', 'invalid_authorized_party', 'expired_id_token', 'invalid_iat', 'invalid_nonce', 'missing_subject', 'missing_id_token', 'email_unverified', 'token_exchange_failed'], true)) {
            return 401;
        }

        if (in_array($code, ['email_conflict', 'identity_owned'], true)) {
            return 409;
        }

        if (in_array($code, ['link_login_required', 'linking_disabled', 'creation_disabled'], true)) {
            return 403;
        }

        return $stage === 'provider' ? 401 : 400;
    }

    private function emit_build_marker($action){
        if (!headers_sent()) {
            header('X-FreedomAPI-Auth-Build: ' . self::BUILD_MARKER);
        }

        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[FreedomAPI auth build] ' . self::BUILD_MARKER . ' action=' . sanitize_key($action) . ' controller=' . __FILE__);
    }

    private function normalize_source_context($context){
        $context = sanitize_key((string) $context);
        if (in_array($context, ['armember', 'armember_login', 'wp_login', 'connected_accounts'], true)) {
            return $context === 'armember_login' ? 'armember' : $context;
        }

        return 'unknown';
    }

    private function debug_auth_event($event, $provider_id, array $data){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $safe = [
            'build' => self::BUILD_MARKER,
            'event' => sanitize_key($event),
            'provider' => sanitize_key($provider_id),
            'source_context' => sanitize_key($data['source_context'] ?? ''),
            'submitted_action' => sanitize_key($data['submitted_action'] ?? ''),
            'normalized_action' => sanitize_key($data['normalized_action'] ?? ''),
            'state_created' => sanitize_key($data['state_created'] ?? ''),
            'stored_state_action' => sanitize_key($data['stored_state_action'] ?? ''),
            'state_record_found' => sanitize_key($data['state_found'] ?? ''),
            'state_action' => sanitize_key($data['state_action'] ?? ''),
            'state_expired' => sanitize_key($data['state_expired'] ?? ''),
            'state_consumed' => sanitize_key($data['state_consumed'] ?? ''),
            'current_user_logged_in' => sanitize_key($data['current_user_logged_in'] ?? ''),
            'stored_linking_user_id_present' => sanitize_key($data['stored_linking_user_id_present'] ?? ''),
            'callback_branch_selected' => sanitize_key($data['callback_branch_selected'] ?? ''),
            'provider_callback_validation_passed' => sanitize_key($data['provider_callback_validation_passed'] ?? ''),
            'normalized_identity_created' => sanitize_key($data['normalized_identity_created'] ?? ''),
            'identity_lookup_result' => sanitize_key($data['identity_lookup_result'] ?? ''),
            'email_conflict_detected' => sanitize_key($data['email_conflict_detected'] ?? ''),
            'provider_enabled' => sanitize_key($data['provider_enabled'] ?? ''),
            'account_creation_enabled' => sanitize_key($data['account_creation_enabled'] ?? ''),
            'linking_enabled' => sanitize_key($data['linking_enabled'] ?? ''),
            'require_verified_email' => sanitize_key($data['require_verified_email'] ?? ''),
            'redirect_uri' => esc_url_raw((string) ($data['redirect_uri'] ?? '')),
            'branch_name' => sanitize_key($data['branch_name'] ?? ''),
            'final_error_code' => sanitize_key($data['final_error_code'] ?? ''),
            'final_http_status' => absint($data['final_http_status'] ?? 0),
        ];

        $parts = [];
        foreach ($safe as $key => $value) {
            if ($value === '' || $value === 0) {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }

        error_log('[FreedomAPI auth diagnostic] ' . implode(' ', $parts));
    }

    private function safe_error($message, $status = 400, $code = ''){
        status_header(absint($status));
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Authentication Error</title></head><body>';
        echo '<main style="max-width:680px;margin:48px auto;font-family:sans-serif;line-height:1.5">';
        echo '<h1>Authentication unavailable</h1><p>' . esc_html($message) . '</p>';
        echo '<p><a href="' . esc_url(wp_login_url()) . '">Return to sign in</a></p>';
        echo '</main></body></html>';
        exit;
    }
}
