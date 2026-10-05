<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Developer_Portal_Publishing {

    public static function save_from_post($api, $user_id){
        if (!$api || $api->post_type !== 'user_api') {
            return ['ok' => false, 'message' => 'API not found.'];
        }

        $can_edit = class_exists('APIPlatform_Ownership_Service')
            ? APIPlatform_Ownership_Service::can($user_id, $api->ID, 'apis.publish')
            : ((int) $api->post_author === (int) $user_id || current_user_can('manage_options'));
        if (!$can_edit) {
            return ['ok' => false, 'message' => 'You cannot edit publication settings for that API.'];
        }

        $current = APIPlatform_Developer_Portal_Query::get_settings($api->ID);
        $settings = self::sanitize_post($api, $current);
        $mode = self::save_mode();
        $validation = self::validate_visibility($api, $settings);

        if (!$validation['valid']) {
            return [
                'ok' => false,
                'message' => 'Developer Portal settings were not saved: ' . implode(', ', $validation['errors']) . '.',
            ];
        }

        if ($mode === 'publish' && class_exists('APIPlatform_Lifecycle_Policy')) {
            $lifecycle = APIPlatform_Lifecycle_Policy::ensure_state($api->ID, $user_id);
            if (!in_array($lifecycle, ['ready', 'public', 'deprecated'], true)) {
                return [
                    'ok' => false,
                    'message' => 'Mark this API Ready before publishing it publicly.',
                ];
            }
        }

        if ($settings['visibility'] === 'public' && empty($settings['published_at'])) {
            $settings['published_at'] = current_time('mysql');
        }

        self::persist($api->ID, $settings);
        APIPlatform_Developer_Portal_Query::sync_registry($api->ID, $settings);
        self::log_visibility_save($api->ID, $_POST['apiplatform_portal_visibility'] ?? '', $settings['visibility']);

        if ($mode === 'publish' && class_exists('APIPlatform_Lifecycle_Policy')) {
            $current_lifecycle = APIPlatform_Lifecycle_Policy::get_state($api->ID);
            $target_lifecycle = $current_lifecycle === 'deprecated' ? 'deprecated' : 'public';
            $transition = APIPlatform_Lifecycle_Policy::transition($api->ID, $target_lifecycle, $user_id);
            if (empty($transition['ok'])) {
                return [
                    'ok' => false,
                    'message' => $transition['message'] ?? 'API could not be published.',
                ];
            }
        }

        return [
            'ok' => true,
            'message' => self::success_message($settings['visibility'], $mode),
        ];
    }

    public static function settings_for_view($api_id){
        $settings = APIPlatform_Developer_Portal_Query::get_settings($api_id);
        $settings['categories'] = APIPlatform_Developer_Portal_Query::categories();
        $settings['preview_url'] = self::preview_url($api_id, $settings['portal_slug']);
        $settings['public_url'] = APIPlatform_Developer_Portal_Query::api_url($settings['portal_slug']);
        $settings['readiness'] = self::readiness(get_post($api_id), $settings);
        $settings['reserved_slugs'] = APIPlatform_Developer_Portal_Query::reserved_portal_slugs();

        return $settings;
    }

    public static function render_settings(array $view_data){
        $api = $view_data['api'] ?? [];
        $portal = $view_data['portal'] ?? [];
        $edit_nonce = $view_data['edit_nonce'] ?? '';

        if (empty($api['id']) || empty($portal)) {
            return '';
        }

        $visibility = $portal['visibility'] ?? 'private';
        $content = '<form method="post" class="apiplatform-portal-publishing">';
        $content .= '<p class="apiplatform-helper-text apiplatform-portal-publishing-intro">Control whether this API appears in the public Developer Portal. These settings reuse the same metadata read by the directory, API detail, docs, and tester routes.</p>';
        $content .= '<input type="hidden" name="apiplatform_edit_api_action" value="save">';
        $content .= '<input type="hidden" name="api_id" value="' . esc_attr(absint($api['id'])) . '">';
        $content .= '<input type="hidden" name="apiplatform_portal_settings_present" value="1">';
        $content .= '<input type="hidden" name="apiplatform_portal_only" value="1">';
        $content .= '<input type="hidden" name="apiplatform_portal_nonce" value="' . esc_attr(wp_create_nonce('apiplatform_portal_settings_' . absint($api['id']))) . '">';
        $content .= '<input type="hidden" name="apiplatform_edit_api_nonce" value="' . esc_attr($edit_nonce) . '">';
        $content .= '<input type="hidden" name="api_name" value="' . esc_attr($api['name'] ?? '') . '">';
        $content .= '<input type="hidden" name="api_slug" value="' . esc_attr($api['slug'] ?? '') . '">';
        $content .= '<textarea name="description" hidden>' . esc_textarea($api['description'] ?? '') . '</textarea>';
        $content .= '<input type="hidden" name="apiplatform_status" value="' . esc_attr($api['status'] ?? 'active') . '">';
        $content .= '<textarea name="response_json" hidden>' . esc_textarea($api['response_json'] ?? '') . '</textarea>';

        $content .= self::field_select('Portal Visibility', 'apiplatform_portal_visibility', $visibility, [
            'private' => 'Private',
            'unlisted' => 'Unlisted',
            'public' => 'Public',
        ]);
        $content .= '<div class="apiplatform-portal-visibility-help">';
        $content .= '<p><strong>Private</strong> keeps the API out of public portal routes.</p>';
        $content .= '<p><strong>Unlisted</strong> allows direct known-link access but excludes the API from directory, search, category, tag, and related API listings.</p>';
        $content .= '<p><strong>Public</strong> publishes the API in the directory and public portal listings after required metadata is complete.</p>';
        $content .= '</div>';
        $content .= self::field_input('Public Title', 'apiplatform_portal_title', $portal['title'] ?? '');
        $content .= self::field_input('Portal Slug', 'apiplatform_portal_slug', $portal['portal_slug'] ?? '');
        $content .= '<p class="apiplatform-portal-field-note">Public URL: ' . esc_html(APIPlatform_Developer_Portal_Query::api_url($portal['portal_slug'] ?? '')) . '</p>';
        $content .= self::field_textarea('Public Summary', 'apiplatform_portal_summary', $portal['summary'] ?? '', 3);
        $content .= self::field_textarea('Public Description', 'apiplatform_portal_description', $portal['description'] ?? '', 7);
        $content .= self::field_select('Category', 'apiplatform_portal_category', $portal['category'] ?? '', array_merge(['' => 'Select category'], $portal['categories'] ?? []));
        $content .= self::field_input('Tags', 'apiplatform_portal_tags', implode(', ', $portal['tags'] ?? []));
        $content .= self::field_input('Logo / Icon Text', 'apiplatform_portal_icon', $portal['icon'] ?? '');
        $content .= self::field_select('Documentation Enabled', 'apiplatform_portal_docs_status', $portal['docs_status'] ?? 'draft', [
            'draft' => 'Disabled / Coming Soon',
            'ready' => 'Enabled',
            'needs-review' => 'Needs Review',
        ]);
        $content .= self::field_select('Authentication', 'apiplatform_portal_authentication_type', $portal['authentication_type'] ?? 'api-key', APIPlatform_Developer_Portal_Query::authentication_options());
        $content .= self::field_input('Public Version', 'apiplatform_portal_version_label', $portal['version_label'] ?? 'v1');
        $content .= self::field_select('Public Status', 'apiplatform_portal_version_status', $portal['version_status'] ?? 'current', [
            'current' => 'Active',
            'beta' => 'Beta',
            'deprecated' => 'Deprecated',
        ]);
        $content .= self::field_input('Release Date', 'apiplatform_portal_release_date', $portal['release_date'] ?? '', 'date');
        $content .= self::field_input('Support URL', 'apiplatform_portal_support_url', $portal['support_url'] ?? '', 'url');
        $content .= self::field_input('Website URL', 'apiplatform_portal_website_url', $portal['website_url'] ?? '', 'url');
        $content .= self::field_input('Terms URL', 'apiplatform_portal_terms_url', $portal['terms_url'] ?? '', 'url');
        $content .= self::field_input('Privacy URL', 'apiplatform_portal_privacy_url', $portal['privacy_url'] ?? '', 'url');
        $content .= self::field_textarea('Deprecation Notice', 'apiplatform_portal_deprecation_notice', $portal['deprecation_notice'] ?? '', 3);
        $content .= self::field_input('Sunset Date', 'apiplatform_portal_sunset_date', $portal['sunset_date'] ?? '', 'date');
        $content .= self::field_textarea('Changelog URL or Summary', 'apiplatform_portal_changelog', $portal['changelog'] ?? '', 3);
        $content .= self::field_input('Public Publisher Name (optional)', 'apiplatform_portal_publisher_name', $portal['publisher_name'] ?? '');

        $content .= '<label class="apiplatform-checkbox-row"><input type="checkbox" name="apiplatform_portal_allow_public_tester" value="1" ' . checked(!empty($portal['allow_public_tester']), true, false) . '> Public tester enabled</label>';
        $content .= '<label class="apiplatform-checkbox-row"><input type="checkbox" name="apiplatform_portal_allow_anonymous_testing" value="1" ' . checked(!empty($portal['allow_anonymous_testing']), true, false) . '> Allow anonymous sandbox testing later</label>';
        $content .= '<label class="apiplatform-checkbox-row"><input type="checkbox" name="apiplatform_portal_confirm_publish" value="1"> I confirm the public listing metadata is safe to publish.</label>';

        $readiness = $portal['readiness'] ?? ['ready' => false, 'missing' => []];
        $content .= '<div class="apiplatform-portal-readiness"><strong>Publication readiness</strong>';
        if (!empty($readiness['ready'])) {
            $content .= '<p>Ready to publish.</p>';
        } else {
            $missing = self::normalize_missing_requirements($readiness['missing'] ?? []);
            if ($missing) {
                $content .= '<p>Missing before public launch:</p><ul class="apiplatform-portal-readiness-list">';
                foreach ($missing as $item) {
                    $content .= '<li><strong>' . esc_html($item['label']) . '</strong><span>' . esc_html($item['message']) . '</span>';
                    if (!empty($item['fix_url'])) {
                        $content .= '<a href="' . esc_url($item['fix_url']) . '">Fix</a>';
                    }
                    $content .= '</li>';
                }
                $content .= '</ul>';
            } else {
                $content .= '<p>Not ready for public launch. Review visibility, lifecycle, and required API metadata.</p>';
            }
        }
        $content .= '</div>';

        $content .= '<div class="apiplatform-form-actions">';
        $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($portal['preview_url'] ?? '#') . '" target="_blank" rel="noopener">Preview Public Page</a>';
        if (!empty($portal['public_url']) && $visibility !== 'private') {
            $content .= '<a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($portal['public_url']) . '" target="_blank" rel="noopener">View Public Page</a>';
        }
        $content .= '<button type="submit" name="apiplatform_portal_save_mode" value="draft" class="apiplatform-button apiplatform-button-secondary">Save Portal Draft</button>';
        $content .= '<button type="submit" name="apiplatform_portal_save_mode" value="publish" class="apiplatform-button apiplatform-button-primary">Publish API</button>';
        $content .= '</div></form>';

        return APIPlatform_Renderer::component('card', [
            'title' => 'Developer Portal Publishing',
            'class' => 'apiplatform-edit-api-card apiplatform-portal-publishing-card',
            'content' => $content,
        ]);
    }

    private static function sanitize_post($api, array $current){
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility(wp_unslash($_POST['apiplatform_portal_visibility'] ?? $current['visibility']))
            : sanitize_key(wp_unslash($_POST['apiplatform_portal_visibility'] ?? $current['visibility']));
        $mode = self::save_mode();

        if (!in_array($visibility, APIPlatform_Developer_Portal_Query::visibility_options(), true)) {
            $visibility = 'private';
        }

        if ($mode === 'publish') {
            $visibility = 'public';
        }

        $settings = $current;
        $settings['visibility'] = $visibility;
        $settings['title'] = sanitize_text_field(wp_unslash($_POST['apiplatform_portal_title'] ?? $current['title']));
        $settings['summary'] = sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_summary'] ?? $current['summary']));
        $settings['description'] = sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_description'] ?? $current['description']));
        $settings['category'] = sanitize_title(wp_unslash($_POST['apiplatform_portal_category'] ?? $current['category']));
        $settings['tags'] = APIPlatform_Developer_Portal_Query::normalize_tags(wp_unslash($_POST['apiplatform_portal_tags'] ?? $current['tags']));
        $settings['tag_slugs'] = APIPlatform_Developer_Portal_Query::tag_slugs($settings['tags']);
        $settings['icon'] = sanitize_text_field(wp_unslash($_POST['apiplatform_portal_icon'] ?? $current['icon']));
        $settings['docs_status'] = sanitize_key(wp_unslash($_POST['apiplatform_portal_docs_status'] ?? $current['docs_status']));
        if (!in_array($settings['docs_status'], ['draft', 'ready', 'needs-review'], true)) {
            $settings['docs_status'] = 'draft';
        }
        $settings['authentication_type'] = APIPlatform_Developer_Portal_Query::normalize_authentication_type(wp_unslash($_POST['apiplatform_portal_authentication_type'] ?? $current['authentication_type']));
        $settings['portal_slug'] = sanitize_title(wp_unslash($_POST['apiplatform_portal_slug'] ?? $current['portal_slug']));
        $settings['support_url'] = self::clean_url($_POST['apiplatform_portal_support_url'] ?? $current['support_url']);
        $settings['website_url'] = self::clean_url($_POST['apiplatform_portal_website_url'] ?? $current['website_url']);
        $settings['terms_url'] = self::clean_url($_POST['apiplatform_portal_terms_url'] ?? $current['terms_url']);
        $settings['privacy_url'] = self::clean_url($_POST['apiplatform_portal_privacy_url'] ?? $current['privacy_url']);
        $settings['deprecation_notice'] = sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_deprecation_notice'] ?? $current['deprecation_notice']));
        $settings['sunset_date'] = self::clean_date($_POST['apiplatform_portal_sunset_date'] ?? $current['sunset_date']);
        $settings['allow_public_tester'] = !empty($_POST['apiplatform_portal_allow_public_tester']);
        $settings['allow_anonymous_testing'] = !empty($_POST['apiplatform_portal_allow_anonymous_testing']);
        $settings['publisher_name'] = sanitize_text_field(wp_unslash($_POST['apiplatform_portal_publisher_name'] ?? $current['publisher_name']));
        $settings['version_label'] = sanitize_text_field(wp_unslash($_POST['apiplatform_portal_version_label'] ?? $current['version_label']));
        $settings['version_status'] = sanitize_key(wp_unslash($_POST['apiplatform_portal_version_status'] ?? $current['version_status']));
        if (!array_key_exists($settings['version_status'], APIPlatform_Developer_Portal_Query::directory_status_options())) {
            $settings['version_status'] = 'current';
        }
        $settings['release_date'] = self::clean_date($_POST['apiplatform_portal_release_date'] ?? $current['release_date']);
        $settings['changelog'] = sanitize_textarea_field(wp_unslash($_POST['apiplatform_portal_changelog'] ?? $current['changelog']));
        $settings['updated_at'] = current_time('mysql');
        $settings['icon'] = $settings['icon'] ?: APIPlatform_Developer_Portal_Query::initials($settings['title']);
        $settings['portal_slug'] = $settings['portal_slug'] ?: sanitize_title($api->post_name);

        return $settings;
    }

    private static function save_mode(){
        $mode = sanitize_key(wp_unslash($_POST['apiplatform_portal_save_mode'] ?? 'draft'));

        return in_array($mode, ['draft', 'publish'], true) ? $mode : 'draft';
    }

    private static function success_message($visibility, $mode){
        if ($mode === 'publish' && $visibility === 'public') {
            return 'Developer Portal settings saved and API is public.';
        }

        if ($visibility === 'unlisted') {
            return 'Developer Portal draft saved and direct unlisted access is enabled.';
        }

        if ($visibility === 'public') {
            return 'Developer Portal settings saved and API remains public.';
        }

        return 'Developer Portal draft saved. Visibility remains private.';
    }

    private static function validate_visibility($api, array $settings){
        $errors = [];
        $visibility = class_exists('APIPlatform_API_Status')
            ? APIPlatform_API_Status::normalize_portal_visibility($settings['visibility'] ?? 'private')
            : ($settings['visibility'] ?? 'private');
        $slug = $settings['portal_slug'] ?? '';

        if (!in_array($visibility, APIPlatform_Developer_Portal_Query::visibility_options(), true)) {
            $errors[] = 'choose Private, Unlisted, or Public visibility';
        }

        if ($visibility === 'private') {
            return [
                'valid' => empty($errors),
                'errors' => $errors,
            ];
        }

        if ($slug === '') {
            $errors[] = 'portal slug is required';
        } elseif (APIPlatform_Developer_Portal_Query::portal_slug_is_reserved($slug)) {
            $errors[] = 'that Developer Portal slug is reserved';
        } elseif (!APIPlatform_Developer_Portal_Query::portal_slug_is_unique($api->ID, $slug)) {
            $errors[] = 'that Developer Portal slug is already in use';
        }

        foreach (self::required_fields($visibility) as $field => $label) {
            if (empty($settings[$field])) {
                $errors[] = $label . ' is required';
            }
        }

        if ($visibility === 'public' && self::save_mode() === 'publish' && empty($_POST['apiplatform_portal_confirm_publish'])) {
            $errors[] = 'confirm the listing is safe to publish';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private static function required_fields($visibility){
        if ($visibility === 'unlisted') {
            return [
                'title' => 'public title',
            ];
        }

        if ($visibility === 'public') {
            return [
                'title' => 'public title',
            ];
        }

        return [];
    }

    private static function persist($api_id, array $settings){
        $keys = APIPlatform_Developer_Portal_Query::meta_keys();

        foreach ($keys as $field => $meta_key) {
            $value = $settings[$field] ?? '';
            update_post_meta($api_id, $meta_key, is_array($value) ? array_values($value) : $value);
        }

        if (class_exists('APIPlatform_Lifecycle_Policy')) {
            APIPlatform_Lifecycle_Policy::invalidate_readiness_cache($api_id, 'portal_settings_saved');
        }
    }

    private static function log_visibility_save($api_id, $submitted, $normalized){
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        global $wpdb;
        $registry_visibility = $wpdb->get_var($wpdb->prepare(
            "SELECT visibility FROM " . APIPlatform_Developer_Portal_Query::table() . " WHERE api_id = %d LIMIT 1",
            absint($api_id)
        ));

        error_log('[FreedomAPI portal visibility save] api_id=' . absint($api_id) . ' submitted_visibility=' . sanitize_key(wp_unslash($submitted)) . ' normalized_visibility=' . sanitize_key($normalized) . ' saved_meta_visibility=' . sanitize_key(get_post_meta($api_id, 'apiplatform_portal_visibility', true)) . ' saved_registry_visibility=' . sanitize_key($registry_visibility));
    }

    private static function readiness($api, array $settings){
        if ($api && class_exists('APIPlatform_Lifecycle_Policy')) {
            return APIPlatform_Lifecycle_Policy::readiness($api->ID);
        }

        return [
            'ready' => false,
            'checks' => [],
            'missing' => [[
                'code' => 'api_unavailable',
                'label' => 'Load the API',
                'message' => 'The API could not be loaded for publication checks.',
                'fix_url' => '',
                'ready' => false,
            ]],
            'missing_labels' => ['Load the API'],
        ];
    }

    private static function normalize_missing_requirements($missing){
        $items = [];

        foreach ((array) $missing as $key => $item) {
            if (is_array($item)) {
                $label = trim((string) ($item['label'] ?? $item['code'] ?? ''));
                $message = trim((string) ($item['message'] ?? 'Complete this requirement before public launch.'));
                if ($label === '') {
                    $label = 'Complete publication requirement';
                }
                $items[] = [
                    'code' => sanitize_key($item['code'] ?? $key),
                    'label' => $label,
                    'message' => $message,
                    'fix_url' => esc_url_raw($item['fix_url'] ?? ''),
                ];
                continue;
            }

            $label = trim((string) $item);
            if ($label !== '') {
                $items[] = [
                    'code' => sanitize_key($key),
                    'label' => $label,
                    'message' => 'Complete this requirement before public launch.',
                    'fix_url' => '',
                ];
            }
        }

        return $items;
    }

    private static function preview_url($api_id, $slug){
        return wp_nonce_url(
            add_query_arg([
                'apiplatform_portal_preview' => absint($api_id),
            ], APIPlatform_Developer_Portal_Query::api_url($slug)),
            'apiplatform_portal_preview_' . absint($api_id)
        );
    }

    private static function clean_url($value){
        $value = esc_url_raw(wp_unslash($value));
        return $value && wp_http_validate_url($value) ? $value : '';
    }

    private static function clean_date($value){
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function field_input($label, $name, $value, $type = 'text'){
        $id = sanitize_html_class($name);
        return '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><input id="' . esc_attr($id) . '" class="apiplatform-input" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></div>';
    }

    private static function field_textarea($label, $name, $value, $rows){
        $id = sanitize_html_class($name);
        return '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><textarea id="' . esc_attr($id) . '" class="apiplatform-input" name="' . esc_attr($name) . '" rows="' . absint($rows) . '">' . esc_textarea($value) . '</textarea></div>';
    }

    private static function field_select($label, $name, $value, array $options){
        $id = sanitize_html_class($name);
        $html = '<div class="apiplatform-form-row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><select id="' . esc_attr($id) . '" class="apiplatform-input" name="' . esc_attr($name) . '">';
        foreach ($options as $option_value => $option_label) {
            $html .= '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
        }
        return $html . '</select></div>';
    }
}
