<?php
if (!defined('ABSPATH')) exit;


/**
 * Plan-aware, bounded Request History retention service.
 * Plan retention values are owned exclusively by APIPlatform_Membership_Panel.
 */
class APIPlatform_Request_History_Retention {
    const OPTION = 'apiplatform_request_history_retention_days';
    const HOOK = 'apiplatform_request_history_retention_cleanup';
    const DEFAULT_DAYS = 365;
    const BATCH_SIZE = 500;
    const MAX_ROWS_PER_RUN = 1000;

    public static function allowed_days(){
        return [7, 14, 30, 60, 90, 180, 365];
    }

    /** Platform-wide safety ceiling; plan values remain canonical in the membership panel. */
    public static function platform_max_days(){
        $value = absint(get_option(self::OPTION, self::DEFAULT_DAYS));
        return in_array($value, self::allowed_days(), true) ? $value : self::DEFAULT_DAYS;
    }

    public static function retention_days(){
        return self::platform_max_days();
    }

    public static function schedule(){
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(){
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Resolve one API's retention from canonical ownership and plan services.
     * Scheduled/manual deletion uses actual plans by default; simulation can be
     * requested explicitly for administrator diagnostics without forcing deletes.
     */
    /** Resolve the actual/effective plan through canonical API ownership. */
    public static function plan_id_for_api($api_id, $use_simulation = false){
        $api_id = absint($api_id);
        if (!$api_id || !class_exists('APIPlatform_Ownership_Service') || !class_exists('APIPlatform_Membership_Panel')) return '';
        $owner = APIPlatform_Ownership_Service::owner($api_id);
        $owner_type = sanitize_key($owner['owner_type'] ?? '');
        $owner_id = absint($owner['owner_id'] ?? 0);
        if (!$owner_id || !in_array($owner_type, ['personal', 'organization'], true)) return '';
        if ($owner_type === 'organization' && class_exists('APIPlatform_Organization_Plans')) {
            $owner_id = APIPlatform_Organization_Plans::plan_owner_id($owner_id);
        }
        if (!$owner_id) return '';
        return sanitize_key($use_simulation
            ? APIPlatform_Membership_Panel::get_effective_user_plan($owner_id)
            : APIPlatform_Membership_Panel::get_user_plan($owner_id));
    }

    /** Resolve one API's plan retention and apply the platform safety ceiling. */
    public static function effective_retention_days_for_api($api_id, $use_simulation = false){
        static $cache = [];
        $api_id = absint($api_id);
        $cache_key = $api_id . ':' . ($use_simulation ? 'sim' : 'actual');
        if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];
        $platform_max = self::platform_max_days();
        $plan = self::plan_id_for_api($api_id, $use_simulation);
        $plan_days = $plan !== '' && class_exists('APIPlatform_Membership_Panel')
            ? absint(APIPlatform_Membership_Panel::get_plan_limit($plan, 'request_history_days'))
            : 0;
        return $cache[$cache_key] = $plan_days ? min($plan_days, $platform_max) : $platform_max;
    }

    private static function shortest_plan_days(){
        if (!class_exists('APIPlatform_Membership_Panel')) return self::DEFAULT_DAYS;
        $days = [];
        foreach (APIPlatform_Membership_Panel::get_plan_definitions() as $definition) {
            $value = absint($definition['limits']['request_history_days'] ?? 0);
            if ($value) $days[] = $value;
        }
        return $days ? min($days) : self::DEFAULT_DAYS;
    }

    private static function created_at_timestamp($value){
        $value = (string) $value;
        if ($value === '') return 0;
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        return $date instanceof DateTimeImmutable ? $date->getTimestamp() : 0;
    }

    public static function cron_cleanup(){
        return self::cleanup('cron');
    }

    public static function cleanup($source = 'cron'){
        global $wpdb;
        $source = in_array($source, ['manual', 'cron'], true) ? $source : 'cron';
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        if ($debug) error_log('[REQUEST HISTORY RETENTION] cleanup_start source=' . $source);
        $table = $wpdb->prefix . 'apiplatform_logs';
        $now = current_time('timestamp');
        $retention_days = self::shortest_plan_days();
        $candidate_cutoff = wp_date('Y-m-d H:i:s', $now - ($retention_days * DAY_IN_SECONDS));
        if ($debug) error_log('[REQUEST HISTORY RETENTION] cutoff retention_days=' . absint($retention_days) . ' cutoff=' . $candidate_cutoff);
        $deleted = 0;
        $candidate_count = 0;
        $batch_count = 0;
        $cursor_created = null;
        $cursor_id = 0;

        for ($run = 0; $run < (int) (self::MAX_ROWS_PER_RUN / self::BATCH_SIZE); $run++) {
            if ($cursor_created === null) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, api_id, created_at, request_id FROM `{$table}` WHERE created_at IS NOT NULL AND created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d",
                    $candidate_cutoff,
                    self::BATCH_SIZE
                ));
            } else {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, api_id, created_at, request_id FROM `{$table}` WHERE created_at IS NOT NULL AND created_at < %s AND (created_at > %s OR (created_at = %s AND id > %d)) ORDER BY created_at ASC, id ASC LIMIT %d",
                    $candidate_cutoff,
                    $cursor_created,
                    $cursor_created,
                    $cursor_id,
                    self::BATCH_SIZE
                ));
            }
            $rows = is_array($rows) ? $rows : [];
            if (!$rows) break;
            $batch_count++;
            $candidate_count += count($rows);
            if ($debug) error_log('[REQUEST HISTORY RETENTION] batch candidates=' . count($rows) . ' cursor_created_at=' . ($cursor_created === null ? 'start' : $cursor_created) . ' cursor_id=' . absint($cursor_id));
            $last = end($rows);
            $cursor_created = (string) ($last->created_at ?? '');
            $cursor_id = absint($last->id ?? 0);
            $eligible = [];

            foreach ($rows as $row) {
                $created = self::created_at_timestamp($row->created_at ?? '');
                $days = self::effective_retention_days_for_api($row->api_id ?? 0, false);
                $eligible_row = $created && ($created + ($days * DAY_IN_SECONDS)) <= $now;
                if ($eligible_row) {
                    $eligible[] = absint($row->id);
                }
            }

            $eligible = array_values(array_filter($eligible));
            if ($eligible) {
                $placeholders = implode(',', array_fill(0, count($eligible), '%d'));


                $result = $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $eligible));


                if ($result !== false) $deleted += (int) $result;
                else break;
            }
            if (count($rows) < self::BATCH_SIZE) break;
        }

        if ($debug) {
            error_log('[REQUEST HISTORY RETENTION] cleanup_end candidates_processed=' . absint($candidate_count) . ' deleted=' . absint($deleted) . ' batches=' . absint($batch_count));
            error_log('[FreedomAPI retention] cleanup deleted_rows=' . $deleted . ' candidates=' . $candidate_count . ' platform_max_days=' . self::platform_max_days());
        }
        return $deleted;
    }
    public static function handle_manual_purge(){
        $post_check = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        if (!$post_check) wp_die('Unauthorized request.', 'Request History', ['response' => 403]);
        $capability_check = current_user_can('manage_options');
        if (!$capability_check) wp_die('Unauthorized request.', 'Request History', ['response' => 403]);
        $nonce_present = !empty($_POST['apiplatform_retention_nonce']);
        $nonce_check = $nonce_present && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apiplatform_retention_nonce'])), 'apiplatform_request_history_purge');


        if (!$nonce_check) wp_die('Security check failed.', 'Request History', ['response' => 403]);
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[REQUEST HISTORY RETENTION] invoking_cleanup source=manual');
        $deleted = self::cleanup('manual');
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[REQUEST HISTORY RETENTION] manual_cleanup_result deleted_rows=' . absint($deleted));
        $redirect = add_query_arg(['page' => 'apiplatform-settings', 'tab' => 'request-history', 'apiplatform_retention_purged' => absint($deleted)], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }
    public static function handle_admin_actions(){
if (!is_admin() || !current_user_can('manage_options')) return;
        if (empty($_POST['apiplatform_retention_action']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (empty($_POST['apiplatform_retention_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apiplatform_retention_nonce'])), 'apiplatform_retention_action')) return;

        $action = sanitize_key(wp_unslash($_POST['apiplatform_retention_action']));
        if ($action === 'save') {
            $days = absint($_POST['apiplatform_request_history_retention_days'] ?? 0);
            if (in_array($days, self::allowed_days(), true)) {
                update_option(self::OPTION, $days, false);
                self::schedule();
                add_settings_error('apiplatform_retention', 'saved', 'Request History platform maximum saved.', 'updated');
            } else {
                add_settings_error('apiplatform_retention', 'invalid', 'Choose one of the available retention periods.', 'error');
            }
        }
    }

    public static function render_admin_tab(){
        if (!current_user_can('manage_options')) return;
        if (isset($_GET['apiplatform_retention_purged'])) {
            add_settings_error('apiplatform_retention', 'purged', absint($_GET['apiplatform_retention_purged']) . ' currently expired Request History rows were deleted. Repeat the action if more eligible rows remain.', 'updated');
        }
        $max_days = self::platform_max_days();
        $allowed = self::allowed_days();
        $definitions = class_exists('APIPlatform_Membership_Panel') ? APIPlatform_Membership_Panel::get_plan_definitions() : [];
        ?>
        <?php settings_errors('apiplatform_retention'); ?>
        <div class="apiplatform-card">
            <h2>Request History Retention</h2>
            <p>Plan definitions determine each API's Request History retention. This setting limits the maximum retention permitted platform-wide.</p>
            <?php if ($definitions): ?><p><strong>Plan retention policy</strong><br>
                <?php foreach ($definitions as $definition): ?><?php echo esc_html($definition['label'] ?? 'Plan'); ?>: <?php echo esc_html(absint($definition['limits']['request_history_days'] ?? 0) . ' days'); ?><br><?php endforeach; ?>
            </p><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('apiplatform_retention_action', 'apiplatform_retention_nonce'); ?>
                <input type="hidden" name="apiplatform_retention_action" value="save">
                <p><label for="apiplatform-request-history-retention"><strong>Platform Maximum Request History Retention</strong></label><br>
                    <select id="apiplatform-request-history-retention" name="apiplatform_request_history_retention_days">
                        <?php foreach ($allowed as $value): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($max_days, $value); ?>><?php echo esc_html($value . ' days'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="description">Cleanup runs once daily and removes at most <?php echo esc_html(self::MAX_ROWS_PER_RUN); ?> rows per run. WordPress Cron depends on site traffic.</p>
                <?php submit_button('Save Platform Maximum', 'primary', 'submit', false); ?>
            </form>
        </div>
        <div class="apiplatform-card">
            <h2>Manual Cleanup</h2>
            <p>Delete only Request History rows currently expired under their API owner plan and this platform maximum. APIs, usage counters, organizations, applications, and keys are not affected.</p>
            <form id="apiplatform-request-history-purge-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('Delete currently expired Request History rows?');">
                <?php wp_nonce_field('apiplatform_request_history_purge', 'apiplatform_retention_nonce'); ?>
                <input type="hidden" name="action" value="apiplatform_request_history_purge">
                <button type="submit" class="button button-secondary" form="apiplatform-request-history-purge-form">Delete Expired Request History</button>
            </form>
        </div>
        <?php if (class_exists('APIPlatform_Request_History_Retention_Test_Utility')) APIPlatform_Request_History_Retention_Test_Utility::render(); ?>
        <?php
    }
}

add_action('wp_loaded', ['APIPlatform_Request_History_Retention', 'schedule']);
add_action(APIPlatform_Request_History_Retention::HOOK, ['APIPlatform_Request_History_Retention', 'cron_cleanup']);
add_action('admin_init', ['APIPlatform_Request_History_Retention', 'handle_admin_actions']);
add_action('admin_post_apiplatform_request_history_purge', ['APIPlatform_Request_History_Retention', 'handle_manual_purge'], 10);






































