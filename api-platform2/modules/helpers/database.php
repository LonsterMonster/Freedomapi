<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
CANONICAL DATABASE INSTALLER
----------------------------------------
*/
if (!defined('APIPLATFORM_DB_VERSION')) {
    define('APIPLATFORM_DB_VERSION', '1.8.5');
}

function apiplatform_install_database($force = false){

    $installed = get_option('apiplatform_db_version', '0');

    if (!$force && version_compare($installed, APIPLATFORM_DB_VERSION, '>=')) {
        update_option('apiplatform_db_ready', 1);
        return true;
    }

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    global $wpdb;

    $charset      = $wpdb->get_charset_collate();
    $logs         = $wpdb->prefix . 'apiplatform_logs';
    $transactions = $wpdb->prefix . 'apiplatform_transactions';
    $rules        = $wpdb->prefix . 'apiplatform_rules';
    $portal       = $wpdb->prefix . 'apiplatform_portal_apis';
    $lifecycle    = $wpdb->prefix . 'apiplatform_lifecycle_events';
    $apps         = $wpdb->prefix . 'apiplatform_developer_applications';
    $app_keys     = $wpdb->prefix . 'apiplatform_application_keys';
    $app_access   = $wpdb->prefix . 'apiplatform_application_api_access';
    $app_events   = $wpdb->prefix . 'apiplatform_application_events';
    $doc_snapshots = $wpdb->prefix . 'apiplatform_documentation_snapshots';
    $doc_releases  = $wpdb->prefix . 'apiplatform_documentation_releases';
    $doc_events    = $wpdb->prefix . 'apiplatform_documentation_events';
    $external_identities = $wpdb->prefix . 'apiplatform_external_identities';
    $organizations = $wpdb->prefix . 'apiplatform_organizations';
    $organization_members = $wpdb->prefix . 'apiplatform_organization_members';
    $organization_invitations = $wpdb->prefix . 'apiplatform_organization_invitations';
    $organization_api_access = $wpdb->prefix . 'apiplatform_organization_api_access';
    $organization_events = $wpdb->prefix . 'apiplatform_organization_events';

    dbDelta("CREATE TABLE $logs (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned DEFAULT 0,
        api_id bigint(20) unsigned DEFAULT 0,
        endpoint varchar(200) DEFAULT '',
        method varchar(10) DEFAULT '',
        ip varchar(45) DEFAULT '',
        status int(11) DEFAULT 0,
        response_time float DEFAULT 0,
        application_id bigint(20) unsigned DEFAULT NULL,
        application_key_id bigint(20) unsigned DEFAULT NULL,
        access_id bigint(20) unsigned DEFAULT NULL,
        auth_type varchar(40) DEFAULT 'legacy',
        auth_transport varchar(40) DEFAULT '',
        request_headers text,
        query_params text,
        request_body text,
        user_agent varchar(512) DEFAULT '',
        response_headers text,
        response_body text,
        error_code varchar(80) DEFAULT '',
        error_message text,
        response_validation_status varchar(30) DEFAULT '',
        response_validation_mode varchar(20) DEFAULT '',
        response_validation_errors text,
        request_id varchar(80) DEFAULT '',
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY api_id (api_id),
        KEY application_id (application_id),
        KEY application_key_id (application_key_id),
        KEY access_id (access_id),
        KEY auth_type (auth_type),
        KEY auth_transport (auth_transport),
        KEY response_validation_status (response_validation_status),
        KEY response_validation_mode (response_validation_mode),
        KEY request_id (request_id),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $transactions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned DEFAULT 0,
        api_id bigint(20) unsigned DEFAULT 0,
        amount int(11) DEFAULT 0,
        type varchar(50) DEFAULT '',
        description text,
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY api_id (api_id),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $rules (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL DEFAULT '',
        trigger_type varchar(50) DEFAULT '',
        condition_key varchar(100) NOT NULL DEFAULT '',
        condition_value varchar(255) NOT NULL DEFAULT '',
        action_type varchar(100) NOT NULL DEFAULT '',
        action_data longtext NULL,
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY condition_key (condition_key),
        KEY action_type (action_type),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $portal (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        portal_slug varchar(200) NOT NULL DEFAULT '',
        visibility varchar(20) NOT NULL DEFAULT 'private',
        category varchar(100) NOT NULL DEFAULT '',
        updated_at datetime DEFAULT NULL,
        published_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY api_id (api_id),
        UNIQUE KEY portal_slug (portal_slug),
        KEY visibility (visibility),
        KEY category (category),
        KEY updated_at (updated_at)
    ) $charset;");

    dbDelta("CREATE TABLE $lifecycle (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        previous_state varchar(40) NOT NULL DEFAULT '',
        new_state varchar(40) NOT NULL DEFAULT '',
        event_type varchar(80) NOT NULL DEFAULT '',
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY api_id (api_id),
        KEY user_id (user_id),
        KEY new_state (new_state),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $apps (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        name varchar(200) NOT NULL DEFAULT '',
        slug varchar(120) NOT NULL DEFAULT '',
        description text,
        environment varchar(40) NOT NULL DEFAULT 'development',
        status varchar(40) NOT NULL DEFAULT 'active',
        website_url varchar(255) DEFAULT '',
        callback_url varchar(255) DEFAULT '',
        last_used_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        archived_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY owner_user_id (owner_user_id),
        KEY environment (environment),
        KEY status (status),
        KEY last_used_at (last_used_at),
        KEY updated_at (updated_at)
    ) $charset;");

    dbDelta("CREATE TABLE $app_keys (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        application_id bigint(20) unsigned NOT NULL DEFAULT 0,
        owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        key_name varchar(200) NOT NULL DEFAULT '',
        key_prefix varchar(80) NOT NULL DEFAULT '',
        key_hash varchar(255) NOT NULL DEFAULT '',
        masked_key varchar(120) NOT NULL DEFAULT '',
        status varchar(40) NOT NULL DEFAULT 'active',
        last_used_at datetime DEFAULT NULL,
        expires_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        revoked_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY key_prefix (key_prefix),
        KEY application_id (application_id),
        KEY owner_user_id (owner_user_id),
        KEY status (status),
        KEY last_used_at (last_used_at),
        KEY expires_at (expires_at)
    ) $charset;");

    dbDelta("CREATE TABLE $app_access (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        application_id bigint(20) unsigned NOT NULL DEFAULT 0,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        access_status varchar(40) NOT NULL DEFAULT 'requested',
        granted_by_user_id bigint(20) unsigned DEFAULT 0,
        granted_at datetime DEFAULT NULL,
        requested_at datetime DEFAULT NULL,
        revoked_at datetime DEFAULT NULL,
        last_used_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY application_api (application_id, api_id),
        KEY application_id (application_id),
        KEY api_id (api_id),
        KEY access_status (access_status),
        KEY granted_by_user_id (granted_by_user_id),
        KEY last_used_at (last_used_at)
    ) $charset;");

    dbDelta("CREATE TABLE $app_events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        application_id bigint(20) unsigned NOT NULL DEFAULT 0,
        api_id bigint(20) unsigned DEFAULT 0,
        application_key_id bigint(20) unsigned DEFAULT 0,
        actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        event_type varchar(80) NOT NULL DEFAULT '',
        message varchar(255) NOT NULL DEFAULT '',
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY application_id (application_id),
        KEY api_id (api_id),
        KEY application_key_id (application_key_id),
        KEY actor_user_id (actor_user_id),
        KEY event_type (event_type),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $doc_snapshots (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        version_label varchar(80) NOT NULL DEFAULT '',
        snapshot_uid varchar(160) NOT NULL DEFAULT '',
        documentation_state varchar(40) NOT NULL DEFAULT 'draft',
        snapshot_data longtext NULL,
        published_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        published_at datetime DEFAULT NULL,
        archived_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY snapshot_uid (snapshot_uid),
        KEY api_id (api_id),
        KEY version_label (version_label),
        KEY documentation_state (documentation_state),
        KEY published_at (published_at)
    ) $charset;");

    dbDelta("CREATE TABLE $doc_releases (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        version_label varchar(80) NOT NULL DEFAULT '',
        release_title varchar(200) NOT NULL DEFAULT '',
        summary text,
        release_date date DEFAULT NULL,
        release_type varchar(40) NOT NULL DEFAULT 'changed',
        breaking_change tinyint(1) NOT NULL DEFAULT 0,
        migration_notes longtext NULL,
        entries longtext NULL,
        created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY api_id (api_id),
        KEY version_label (version_label),
        KEY release_type (release_type),
        KEY release_date (release_date)
    ) $charset;");

    dbDelta("CREATE TABLE $doc_events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        event_type varchar(80) NOT NULL DEFAULT '',
        version_label varchar(80) NOT NULL DEFAULT '',
        snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
        message varchar(255) NOT NULL DEFAULT '',
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY api_id (api_id),
        KEY actor_user_id (actor_user_id),
        KEY event_type (event_type),
        KEY version_label (version_label),
        KEY snapshot_id (snapshot_id),
        KEY created_at (created_at)
    ) $charset;");

    dbDelta("CREATE TABLE $external_identities (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        provider varchar(80) NOT NULL DEFAULT '',
        provider_type varchar(80) NOT NULL DEFAULT '',
        provider_user_id varchar(191) NOT NULL DEFAULT '',
        provider_email varchar(191) DEFAULT '',
        email_verified tinyint(1) DEFAULT NULL,
        display_name varchar(200) DEFAULT '',
        avatar_url varchar(255) DEFAULT '',
        profile_data longtext NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        last_login_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY provider_identity (provider, provider_user_id),
        KEY user_id (user_id),
        KEY provider (provider),
        KEY provider_email (provider_email),
        KEY last_login_at (last_login_at)
    ) $charset;");

    dbDelta("CREATE TABLE $organizations (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(200) NOT NULL DEFAULT '',
        slug varchar(120) NOT NULL DEFAULT '',
        description text,
        logo_url varchar(255) DEFAULT '',
        website_url varchar(255) DEFAULT '',
        status varchar(40) NOT NULL DEFAULT 'active',
        plan_id varchar(40) NOT NULL DEFAULT 'free',
        plan_status varchar(40) NOT NULL DEFAULT 'active',
        created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        archived_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY created_by_user_id (created_by_user_id),
        KEY status (status),
        KEY plan_id (plan_id),
        KEY plan_status (plan_status),
        KEY updated_at (updated_at)
    ) $charset;");

    dbDelta("CREATE TABLE $organization_members (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        role varchar(40) NOT NULL DEFAULT 'viewer',
        status varchar(40) NOT NULL DEFAULT 'active',
        joined_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        invited_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        suspended_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY organization_user (organization_id, user_id),
        KEY user_id (user_id),
        KEY role (role),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE $organization_invitations (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        email varchar(191) NOT NULL DEFAULT '',
        role varchar(40) NOT NULL DEFAULT 'viewer',
        token_hash varchar(191) NOT NULL DEFAULT '',
        status varchar(40) NOT NULL DEFAULT 'pending',
        invited_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        expires_at datetime DEFAULT NULL,
        accepted_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        accepted_at datetime DEFAULT NULL,
        revoked_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY organization_id (organization_id),
        KEY email (email),
        KEY status (status),
        KEY expires_at (expires_at)
    ) $charset;");

    dbDelta("CREATE TABLE $organization_api_access (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        api_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        access_role varchar(40) NOT NULL DEFAULT '',
        permissions longtext NULL,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY api_user (api_id, user_id),
        KEY organization_id (organization_id),
        KEY user_id (user_id)
    ) $charset;");

    dbDelta("CREATE TABLE $organization_events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        event_type varchar(80) NOT NULL DEFAULT '',
        subject_type varchar(40) NOT NULL DEFAULT '',
        subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
        message varchar(255) NOT NULL DEFAULT '',
        metadata longtext NULL,
        created_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY organization_id (organization_id),
        KEY actor_user_id (actor_user_id),
        KEY event_type (event_type),
        KEY created_at (created_at)
    ) $charset;");

    if (function_exists('apiplatform_migrate_legacy_api_keys')) {
        apiplatform_migrate_legacy_api_keys();
    }
    if (function_exists('apiplatform_cleanup_legacy_plaintext_api_keys')) {
        apiplatform_cleanup_legacy_plaintext_api_keys();
    }

    apiplatform_backfill_personal_api_ownership();
    apiplatform_backfill_organization_plans();

    if (get_option('apiplatform_default_organization_seat_limit', null) === null) {
        update_option('apiplatform_default_organization_seat_limit', 'unlimited', false);
    }
    if (get_option('apiplatform_default_organization_plan', null) === null) {
        update_option('apiplatform_default_organization_plan', 'free', false);
    }

    update_option('apiplatform_db_version', APIPLATFORM_DB_VERSION);
    update_option('apiplatform_db_ready', 1);

    return true;
}

/*
----------------------------------------
PHASE 10C OWNERSHIP BACKFILL
----------------------------------------
*/
function apiplatform_backfill_personal_api_ownership(){
    $migration_version = '1.0.0';
    if (get_option('apiplatform_ownership_migration_version', '0') === $migration_version) {
        return 0;
    }

    $api_ids = get_posts([
        'post_type' => 'user_api',
        'post_status' => 'any',
        'fields' => 'ids',
        'numberposts' => -1,
        'no_found_rows' => true,
    ]);
    $updated = 0;
    $failed = false;

    foreach ($api_ids as $api_id) {
        $has_owner_type = metadata_exists('post', $api_id, 'apiplatform_owner_type');
        $has_owner_id = metadata_exists('post', $api_id, 'apiplatform_owner_id');

        // Complete canonical ownership is already migrated. Partial canonical
        // metadata is only repairable when the existing value matches the
        // unambiguous legacy-personal mapping; conflicting values remain for
        // manual review and keep this migration retryable.
        if ($has_owner_type && $has_owner_id) {
            continue;
        }

        $author_id = absint(get_post_field('post_author', $api_id));
        if (!$author_id) {
            continue;
        }

        if ($has_owner_type && sanitize_key(get_post_meta($api_id, 'apiplatform_owner_type', true)) !== 'personal') {
            $failed = true;
            continue;
        }
        if ($has_owner_id && absint(get_post_meta($api_id, 'apiplatform_owner_id', true)) !== $author_id) {
            $failed = true;
            continue;
        }

        if (!metadata_exists('post', $api_id, 'apiplatform_created_by_user_id')) {
            if (update_post_meta($api_id, 'apiplatform_created_by_user_id', $author_id) === false) {
                $failed = true;
                continue;
            }
        }
        if (!$has_owner_type && update_post_meta($api_id, 'apiplatform_owner_type', 'personal') === false) {
            $failed = true;
            continue;
        }
        if (!$has_owner_id && update_post_meta($api_id, 'apiplatform_owner_id', $author_id) === false) {
            $failed = true;
            continue;
        }
        $updated++;
    }

    if ($failed) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] personal ownership backfill incomplete; completion marker not advanced.');
        }
        return $updated;
    }

    if (update_option('apiplatform_ownership_migration_version', $migration_version, false) === false) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] personal ownership marker write failed; migration remains retryable.');
        }
        return $updated;
    }
    return $updated;
}

/*
----------------------------------------
PHASE 10D ORGANIZATION PLAN BACKFILL
----------------------------------------
*/
function apiplatform_backfill_organization_plans(){
    $migration_version = '1.0.0';
    if (get_option('apiplatform_organization_plan_migration_version', '0') === $migration_version) return 0;

    global $wpdb;
    $table = $wpdb->prefix . 'apiplatform_organizations';
    $default_plan = class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::default_plan_id() : 'free';
    $updated = $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET plan_id = %s WHERE plan_id = '' OR plan_id IS NULL", $default_plan));
    $status_updated = $wpdb->query("UPDATE `{$table}` SET plan_status = 'active' WHERE plan_status = '' OR plan_status IS NULL");
    if ($updated === false || $status_updated === false) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] organization plan backfill incomplete; completion marker not advanced.');
        }
        return max(0, $updated === false ? 0 : (int) $updated) + max(0, $status_updated === false ? 0 : (int) $status_updated);
    }
    if (update_option('apiplatform_organization_plan_migration_version', $migration_version, false) === false) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[API PLATFORM MIGRATION] organization plan marker write failed; migration remains retryable.');
        }
        return max(0, (int) $updated) + max(0, (int) $status_updated);
    }
    return max(0, (int) $updated) + max(0, (int) $status_updated);
}
/*
----------------------------------------
AUTO HEAL / MIGRATION SYSTEM
----------------------------------------
*/
function apiplatform_auto_heal(){

    apiplatform_install_database(false);
}

add_action('init', 'apiplatform_auto_heal', 1);

/*
----------------------------------------
MANUAL HEAL
----------------------------------------
*/
function apiplatform_run_heal(){

    apiplatform_install_database(true);

    return true;
}


