<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_API_Status {

    public static function portal_visibilities(){
        return ['private', 'unlisted', 'public'];
    }

    public static function lifecycle_states(){
        return ['draft', 'private', 'testing', 'ready', 'public', 'deprecated', 'archived'];
    }

    public static function runtime_statuses(){
        return ['active', 'inactive', 'archived', 'disabled'];
    }

    public static function normalize_portal_visibility($value){
        $value = sanitize_key(str_replace([' ', '_'], '-', (string) $value));

        if (in_array($value, self::portal_visibilities(), true)) {
            return $value;
        }

        if (in_array($value, ['publish', 'published', 'live', 'listed'], true)) {
            return 'public';
        }

        if (in_array($value, ['direct', 'hidden', 'link-only', 'link_only'], true)) {
            return 'unlisted';
        }

        return 'private';
    }

    public static function normalize_lifecycle($value){
        $value = sanitize_key(str_replace([' ', '_'], '-', (string) $value));

        if (in_array($value, self::lifecycle_states(), true)) {
            return $value;
        }

        if (in_array($value, ['active', 'publish', 'published', 'live', 'listed'], true)) {
            return 'public';
        }

        if (in_array($value, ['disabled', 'inactive'], true)) {
            return 'private';
        }

        return 'private';
    }

    public static function normalize_runtime_status($value){
        $value = sanitize_key(str_replace([' ', '_'], '-', (string) $value));

        if (in_array($value, self::runtime_statuses(), true)) {
            return $value;
        }

        if (in_array($value, ['enabled', 'enable', 'public', 'published', 'live'], true)) {
            return 'active';
        }

        return 'inactive';
    }

    public static function is_publicly_listable($visibility, $lifecycle, $runtime_status = 'active'){
        return self::normalize_portal_visibility($visibility) === 'public'
            && in_array(self::normalize_lifecycle($lifecycle), ['public', 'deprecated'], true)
            && self::normalize_runtime_status($runtime_status) === 'active';
    }
}
