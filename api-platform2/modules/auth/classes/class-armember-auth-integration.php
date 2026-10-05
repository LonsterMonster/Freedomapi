<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_ARMember_Auth_Integration {
    private $rendered_hashes = [];

    public function __construct(){
        add_filter('do_shortcode_tag', [$this, 'append_to_login_shortcode'], 20, 4);
        add_shortcode('apiplatform_auth_buttons', [$this, 'auth_buttons_shortcode']);
    }

    public function append_to_login_shortcode($output, $tag, $attr, $m){
        if (!$this->armember_available() || !$this->is_armember_shortcode($tag)) {
            return $output;
        }

        if (!$this->looks_like_login_form($output) || strpos((string) $output, 'data-apiplatform-auth-buttons=') !== false) {
            return $output;
        }

        $hash = md5((string) $tag . '|' . serialize((array) $attr) . '|' . substr((string) $output, 0, 300));
        if (isset($this->rendered_hashes[$hash])) {
            return $output;
        }
        $this->rendered_hashes[$hash] = true;

        $buttons = APIPlatform_Auth_Buttons::render([
            'context' => 'armember_login',
            'return_url' => APIPlatform_Auth_Buttons::current_url(),
            'show_separator' => true,
        ]);

        return $buttons ? $output . $buttons : $output;
    }

    public function auth_buttons_shortcode($atts){
        $atts = shortcode_atts([
            'context' => 'login',
            'separator' => 'yes',
        ], $atts);

        return APIPlatform_Auth_Buttons::render([
            'context' => sanitize_key($atts['context']),
            'return_url' => APIPlatform_Auth_Buttons::current_url(),
            'show_separator' => $atts['separator'] !== 'no',
        ]);
    }

    public static function status_label(){
        return self::detected() ? 'Active' : 'Not detected';
    }

    private function armember_available(){
        return self::detected();
    }

    private static function detected(){
        return shortcode_exists('arm_form') ||
            shortcode_exists('arm_login') ||
            shortcode_exists('arm_member_login') ||
            class_exists('ARM_members') ||
            class_exists('ARM_Form');
    }

    private function is_armember_shortcode($tag){
        $tag = sanitize_key($tag);
        return strpos($tag, 'arm') === 0;
    }

    private function looks_like_login_form($output){
        $output = (string) $output;
        $lower = strtolower($output);

        if (strpos($lower, 'type="password"') === false && strpos($lower, "type='password'") === false) {
            return false;
        }

        if (strpos($lower, 'register') !== false && strpos($lower, 'login') === false) {
            return false;
        }

        foreach (['forgot', 'reset', 'change password', 'checkout', 'profile'] as $blocked) {
            if (strpos($lower, $blocked) !== false && strpos($lower, 'login') === false) {
                return false;
            }
        }

        return strpos($lower, 'login') !== false ||
            strpos($lower, 'user_login') !== false ||
            strpos($lower, 'username') !== false ||
            strpos($lower, 'email') !== false;
    }
}
