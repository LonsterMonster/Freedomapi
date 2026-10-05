<?php
if (!defined('ABSPATH')) exit;

/** Canonical FreedomAPI application error definitions. FAPI codes are not HTTP statuses. */
class APIPlatform_Error_Codes {
    private static function builtins(){
        return [
            101 => ['key' => 'login_required', 'title' => 'Login Required', 'message' => 'Sign in to continue.', 'http_status' => 401, 'resolution' => 'Sign in and try again.', 'category' => 'authentication'],
            102 => ['key' => 'email_mismatch', 'title' => 'Invitation Email Mismatch', 'message' => 'Sign in with the invited email address to continue.', 'http_status' => 403, 'resolution' => 'Use the account that received the invitation.', 'category' => 'authentication'],
            503 => ['key' => 'organization_not_active', 'title' => 'Organization Unavailable', 'message' => 'This organization is not active.', 'http_status' => 409, 'resolution' => 'Contact an organization owner or administrator.', 'category' => 'organizations'],
            504 => ['key' => 'already_member', 'title' => 'Already a Member', 'message' => 'This person is already a member of the organization.', 'http_status' => 409, 'resolution' => 'Manage the existing membership instead.', 'category' => 'organizations'],
            505 => ['key' => 'duplicate_invitation', 'title' => 'Invitation Already Pending', 'message' => 'A pending invitation already exists for this email address.', 'http_status' => 409, 'resolution' => 'Revoke the pending invitation or wait for it to expire.', 'category' => 'organizations'],
            506 => ['key' => 'invalid_email', 'title' => 'Invalid Email Address', 'message' => 'Enter a valid email address.', 'http_status' => 422, 'resolution' => 'Check the email address and try again.', 'category' => 'organizations'],
            507 => ['key' => 'invitation_expired', 'title' => 'Invitation Expired', 'message' => 'This invitation has expired.', 'http_status' => 410, 'resolution' => 'Ask an organization owner to send a new invitation.', 'category' => 'organizations'],
            508 => ['key' => 'invitation_revoked', 'title' => 'Invitation Revoked', 'message' => 'This invitation has been revoked by the organization.', 'http_status' => 410, 'resolution' => 'Contact the organization owner if you need another invitation.', 'category' => 'organizations'],
            509 => ['key' => 'invitation_accepted', 'title' => 'Invitation Already Accepted', 'message' => 'This invitation has already been accepted.', 'http_status' => 409, 'resolution' => 'Open the organization from your Organizations page.', 'category' => 'organizations'],
            510 => ['key' => 'invalid_invitation', 'title' => 'Invalid Invitation', 'message' => 'This invitation is unavailable.', 'http_status' => 404, 'resolution' => 'Check the invitation link or contact the organization owner.', 'category' => 'organizations'],
            511 => ['key' => 'permission_denied', 'title' => 'Permission Denied', 'message' => 'You do not have permission to perform this action.', 'http_status' => 403, 'resolution' => 'Ask an organization owner for the required access.', 'category' => 'organizations'],
            512 => ['key' => 'invitation_unavailable', 'title' => 'Invitation Unavailable', 'message' => 'This invitation is no longer available.', 'http_status' => 404, 'resolution' => 'Contact the organization owner if you need another invitation.', 'category' => 'organizations'],
            513 => ['key' => 'malformed_authorization', 'title' => 'Malformed Authorization', 'message' => 'The Authorization header must use a valid Bearer credential.', 'http_status' => 401, 'resolution' => 'Send Authorization: Bearer YOUR_API_KEY or use X-API-Key.', 'category' => 'authentication'],
            514 => ['key' => 'conflicting_credentials', 'title' => 'Conflicting Credentials', 'message' => 'Multiple authentication credentials were supplied and do not match.', 'http_status' => 401, 'resolution' => 'Send one credential or use the same credential in each transport.', 'category' => 'authentication'],
            515 => ['key' => 'malformed_api_key', 'title' => 'Malformed API Key', 'message' => 'The API key credential is malformed.', 'http_status' => 401, 'resolution' => 'Send a single API key without whitespace.', 'category' => 'authentication'],
            516 => ['key' => 'response_schema_validation_failed', 'title' => 'Response Contract Validation Failed', 'message' => 'The API returned a response that does not match its declared response contract.', 'http_status' => 502, 'resolution' => 'Review the declared response schema and runtime response.', 'category' => 'validation'],
            603 => ['key' => 'organization_seat_limit_reached', 'title' => 'Not Enough Open Seats', 'message' => 'This organization has reached its available seat limit.', 'http_status' => 409, 'resolution' => 'Remove an existing member or use a plan with more available seats.', 'category' => 'entitlements'],
            604 => ['key' => 'organization_api_limit_reached', 'title' => 'API Limit Reached', 'message' => 'This organization has reached its API limit.', 'http_status' => 409, 'resolution' => 'Remove an existing API or use a plan with a higher API limit.', 'category' => 'entitlements'],
            900 => ['key' => 'platform_error', 'title' => 'Something Went Wrong', 'message' => 'The request could not be completed.', 'http_status' => 500, 'resolution' => 'Please try again. If the issue continues, contact support.', 'category' => 'platform'],
        ];
    }

    public static function all(){
        $builtins = self::builtins();
        $extensions = apply_filters('apiplatform_error_codes', [], $builtins);
        if (!is_array($extensions)) return $builtins;
        $codes = $builtins;
        $keys = array_column($builtins, 'key');
        foreach ($extensions as $code => $definition) {
            $code = absint($code);
            if (!$code || isset($codes[$code]) || !is_array($definition)) continue;
            $key = sanitize_key($definition['key'] ?? '');
            $title = sanitize_text_field($definition['title'] ?? '');
            $message = sanitize_text_field($definition['message'] ?? '');
            $status = absint($definition['http_status'] ?? 500);
            if (!$key || in_array($key, $keys, true) || !$title || !$message || $status < 100 || $status > 599) continue;
            $codes[$code] = ['key' => $key, 'title' => $title, 'message' => $message, 'http_status' => $status, 'resolution' => sanitize_text_field($definition['resolution'] ?? ''), 'category' => sanitize_key($definition['category'] ?? 'platform')];
            $keys[] = $key;
        }
        ksort($codes, SORT_NUMERIC);
        foreach ($codes as $code => $definition) $codes[$code] = ['code' => (int) $code] + $definition;
        return $codes;
    }

    public static function get($code){ $code = absint($code); $all = self::all(); return $all[$code] ?? null; }
    public static function get_by_key($key){ $key = sanitize_key($key); foreach (self::all() as $code => $definition) if ($definition['key'] === $key) return ['code' => $code] + $definition; return null; }
    public static function code_for_key($key){ $definition = self::get_by_key($key); return $definition['code'] ?? 0; }
    public static function exists($code_or_key){ return is_numeric($code_or_key) ? (bool) self::get($code_or_key) : (bool) self::get_by_key($code_or_key); }
    private static function definition($code_or_key){ return is_numeric($code_or_key) ? self::get($code_or_key) : self::get_by_key($code_or_key); }
    public static function title($code_or_key){ $definition = self::definition($code_or_key); return $definition['title'] ?? self::get(900)['title']; }
    public static function message($code_or_key){ $definition = self::definition($code_or_key); return $definition['message'] ?? self::get(900)['message']; }
    public static function http_status($code_or_key){ $definition = self::definition($code_or_key); return absint($definition['http_status'] ?? 500); }
    public static function resolution($code_or_key){ $definition = self::definition($code_or_key); return $definition['resolution'] ?? self::get(900)['resolution']; }
    public static function id($code_or_key){ $definition = self::definition($code_or_key); $code = $definition['code'] ?? (is_numeric($code_or_key) ? absint($code_or_key) : 900); return 'FAPI-' . $code; }
    public static function wp_error($key, array $context = [], $message = null){
        $definition = self::get_by_key($key) ?: self::get_by_key('platform_error');
        $context = array_merge($context, ['fapi_code' => $definition['code'], 'fapi_id' => 'FAPI-' . $definition['code'], 'key' => $definition['key'], 'title' => $definition['title'], 'http_status' => $definition['http_status'], 'resolution' => $definition['resolution']]);
        return new WP_Error($definition['key'], $message === null ? $definition['message'] : $message, $context);
    }
}