<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Organization_Service {
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_ARCHIVED = 'archived';

    public static function tables(){
        global $wpdb;
        return [
            'organizations' => $wpdb->prefix . 'apiplatform_organizations',
            'members' => $wpdb->prefix . 'apiplatform_organization_members',
            'invitations' => $wpdb->prefix . 'apiplatform_organization_invitations',
            'api_access' => $wpdb->prefix . 'apiplatform_organization_api_access',
            'events' => $wpdb->prefix . 'apiplatform_organization_events',
        ];
    }

    public static function statuses(){
        return [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ARCHIVED];
    }

    public static function create(array $data, $creator_user_id){
        $creator_user_id = absint($creator_user_id);
        if (!$creator_user_id) {
            return new WP_Error('login_required', 'Login required.');
        }

        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('missing_name', 'Organization name is required.');
        }

        $slug = sanitize_title((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = sanitize_title($name);
        }
        $slug = self::unique_slug($slug);
        if (is_wp_error($slug)) {
            return $slug;
        }

        global $wpdb;
        $table = self::tables()['organizations'];
        $now = current_time('mysql');
        $inserted = $wpdb->insert($table, [
            'name' => $name,
            'slug' => $slug,
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'logo_url' => esc_url_raw((string) ($data['logo_url'] ?? '')),
            'website_url' => esc_url_raw((string) ($data['website_url'] ?? '')),
            'status' => self::STATUS_ACTIVE,
            'plan_id' => class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::default_plan_id() : 'free',
            'plan_status' => 'active',
            'created_by_user_id' => $creator_user_id,
            'created_at' => $now,
            'updated_at' => $now,
            'archived_at' => null,
        ]);

        if (!$inserted) {
            return new WP_Error('organization_create_failed', 'Organization could not be created.');
        }

        $organization_id = absint($wpdb->insert_id);
        $member = APIPlatform_Organization_Membership_Service::add_member($organization_id, $creator_user_id, 'owner', $creator_user_id);
        if (is_wp_error($member)) {
            return $member;
        }

        self::record_event($organization_id, $creator_user_id, 'organization_created', 'organization', $organization_id, 'Organization created.', ['slug' => $slug]);
        self::clear_cache($organization_id);

        return self::find($organization_id);
    }

    public static function update($organization_id, array $data, $actor_user_id){
        $organization_id = absint($organization_id);
        $actor_user_id = absint($actor_user_id);
        if (!APIPlatform_Organization_Permissions::can($actor_user_id, $organization_id, 'organization.edit')) {
            return new WP_Error('permission_denied', 'You cannot edit this organization.');
        }

        global $wpdb;
        $update = [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'logo_url' => esc_url_raw((string) ($data['logo_url'] ?? '')),
            'website_url' => esc_url_raw((string) ($data['website_url'] ?? '')),
            'updated_at' => current_time('mysql'),
        ];
        if ($update['name'] === '') {
            return new WP_Error('missing_name', 'Organization name is required.');
        }

        $updated = $wpdb->update(self::tables()['organizations'], $update, ['id' => $organization_id]);
        if ($updated === false) {
            return new WP_Error('organization_update_failed', 'Organization could not be updated.');
        }

        self::record_event($organization_id, $actor_user_id, 'organization_updated', 'organization', $organization_id, 'Organization updated.');
        self::clear_cache($organization_id);
        return self::find($organization_id);
    }

    public static function archive($organization_id, $actor_user_id){
        $organization_id = absint($organization_id);
        if (!APIPlatform_Organization_Permissions::can($actor_user_id, $organization_id, 'organization.archive')) {
            return new WP_Error('permission_denied', 'Only organization owners can archive this organization.');
        }

        global $wpdb;
        $updated = $wpdb->update(self::tables()['organizations'], [
            'status' => self::STATUS_ARCHIVED,
            'archived_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $organization_id]);

        if ($updated === false) {
            return new WP_Error('organization_archive_failed', 'Organization could not be archived.');
        }

        self::record_event($organization_id, $actor_user_id, 'organization_archived', 'organization', $organization_id, 'Organization archived.');
        self::clear_cache($organization_id);
        return true;
    }

    public static function set_status($organization_id, $status, $actor_user_id = 0){
        $organization_id = absint($organization_id);
        $status = sanitize_key($status);
        if (!in_array($status, self::statuses(), true)) {
            return new WP_Error('invalid_status', 'Choose a valid organization status.');
        }

        global $wpdb;
        $updated = $wpdb->update(self::tables()['organizations'], [
            'status' => $status,
            'updated_at' => current_time('mysql'),
            'archived_at' => $status === self::STATUS_ARCHIVED ? current_time('mysql') : null,
        ], ['id' => $organization_id]);

        if ($updated === false) {
            return new WP_Error('organization_status_failed', 'Organization status could not be updated.');
        }

        self::record_event($organization_id, $actor_user_id, 'organization_status_changed', 'organization', $organization_id, 'Organization status changed.', ['status' => $status]);
        self::clear_cache($organization_id);
        return true;
    }

    public static function find($organization_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::tables()['organizations'] . '` WHERE id = %d LIMIT 1', absint($organization_id)), ARRAY_A);
    }

    public static function find_by_slug($slug){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::tables()['organizations'] . '` WHERE slug = %s LIMIT 1', sanitize_title($slug)), ARRAY_A);
    }

    public static function list_for_user($user_id, $include_inactive = false){
        global $wpdb;
        $where = "m.user_id = %d AND m.status = 'active'";
        if (!$include_inactive) {
            $where .= " AND o.status = 'active'";
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT o.*, m.role AS current_user_role, m.status AS membership_status FROM `' . self::tables()['organizations'] . '` o INNER JOIN `' . self::tables()['members'] . "` m ON m.organization_id = o.id WHERE {$where} ORDER BY o.updated_at DESC, o.name ASC",
            absint($user_id)
        ), ARRAY_A);
    }

    public static function admin_list(){
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM `' . self::tables()['organizations'] . '` ORDER BY updated_at DESC, id DESC LIMIT 200', ARRAY_A);
    }

    public static function unique_slug($slug){
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return new WP_Error('missing_slug', 'Organization slug is required.');
        }

        $reserved = ['dashboard', 'developers', 'developer', 'api', 'gateway', 'auth', 'wp-admin', 'wp-json', 'settings', 'billing', 'organizations', 'organization'];
        if (in_array($slug, $reserved, true)) {
            return new WP_Error('reserved_slug', 'That organization slug is reserved.');
        }

        $base = $slug;
        $i = 2;
        while (self::find_by_slug($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    public static function record_event($organization_id, $actor_user_id, $event_type, $subject_type = '', $subject_id = 0, $message = '', array $metadata = []){
        global $wpdb;
        foreach (['api_key', 'authorization', 'cookie', 'nonce', 'password', 'token', 'client_secret', 'access_token', 'refresh_token'] as $secret_key) {
            unset($metadata[$secret_key]);
        }

        return $wpdb->insert(self::tables()['events'], [
            'organization_id' => absint($organization_id),
            'actor_user_id' => absint($actor_user_id),
            'event_type' => sanitize_key($event_type),
            'subject_type' => sanitize_key($subject_type),
            'subject_id' => absint($subject_id),
            'message' => sanitize_text_field((string) $message),
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function events($organization_id, $limit = 30){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT e.*, u.display_name AS actor_name FROM `' . self::tables()['events'] . '` e LEFT JOIN `' . $wpdb->users . '` u ON u.ID = e.actor_user_id WHERE e.organization_id = %d ORDER BY e.created_at DESC, e.id DESC LIMIT %d',
            absint($organization_id),
            max(1, min(100, absint($limit)))
        ), ARRAY_A);
    }

    public static function clear_cache($organization_id = 0){
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('org_' . absint($organization_id), 'apiplatform_organizations');
            wp_cache_delete('user_orgs', 'apiplatform_organizations');
        }
        do_action('apiplatform_organization_cache_cleared', absint($organization_id));
    }
}

class APIPlatform_Organization_Membership_Service {
    public static function roles(){
        return ['owner', 'admin', 'developer', 'analyst', 'support', 'billing', 'viewer'];
    }

    public static function role_label($role){
        $labels = [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'developer' => 'Developer',
            'analyst' => 'Analyst',
            'support' => 'Support',
            'billing' => 'Billing',
            'viewer' => 'Viewer',
        ];
        return $labels[sanitize_key($role)] ?? 'Viewer';
    }

    public static function add_member($organization_id, $user_id, $role = 'viewer', $invited_by_user_id = 0, $seat_reserved = false){
        $organization_id = absint($organization_id);
        $user_id = absint($user_id);
        $role = self::sanitize_role($role);
        if (!$organization_id || !$user_id) {
            return new WP_Error('invalid_member', 'A valid organization and user are required.');
        }

        // A valid pending invitation already reserves this seat; accepting it must
        // convert that reservation to membership without consuming a second seat.
        if (!$seat_reserved) {
            $seat_check = class_exists('APIPlatform_Organization_Entitlements')
                ? APIPlatform_Organization_Entitlements::can_consume($organization_id, 'members', $invited_by_user_id)
                : (APIPlatform_Organization_Seats::can_add_member($organization_id) ? true : APIPlatform_Error_Codes::wp_error('organization_seat_limit_reached'));
            if (is_wp_error($seat_check)) return $seat_check;
        }

        global $wpdb;
        $existing = self::member($organization_id, $user_id, true);
        $now = current_time('mysql');
        if ($existing) {
            $updated = $wpdb->update(APIPlatform_Organization_Service::tables()['members'], [
                'role' => $role,
                'status' => 'active',
                'updated_at' => $now,
                'suspended_at' => null,
            ], ['id' => absint($existing['id'])]);
            if ($updated === false) {
                return new WP_Error('member_update_failed', 'Member could not be updated.');
            }
        } else {
            $inserted = $wpdb->insert(APIPlatform_Organization_Service::tables()['members'], [
                'organization_id' => $organization_id,
                'user_id' => $user_id,
                'role' => $role,
                'status' => 'active',
                'joined_at' => $now,
                'updated_at' => $now,
                'invited_by_user_id' => absint($invited_by_user_id),
                'suspended_at' => null,
            ]);
            if (!$inserted) {
                return new WP_Error('member_add_failed', 'Member could not be added.');
            }
        }

        APIPlatform_Organization_Service::record_event($organization_id, $invited_by_user_id, 'member_added', 'member', $user_id, 'Member added.', ['role' => $role]);
        do_action('apiplatform_organization_member_added', $organization_id, $user_id, $role);
        do_action('apiplatform_organization_seat_usage_changed', $organization_id);
        APIPlatform_Organization_Service::clear_cache($organization_id);
        return self::member($organization_id, $user_id, true);
    }

    public static function member($organization_id, $user_id, $include_inactive = false){
        global $wpdb;
        $where = 'organization_id = %d AND user_id = %d';
        if (!$include_inactive) {
            $where .= " AND status = 'active'";
        }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . APIPlatform_Organization_Service::tables()['members'] . "` WHERE {$where} LIMIT 1", absint($organization_id), absint($user_id)), ARRAY_A);
    }

    public static function list_members($organization_id, $include_removed = false){
        global $wpdb;
        $where = 'm.organization_id = %d';
        if (!$include_removed) {
            $where .= " AND m.status <> 'removed'";
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, u.display_name, u.user_email, inviter.display_name AS invited_by_name FROM `' . APIPlatform_Organization_Service::tables()['members'] . '` m LEFT JOIN `' . $wpdb->users . '` u ON u.ID = m.user_id LEFT JOIN `' . $wpdb->users . "` inviter ON inviter.ID = m.invited_by_user_id WHERE {$where} ORDER BY FIELD(m.role, 'owner','admin','developer','analyst','support','billing','viewer'), m.joined_at ASC",
            absint($organization_id)
        ), ARRAY_A);
    }

    public static function active_owner_count($organization_id){
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `" . APIPlatform_Organization_Service::tables()['members'] . "` WHERE organization_id = %d AND role = 'owner' AND status = 'active'", absint($organization_id)));
    }

    public static function change_role($organization_id, $target_user_id, $role, $actor_user_id){
        $organization_id = absint($organization_id);
        $target_user_id = absint($target_user_id);
        $role = self::sanitize_role($role);
        if (!APIPlatform_Organization_Permissions::can($actor_user_id, $organization_id, 'members.change_role')) {
            return new WP_Error('permission_denied', 'You cannot change member roles.');
        }
        $member = self::member($organization_id, $target_user_id);
        if (!$member) {
            return new WP_Error('member_missing', 'Member not found.');
        }
        if ($member['role'] === 'owner' && $role !== 'owner' && self::active_owner_count($organization_id) <= 1) {
            return new WP_Error('final_owner', 'The final active owner cannot be downgraded.');
        }

        global $wpdb;
        $wpdb->update(APIPlatform_Organization_Service::tables()['members'], ['role' => $role, 'updated_at' => current_time('mysql')], ['id' => absint($member['id'])]);
        APIPlatform_Organization_Service::record_event($organization_id, $actor_user_id, 'member_role_changed', 'member', $target_user_id, 'Member role changed.', ['role' => $role]);
        APIPlatform_Organization_Service::clear_cache($organization_id);
        return true;
    }

    public static function set_status($organization_id, $target_user_id, $status, $actor_user_id){
        $organization_id = absint($organization_id);
        $target_user_id = absint($target_user_id);
        $status = sanitize_key($status);
        if (!in_array($status, ['active', 'suspended', 'removed'], true)) {
            return new WP_Error('invalid_status', 'Choose a valid member status.');
        }
        $permission = $status === 'removed' ? 'members.remove' : 'members.suspend';
        if (!APIPlatform_Organization_Permissions::can($actor_user_id, $organization_id, $permission)) {
            return new WP_Error('permission_denied', 'You cannot update this member.');
        }
        $member = self::member($organization_id, $target_user_id, true);
        if (!$member) {
            return new WP_Error('member_missing', 'Member not found.');
        }
        if ($member['role'] === 'owner' && $status !== 'active' && self::active_owner_count($organization_id) <= 1) {
            return new WP_Error('final_owner', 'The final active owner cannot be removed or suspended.');
        }

        global $wpdb;
        $wpdb->update(APIPlatform_Organization_Service::tables()['members'], [
            'status' => $status,
            'updated_at' => current_time('mysql'),
            'suspended_at' => $status === 'suspended' ? current_time('mysql') : null,
        ], ['id' => absint($member['id'])]);
        $event = $status === 'removed' ? 'member_removed' : ($status === 'suspended' ? 'member_suspended' : 'member_restored');
        APIPlatform_Organization_Service::record_event($organization_id, $actor_user_id, $event, 'member', $target_user_id, str_replace('_', ' ', ucfirst($event)) . '.');
        do_action('apiplatform_organization_member_removed', $organization_id, $target_user_id, $status);
        do_action('apiplatform_organization_seat_usage_changed', $organization_id);
        APIPlatform_Organization_Service::clear_cache($organization_id);
        return true;
    }

    public static function leave($organization_id, $user_id){
        $member = self::member($organization_id, $user_id);
        if (!$member) {
            return new WP_Error('member_missing', 'You are not an active member of this organization.');
        }
        if ($member['role'] === 'owner' && self::active_owner_count($organization_id) <= 1) {
            return new WP_Error('final_owner', 'The final active owner cannot leave.');
        }
        return self::set_status($organization_id, $user_id, 'removed', $user_id);
    }

    private static function sanitize_role($role){
        $role = sanitize_key($role);
        return in_array($role, self::roles(), true) ? $role : 'viewer';
    }
}

class APIPlatform_Organization_Permissions {
    public static function role_permissions(){
        return [
            'owner' => ['*'],
            'admin' => ['organization.view', 'organization.edit', 'members.view', 'members.invite', 'members.change_role', 'members.suspend', 'members.remove', 'apis.view', 'apis.create', 'apis.edit', 'apis.delete', 'apis.publish', 'apis.manage_schema', 'apis.manage_versions', 'apis.manage_runtime', 'apis.manage_docs', 'apis.manage_keys', 'analytics.view', 'logs.view', 'applications.view', 'applications.manage', 'application_access.manage', 'organization_activity.view'],
            'developer' => ['organization.view', 'apis.view', 'apis.create', 'apis.edit', 'apis.manage_schema', 'apis.manage_versions', 'apis.manage_runtime', 'apis.manage_docs', 'apis.manage_keys', 'logs.view'],
            'analyst' => ['organization.view', 'apis.view', 'analytics.view', 'logs.view', 'organization_activity.view'],
            'support' => ['organization.view', 'apis.view', 'logs.view', 'applications.view'],
            'billing' => ['organization.view', 'billing.view', 'billing.manage'],
            'viewer' => ['organization.view', 'apis.view'],
        ];
    }

    public static function can($user_id, $organization_id, $permission){
        $user_id = absint($user_id);
        $organization_id = absint($organization_id);
        $permission = self::normalize_permission($permission);
        if (!$user_id || !$organization_id || $permission === '') {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $organization = APIPlatform_Organization_Service::find($organization_id);
        if (!$organization || $organization['status'] !== APIPlatform_Organization_Service::STATUS_ACTIVE) {
            return false;
        }

        $member = APIPlatform_Organization_Membership_Service::member($organization_id, $user_id);
        if (!$member) {
            return false;
        }

        $matrix = self::role_permissions();
        $permissions = $matrix[$member['role']] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function role_can($role, $permission){
        $matrix = self::role_permissions();
        $permissions = $matrix[sanitize_key($role)] ?? [];
        return in_array('*', $permissions, true) || in_array(self::normalize_permission($permission), $permissions, true);
    }

    private static function normalize_permission($permission){
        $permission = strtolower(str_replace(':', '.', (string) $permission));
        return preg_replace('/[^a-z0-9_.-]/', '', $permission);
    }

    public static function user_api_ids($user_id, $permission = 'apis.view'){
        $user_id = absint($user_id);
        $personal_ids = get_posts([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'author' => $user_id,
            'fields' => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);

        $canonical_personal_ids = get_posts([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'meta_query' => [
                ['key' => APIPlatform_Ownership_Service::META_OWNER_TYPE, 'value' => 'personal'],
                ['key' => APIPlatform_Ownership_Service::META_OWNER_ID, 'value' => $user_id, 'compare' => '='],
            ],
            'fields' => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
        ]);
        $personal_ids = array_merge($personal_ids, $canonical_personal_ids);

        // An author-derived candidate may now be organization-owned. Filter it
        // through canonical ownership so stale post_author never restores access.
        $personal_ids = array_values(array_filter(array_map('absint', $personal_ids), function($api_id) use ($user_id){
            $owner = APIPlatform_Ownership_Service::owner($api_id);
            return ($owner['owner_type'] ?? '') === 'personal' && absint($owner['owner_id'] ?? 0) === $user_id;
        }));

        $org_ids = [];
        foreach (APIPlatform_Organization_Service::list_for_user($user_id) as $org) {
            if (!self::can($user_id, $org['id'], $permission)) {
                continue;
            }
            $ids = get_posts([
                'post_type' => 'user_api',
                'post_status' => ['publish', 'draft', 'pending', 'private'],
                'meta_query' => [
                    ['key' => APIPlatform_Ownership_Service::META_OWNER_TYPE, 'value' => 'organization'],
                    ['key' => APIPlatform_Ownership_Service::META_OWNER_ID, 'value' => absint($org['id']), 'compare' => '='],
                ],
                'fields' => 'ids',
                'numberposts' => -1,
                'no_found_rows' => true,
            ]);
            $org_ids = array_merge($org_ids, $ids);
        }

        return array_values(array_unique(array_map('absint', array_merge($personal_ids, $org_ids))));
    }

    public static function can_capability($user_id, $capability, $context = 0){
        $capability = self::normalize_permission($capability);
        $map = [
            'api.create' => 'apis.create',
            'api.view' => 'apis.view',
            'api.edit' => 'apis.edit',
            'api.delete' => 'apis.delete',
            'api.transfer' => 'apis.transfer',
            'schema.edit' => 'apis.manage_schema',
            'model.edit' => 'apis.manage_schema',
            'version.edit' => 'apis.manage_versions',
            'version.publish' => 'apis.publish',
            'docs.edit' => 'apis.manage_docs',
            'analytics.view' => 'analytics.view',
            'logs.view' => 'logs.view',
            'sdk.generate' => 'apis.manage_docs',
            'publishing.manage' => 'apis.publish',
            'gateway.manage' => 'apis.manage_runtime',
            'keys.manage' => 'apis.manage_keys',
            'application_access.manage' => 'application_access.manage',
        ];
        $permission = $map[$capability] ?? $capability;

        if (is_array($context)) {
            $context_type = sanitize_key($context['type'] ?? $context['context_type'] ?? '');
            $context_id = absint($context['id'] ?? $context['organization_id'] ?? $context['api_id'] ?? 0);
            if ($context_type === 'organization') {
                return self::can($user_id, $context_id, $permission);
            }
            if ($context_type === 'api' && class_exists('APIPlatform_Ownership_Service')) {
                return APIPlatform_Ownership_Service::can($user_id, $context_id, $permission);
            }
        }

        return self::can($user_id, absint($context), $permission);
    }
}

class APIPlatform_Ownership_Service {
    const META_OWNER_TYPE = 'apiplatform_owner_type';
    const META_OWNER_ID = 'apiplatform_owner_id';
    const META_CREATED_BY = 'apiplatform_created_by_user_id';

    public static function owner($api_id){
        $api_id = absint($api_id);
        $post = get_post($api_id);
        if (!$post || $post->post_type !== 'user_api') {
            return ['owner_type' => '', 'owner_id' => 0, 'post_author' => 0];
        }

        $type = sanitize_key(get_post_meta($api_id, self::META_OWNER_TYPE, true));
        $owner_id = absint(get_post_meta($api_id, self::META_OWNER_ID, true));
        if ($type !== 'organization') {
            $type = 'personal';
        }
        if (!$owner_id) {
            $owner_id = $type === 'organization' ? 0 : absint($post->post_author);
        }

        // post_author is only a WordPress compatibility/creator field once an
        // explicit canonical owner exists. Never prefer it to the stored owner.
        $name = $type === 'organization' ? 'Organization' : get_the_author_meta('display_name', $owner_id);
        $slug = $type === 'organization' ? '' : get_the_author_meta('user_nicename', $owner_id);
        if ($type === 'organization' && $owner_id) {
            $organization = APIPlatform_Organization_Service::find($owner_id);
            if ($organization) {
                $name = $organization['name'];
                $slug = $organization['slug'];
            }
        }

        return [
            'owner_type' => $type,
            'type' => $type,
            'owner_id' => $owner_id,
            'id' => $owner_id,
            'owner_name' => $name ?: 'Personal',
            'name' => $name ?: 'Personal',
            'owner_slug' => $slug,
            'slug' => $slug,
            'post_author' => absint($post->post_author),
        ];
    }

    public static function get_owner($api_id){ return self::owner($api_id); }
    public static function get_owner_type($api_id){ $owner = self::owner($api_id); return $owner['owner_type']; }
    public static function get_owner_id($api_id){ $owner = self::owner($api_id); return absint($owner['owner_id']); }
    public static function get_owner_name($api_id){ $owner = self::owner($api_id); return (string) $owner['owner_name']; }
    public static function get_owner_slug($api_id){ $owner = self::owner($api_id); return (string) $owner['owner_slug']; }
    public static function is_personal($api_id){ return self::get_owner_type($api_id) === 'personal'; }
    public static function is_organization_owned($api_id){ return self::get_owner_type($api_id) === 'organization'; }
    public static function can_user_access_api($user_id, $api_id){ return self::can($user_id, $api_id, 'apis.view'); }
    public static function can_user_manage_api($user_id, $api_id){ return self::can($user_id, $api_id, 'apis.edit'); }
    public static function transfer_api($api_id, array $target_owner, $actor_user_id, $confirmation = ''){
        return self::transfer($api_id, $target_owner['type'] ?? $target_owner['owner_type'] ?? 'personal', absint($target_owner['id'] ?? $target_owner['owner_id'] ?? 0), $actor_user_id, $confirmation);
    }

    public static function can_capability($user_id, $capability, $api_id){
        return APIPlatform_Organization_Permissions::can_capability($user_id, $capability, ['type' => 'api', 'id' => absint($api_id)]);
    }

    public static function api_ids_for_owner($owner_type, $owner_id, array $args = []){
        $owner_type = sanitize_key($owner_type) === 'organization' ? 'organization' : 'personal';
        $owner_id = absint($owner_id);
        if (!$owner_id) {
            return [];
        }

        $query = array_merge([
            'post_type' => 'user_api',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'fields' => 'ids',
            'numberposts' => -1,
            'no_found_rows' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ], $args);

        if ($owner_type === 'organization') {
            $query['meta_query'] = [
                ['key' => self::META_OWNER_TYPE, 'value' => 'organization'],
                ['key' => self::META_OWNER_ID, 'value' => $owner_id, 'compare' => '='],
            ];
        } else {
            $query['author'] = $owner_id;
        }

        $ids = array_values(array_map('absint', get_posts($query)));
        if ($owner_type === 'personal') {
            $canonical_query = $query;
            unset($canonical_query['author']);
            $canonical_query['meta_query'] = [
                ['key' => self::META_OWNER_TYPE, 'value' => 'personal'],
                ['key' => self::META_OWNER_ID, 'value' => $owner_id, 'compare' => '='],
            ];
            $ids = array_values(array_unique(array_merge(
                $ids,
                array_map('absint', get_posts($canonical_query))
            )));
            $ids = array_values(array_filter($ids, function($api_id) use ($owner_id){
                $owner = self::owner($api_id);
                return ($owner['owner_type'] ?? '') === 'personal' && absint($owner['owner_id'] ?? 0) === $owner_id;
            }));
        }
        return $ids;
    }

    public static function assign($api_id, $owner_type, $owner_id, $actor_user_id = 0){
        $api_id = absint($api_id);
        $owner_type = sanitize_key($owner_type) === 'organization' ? 'organization' : 'personal';
        $owner_id = absint($owner_id);
        if (!$api_id || !$owner_id) {
            return new WP_Error('invalid_owner', 'A valid owner is required.');
        }
        if ($owner_type === 'organization') {
            $organization = APIPlatform_Organization_Service::find($owner_id);
            if (!$organization || ($organization['status'] ?? '') !== APIPlatform_Organization_Service::STATUS_ACTIVE) {
                return new WP_Error('invalid_owner', 'A valid active organization is required.');
            }
        } elseif (!get_userdata($owner_id)) {
            return new WP_Error('invalid_owner', 'A valid personal owner is required.');
        }

        update_post_meta($api_id, self::META_OWNER_TYPE, $owner_type);
        update_post_meta($api_id, self::META_OWNER_ID, $owner_id);
        if (!get_post_meta($api_id, self::META_CREATED_BY, true)) {
            update_post_meta($api_id, self::META_CREATED_BY, absint($actor_user_id));
        }

        if ($owner_type === 'organization') {
            APIPlatform_Organization_Service::record_event($owner_id, $actor_user_id, 'api_created', 'api', $api_id, 'API assigned to organization.');
        }

        do_action('apiplatform_api_ownership_changed', $api_id, $owner_type, $owner_id, absint($actor_user_id));
        clean_post_cache($api_id);
        return true;
    }

    public static function transfer($api_id, $target_type, $target_id, $actor_user_id, $confirmation = ''){
        $api_id = absint($api_id);
        $actor_user_id = absint($actor_user_id);
        $target_type = sanitize_key($target_type) === 'organization' ? 'organization' : 'personal';
        $target_id = absint($target_id);
        $post = get_post($api_id);
        if (!$post || $post->post_type !== 'user_api') {
            return new WP_Error('invalid_api', 'API not found.');
        }
        if (strtolower(trim((string) $confirmation)) !== 'transfer') {
            return new WP_Error('confirmation_required', 'Type transfer to confirm ownership transfer.');
        }
        if (!self::can($actor_user_id, $api_id, 'apis.transfer')) {
            return new WP_Error('api_transfer_forbidden', 'You cannot transfer this API.');
        }
        $from = self::owner($api_id);
        if ($target_type === 'personal' && $from['owner_type'] === 'organization') {
            $member = APIPlatform_Organization_Membership_Service::member($from['owner_id'], $actor_user_id);
            if (!$member || $member['role'] !== 'owner') {
                return new WP_Error('api_transfer_forbidden', 'Only organization owners can transfer organization APIs to personal ownership.');
            }
        }
        if ($target_type === 'organization' && !APIPlatform_Organization_Permissions::can($actor_user_id, $target_id, 'apis.create')) {
            return new WP_Error('permission_denied', 'You cannot transfer APIs into that organization.');
        }
        if ($target_type === 'organization' && class_exists('APIPlatform_Organization_Entitlements')) {
            $api_check = APIPlatform_Organization_Entitlements::can_consume($target_id, 'apis', $actor_user_id);
            if (is_wp_error($api_check)) return $api_check;
        }
        if ($target_type === 'personal' && $target_id !== $actor_user_id && !user_can($actor_user_id, 'manage_options')) {
            return new WP_Error('permission_denied', 'You cannot transfer this API to another personal account.');
        }

        $result = self::assign($api_id, $target_type, $target_id, $actor_user_id);
        if (is_wp_error($result)) {
            return $result;
        }

        if ($target_type === 'personal') {
            wp_update_post(['ID' => $api_id, 'post_author' => $target_id]);
        }
        if ($from['owner_type'] === 'organization') {
            APIPlatform_Organization_Service::record_event($from['owner_id'], $actor_user_id, 'api_transferred_out', 'api', $api_id, 'API transferred out.');
        }
        if ($target_type === 'organization') {
            APIPlatform_Organization_Service::record_event($target_id, $actor_user_id, 'api_transferred_in', 'api', $api_id, 'API transferred in.');
        }

        return true;
    }

    public static function can($user_id, $api_id, $permission = 'apis.view'){
        $user_id = absint($user_id);
        if (!$user_id || !$api_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $post = get_post($api_id);
        if (!$post || $post->post_type !== 'user_api') {
            return false;
        }
        $owner = self::owner($api_id);
        if ($owner['owner_type'] === 'organization') {
            return APIPlatform_Organization_Permissions::can($user_id, $owner['owner_id'], $permission);
        }
        return absint($owner['owner_id']) === $user_id;
    }
}

class APIPlatform_Organization_Seats {
    const DEFAULT_LIMIT_OPTION = 'apiplatform_default_organization_seat_limit';

    public static function active_member_count($organization_id){
        global $wpdb;
        $policy = APIPlatform_Membership_Panel::seat_policy();
        $statuses = array_values(array_filter(array_map('sanitize_key', (array) $policy['member_statuses'])));
        if (!$statuses) return 0;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $query = "SELECT COUNT(*) FROM `" . APIPlatform_Organization_Service::tables()['members'] . "` WHERE organization_id = %d AND status IN ($placeholders)";
        return (int) $wpdb->get_var($wpdb->prepare($query, array_merge([absint($organization_id)], $statuses)));
    }

    public static function valid_pending_invitation_count($organization_id){
        global $wpdb;
        $policy = APIPlatform_Membership_Panel::seat_policy();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `" . APIPlatform_Organization_Service::tables()['invitations'] . "` WHERE organization_id = %d AND status = %s AND expires_at > %s",
            absint($organization_id),
            sanitize_key($policy['invitation_status']),
            current_time('mysql')
        ));
    }

    /** Canonical seat usage follows APIPlatform_Membership_Panel::seat_policy(). */
    public static function used_count($organization_id){
        return APIPlatform_Membership_Panel::seat_usage(
            self::active_member_count($organization_id),
            self::valid_pending_invitation_count($organization_id)
        );
    }

    public static function pending_invitation_count($organization_id){
        return self::valid_pending_invitation_count($organization_id);
    }
    public static function limit($organization_id){
        if (class_exists('APIPlatform_Organization_Entitlements')) return APIPlatform_Organization_Entitlements::get_limit($organization_id, 'members.max');
        $raw = get_option(self::DEFAULT_LIMIT_OPTION, 'unlimited');
        return $raw === 'unlimited' || $raw === '' ? null : absint($raw);
    }

    public static function can_add_member($organization_id){
        $limit = self::limit($organization_id);
        return $limit === null || self::used_count($organization_id) < $limit;
    }

    public static function label($organization_id){
        $limit = self::limit($organization_id);
        return $limit === null ? 'Unlimited' : (string) $limit;
    }
}

class APIPlatform_Organization_Invitation_Service {
    const TTL = 604800;

    public static function create($organization_id, $email, $role, $inviter_user_id, $message = ''){
        $organization_id = absint($organization_id);
        $inviter_user_id = absint($inviter_user_id);
        if (!APIPlatform_Organization_Permissions::can($inviter_user_id, $organization_id, 'members.invite')) {
            return APIPlatform_Error_Codes::wp_error('permission_denied');
        }
        $seat_check = class_exists('APIPlatform_Organization_Entitlements')
            ? APIPlatform_Organization_Entitlements::can_consume($organization_id, 'members', $inviter_user_id)
            : (APIPlatform_Organization_Seats::can_add_member($organization_id) ? true : APIPlatform_Error_Codes::wp_error('organization_seat_limit_reached'));
        if (is_wp_error($seat_check)) return $seat_check;
        $email = sanitize_email((string) $email);
        if (!$email) {
            return APIPlatform_Error_Codes::wp_error('invalid_email');
        }
        $role = in_array(sanitize_key($role), APIPlatform_Organization_Membership_Service::roles(), true) ? sanitize_key($role) : 'viewer';
        $user = get_user_by('email', $email);
        if ($user && APIPlatform_Organization_Membership_Service::member($organization_id, $user->ID)) {
            return APIPlatform_Error_Codes::wp_error('already_member');
        }

        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM `" . APIPlatform_Organization_Service::tables()['invitations'] . "` WHERE organization_id = %d AND email = %s AND status = 'pending' AND expires_at > %s LIMIT 1", $organization_id, $email, current_time('mysql')), ARRAY_A);
        if ($existing) {
            return APIPlatform_Error_Codes::wp_error('duplicate_invitation');
        }

        $token = self::random_token();
        $token_hash = self::hash_token($token);
        $now = current_time('mysql');
        $expires_at = gmdate('Y-m-d H:i:s', time() + self::TTL);
        $inserted = $wpdb->insert(APIPlatform_Organization_Service::tables()['invitations'], [
            'organization_id' => $organization_id,
            'email' => $email,
            'role' => $role,
            'token_hash' => $token_hash,
            'status' => 'pending',
            'invited_by_user_id' => $inviter_user_id,
            'expires_at' => $expires_at,
            'accepted_by_user_id' => 0,
            'accepted_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$inserted) {
            return new WP_Error('invitation_failed', 'Invitation could not be created.');
        }

        $invitation_id = absint($wpdb->insert_id);
        $organization = APIPlatform_Organization_Service::find($organization_id);
        $url = self::url($token);
        $subject = 'Invitation to join ' . ($organization['name'] ?? 'FreedomAPI organization');
        $body = "You have been invited to join " . ($organization['name'] ?? 'a FreedomAPI organization') . " as " . APIPlatform_Organization_Membership_Service::role_label($role) . ".\n\nAccept or decline the invitation:\n" . $url . "\n\nThis invitation expires in 7 days.";
        $mail_accepted = wp_mail($email, $subject, $body);

        APIPlatform_Organization_Service::record_event($organization_id, $inviter_user_id, 'member_invited', 'invitation', $invitation_id, 'Member invitation created.', ['role' => $role, 'mail_accepted' => $mail_accepted ? 'yes' : 'no']);

        return ['id' => $invitation_id, 'url' => $url, 'mail_accepted' => (bool) $mail_accepted];
    }

    public static function find_by_token($token){
        global $wpdb;
        $hash = self::hash_token($token);
        if ($hash === '') {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . APIPlatform_Organization_Service::tables()['invitations'] . '` WHERE token_hash = %s LIMIT 1', $hash), ARRAY_A);
    }

    public static function pending_for_org($organization_id){
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM `" . APIPlatform_Organization_Service::tables()['invitations'] . "` WHERE organization_id = %d ORDER BY created_at DESC, id DESC LIMIT 100", absint($organization_id)), ARRAY_A);
    }

    public static function accept($token, $user_id){
        $invitation = self::find_by_token($token);
        if (!$invitation) {
            return APIPlatform_Error_Codes::wp_error('invalid_invitation');
        }
        if ($invitation['status'] !== 'pending') {
            $status = sanitize_key($invitation['status']);
            if ($status === 'accepted') return APIPlatform_Error_Codes::wp_error('invitation_accepted');
            if ($status === 'revoked') return APIPlatform_Error_Codes::wp_error('invitation_revoked');
            if ($status === 'expired') return APIPlatform_Error_Codes::wp_error('invitation_expired');
            return APIPlatform_Error_Codes::wp_error('invitation_unavailable');
        }
        if (strtotime((string) $invitation['expires_at']) < time()) {
            self::expire($invitation['id']);
            return APIPlatform_Error_Codes::wp_error('invitation_expired');
        }
        $user = get_user_by('id', absint($user_id));
        if (!$user) {
            return APIPlatform_Error_Codes::wp_error('login_required');
        }
        if (strcasecmp((string) $user->user_email, (string) $invitation['email']) !== 0) {
            return APIPlatform_Error_Codes::wp_error('email_mismatch');
        }
        $member = APIPlatform_Organization_Membership_Service::add_member($invitation['organization_id'], $user_id, $invitation['role'], $invitation['invited_by_user_id'], true);
        if (is_wp_error($member)) {
            return $member;
        }

        global $wpdb;
        $wpdb->update(APIPlatform_Organization_Service::tables()['invitations'], [
            'status' => 'accepted',
            'accepted_by_user_id' => absint($user_id),
            'accepted_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($invitation['id'])]);
        APIPlatform_Organization_Service::record_event($invitation['organization_id'], $user_id, 'invitation_accepted', 'invitation', $invitation['id'], 'Invitation accepted.');
        return APIPlatform_Organization_Service::find($invitation['organization_id']);
    }

    public static function decline($token, $user_id = 0){
        $invitation = self::find_by_token($token);
        if (!$invitation || $invitation['status'] !== 'pending') {
            return APIPlatform_Error_Codes::wp_error('invalid_invitation');
        }
        global $wpdb;
        $wpdb->update(APIPlatform_Organization_Service::tables()['invitations'], ['status' => 'declined', 'updated_at' => current_time('mysql')], ['id' => absint($invitation['id'])]);
        APIPlatform_Organization_Service::record_event($invitation['organization_id'], absint($user_id), 'invitation_declined', 'invitation', $invitation['id'], 'Invitation declined.');
        return true;
    }

    public static function revoke($invitation_id, $actor_user_id){
        global $wpdb;
        $invitation = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . APIPlatform_Organization_Service::tables()['invitations'] . '` WHERE id = %d LIMIT 1', absint($invitation_id)), ARRAY_A);
        if (!$invitation) {
            return new WP_Error('invitation_missing', 'Invitation not found.');
        }
        if (!APIPlatform_Organization_Permissions::can($actor_user_id, $invitation['organization_id'], 'members.invite')) {
            return new WP_Error('permission_denied', 'You cannot revoke invitations.');
        }
        $wpdb->update(APIPlatform_Organization_Service::tables()['invitations'], ['status' => 'revoked', 'revoked_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['id' => absint($invitation_id)]);
        APIPlatform_Organization_Service::record_event($invitation['organization_id'], $actor_user_id, 'invitation_revoked', 'invitation', $invitation_id, 'Invitation revoked.');
        return true;
    }

    public static function expire($invitation_id){
        global $wpdb;
        return $wpdb->update(APIPlatform_Organization_Service::tables()['invitations'], ['status' => 'expired', 'updated_at' => current_time('mysql')], ['id' => absint($invitation_id)]);
    }

    public static function url($token){
        $base = class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard');
        return add_query_arg(['apipage' => 'organization-invite', 'token' => rawurlencode((string) $token)], $base);
    }

    private static function random_token(){
        if (function_exists('random_bytes')) {
            return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        return wp_generate_password(48, false, false);
    }

    private static function hash_token($token){
        $token = trim((string) $token);
        return $token === '' ? '' : hash('sha256', $token);
    }
}

function apiplatform_api_can($user_id, $api_id, $permission = 'apis.view'){
    return class_exists('APIPlatform_Ownership_Service') && APIPlatform_Ownership_Service::can($user_id, $api_id, $permission);
}

function apiplatform_api_owner($api_id){
    return class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($api_id) : ['owner_type' => 'personal', 'owner_id' => (int) get_post_field('post_author', $api_id)];
}
