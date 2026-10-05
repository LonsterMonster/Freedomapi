<?php
if (!defined('ABSPATH')) exit;

interface APIPlatform_Auth_Provider_Interface {
    public function get_id();
    public function get_name();
    public function get_type();
    public function get_button_label();
    public function get_default_scopes();
    public function supports_pkce();
    public function supports_verified_email();
    public function get_authorization_url(array $context);
    public function handle_callback(array $request, array $context);
    public function validate_settings(array $settings);
    public function get_settings_schema();
}
