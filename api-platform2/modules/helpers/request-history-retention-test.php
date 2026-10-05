<?php
if (!defined('ABSPATH')) exit;

/** Development-only multi-plan Request History retention certification utility. */
class APIPlatform_Request_History_Retention_Test_Utility {
    const PREFIX = 'retention_test_';
    const META = 'apiplatform_retention_test_fixtures';
    const API_META = 'apiplatform_retention_test_apis';
    const NONCE = 'apiplatform_retention_test_action';

    public static function enabled(){
        return defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options');
    }

    private static function boundary_ages(){
        return ['free' => [6, 8], 'pro' => [20, 31], 'premium' => [80, 91], 'admin' => [300, 366]];
    }

    private static function canonical_plans(){
        return array_keys(self::boundary_ages());
    }

    private static function posted_api_ids(){
        $raw = $_POST['retention_test_api_ids'] ?? '';
        $values = is_array($raw) ? $raw : preg_split('/[\s,]+/', sanitize_textarea_field(wp_unslash($raw)), -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];
        foreach ((array) $values as $value) {
            $id = absint($value);
            if ($id && !in_array($id, $ids, true)) $ids[] = $id;
        }
        return $ids;
    }
    private static function saved_api_ids(){
        $ids = get_user_meta(get_current_user_id(), self::API_META, true);
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', $ids)))) : [];
    }

    private static function valid_api($api_id){
        $post = $api_id ? get_post($api_id) : null;
        return $post && $post->post_type === 'user_api';
    }

    private static function owner_label($owner){
        $type = sanitize_key($owner['owner_type'] ?? '');
        $id = absint($owner['owner_id'] ?? 0);
        if ($type === 'personal') {
            $user = $id ? get_userdata($id) : false;
            return $user ? $user->display_name . ' (User #' . $id . ')' : 'Deleted user (User #' . $id . ')';
        }
        if ($type === 'organization') {
            $name = get_the_title($id);
            return ($name ?: 'Organization') . ' (Org #' . $id . ')';
        }
        return 'Unknown owner';
    }

    private static function resolve_api($api_id){
        $api_id = absint($api_id);
        $owner = class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::owner($api_id) : [];
        $plan = APIPlatform_Request_History_Retention::plan_id_for_api($api_id, false);
        $effective = APIPlatform_Request_History_Retention::effective_retention_days_for_api($api_id, false);
        $definitions = class_exists('APIPlatform_Membership_Panel') ? APIPlatform_Membership_Panel::get_plan_definitions() : [];
        $plan_days = absint($definitions[$plan]['limits']['request_history_days'] ?? 0);
        $valid = self::valid_api($api_id) && in_array($plan, self::canonical_plans(), true) && $plan_days > 0 && $effective > 0;
        return [
            'api_id' => $api_id,
            'owner' => self::owner_label($owner),
            'plan' => $plan ?: 'unknown',
            'plan_days' => $plan_days,
            'platform_max' => class_exists('APIPlatform_Request_History_Retention') ? APIPlatform_Request_History_Retention::platform_max_days() : 0,
            'effective_days' => absint($effective),
            'valid' => $valid,
        ];
    }

    private static function validate_apis($ids){
        $rows = [];
        foreach ($ids as $api_id) $rows[$api_id] = self::resolve_api($api_id);
        return array_values($rows);
    }

    private static function insert_fixture($api_id, $plan, $age, $effective_days){
        global $wpdb;
        $label = $plan . '_' . absint($age);
        $request_id = self::PREFIX . sanitize_key($label) . 'd_' . strtolower(wp_generate_uuid4());
        $created_at = wp_date('Y-m-d H:i:s', current_time('timestamp') - (absint($age) * DAY_IN_SECONDS));
        $ok = $wpdb->insert($wpdb->prefix . 'apiplatform_logs', [
            'user_id' => 0,
            'api_id' => absint($api_id),
            'endpoint' => 'retention-test',
            'method' => 'GET',
            'ip' => '',
            'status' => 200,
            'response_time' => 0,
            'auth_type' => 'not_attempted',
            'auth_transport' => '',
            'request_id' => $request_id,
            'created_at' => $created_at,
        ]);
        if (!$ok) return null;
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[REQUEST HISTORY RETENTION TEST] fixture_created request_id=' . sanitize_text_field($request_id) . ' row_id=' . absint($wpdb->insert_id) . ' api_id=' . absint($api_id) . ' created_at=' . sanitize_text_field($created_at));
        return [
            'request_id' => $request_id,
            'row_id' => absint($wpdb->insert_id),
            'api_id' => absint($api_id),
            'created_plan' => sanitize_key($plan),
            'created_effective_days' => absint($effective_days),
            'age' => absint($age),
            'expected_action_at_creation' => absint($age) < absint($effective_days) ? 'KEEP' : 'DELETE',
            'created_at' => $created_at,
        ];
    }

    private static function create_rows($validation){
        $fixtures = [];
        $errors = [];
        foreach ($validation as $row) {
            if (!$row['valid']) {
                $errors[] = 'API ' . absint($row['api_id']) . ' validation failed: detected plan ' . ucfirst($row['plan']) . ' / ' . absint($row['effective_days']) . ' days.';
                continue;
            }
            foreach (self::boundary_ages()[$row['plan']] as $age) {
                $fixture = self::insert_fixture($row['api_id'], $row['plan'], $age, $row['effective_days']);
                if ($fixture) $fixtures[] = $fixture;
                else $errors[] = 'Could not insert the ' . $row['plan'] . ' ' . $age . '-day fixture for API ' . absint($row['api_id']) . '.';
            }
        }
        if ($fixtures) update_user_meta(get_current_user_id(), self::META, array_merge(self::fixtures_for_user(), $fixtures));
        return [$fixtures, $errors];
    }

    private static function create_platform_row($api_id){
        if (!self::valid_api($api_id)) return [[], ['Selected API is not a valid user_api.']];
        $resolved = self::resolve_api($api_id);
        if ($resolved['plan'] !== 'premium') return [[], ['Selected API resolves to ' . ucfirst($resolved['plan']) . '; expected Premium for this separate test.']];
        $fixture = self::insert_fixture($api_id, 'premium_max', 61, $resolved['effective_days']);
        return $fixture ? [[$fixture], []] : [[], ['Could not insert the platform-maximum fixture.']];
    }

    public static function handle_actions(){
        if (!self::enabled() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['retention_test_action'])) return;
        if (empty($_POST['retention_test_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['retention_test_nonce'])), self::NONCE)) return;
        $action = sanitize_key(wp_unslash($_POST['retention_test_action']));
        $fixtures = self::fixtures_for_user();
        if ($action === 'validate' || $action === 'create') {
            $ids = self::posted_api_ids();
            if (!$ids) {
                add_settings_error('apiplatform_retention_test', $action === 'create' ? 'create_empty' : 'validate_empty', $action === 'create' ? 'No certification APIs supplied. No test rows were created or deleted.' : 'No certification APIs supplied.', 'error');
                return;
            }
            update_user_meta(get_current_user_id(), self::API_META, $ids);
            $validation = self::validate_apis($ids);
            if ($action === 'create') {
                [$created, $errors] = self::create_rows($validation);

                add_settings_error('apiplatform_retention_test', 'created', count($created) . ' certification rows created.', $created ? 'updated' : 'error');
                foreach ($errors as $error) add_settings_error('apiplatform_retention_test', 'create_error', $error, 'error');
            } else {
                add_settings_error('apiplatform_retention_test', 'validated', 'Certification API validation results are shown below.', 'updated');
            }
        } elseif ($action === 'create_platform') {
            [$created, $errors] = self::create_platform_row(absint($_POST['retention_test_api_premium_max'] ?? 0));
            if ($created) update_user_meta(get_current_user_id(), self::META, array_merge($fixtures, $created));


            add_settings_error('apiplatform_retention_test', 'platform_created', count($created) . ' platform-maximum test row created.', $created ? 'updated' : 'error');
            foreach ($errors as $error) add_settings_error('apiplatform_retention_test', 'platform_error', $error, 'error');
        } elseif ($action === 'verify') {
            if (!$fixtures) {
                add_settings_error('apiplatform_retention_test', 'verify_empty', 'No certification test rows are currently available to verify.', 'notice');
                return;
            }
            add_settings_error('apiplatform_retention_test', 'verified', 'Certification results are shown below.', 'updated');
        } elseif ($action === 'delete_all') {
            global $wpdb;
            $pattern = $wpdb->esc_like(self::PREFIX) . '%';
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}apiplatform_logs` WHERE request_id LIKE %s", $pattern));
            delete_user_meta(get_current_user_id(), self::META);
            add_settings_error('apiplatform_retention_test', 'deleted', absint($deleted) . ' certification test rows deleted.', 'updated');
        }
    }
    private static function fixtures_for_user(){
        $value = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($value)) return [];
        $fixtures = [];
        foreach ($value as $fixture) if (is_array($fixture) && !empty($fixture['request_id'])) $fixtures[] = $fixture;
        return $fixtures;
    }

    private static function rows_by_ids($fixtures){
        global $wpdb;
        $ids = array_values(array_filter(array_map(function($row){ return sanitize_text_field($row['request_id'] ?? ''); }, $fixtures)));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $rows = $wpdb->get_col($wpdb->prepare("SELECT request_id FROM `{$wpdb->prefix}apiplatform_logs` WHERE request_id IN ({$placeholders})", $ids));
        return array_fill_keys(array_map('sanitize_text_field', (array) $rows), true);
    }

    public static function render(){
        if (!self::enabled()) return;
        $fixtures = self::fixtures_for_user();
        $existing = self::rows_by_ids($fixtures);
        $ids = self::saved_api_ids();
        $validation = self::validate_apis($ids);
        $coverage = array_fill_keys(self::canonical_plans(), false);
        foreach ($validation as $row) if ($row['valid']) $coverage[$row['plan']] = true;
        ?>
        <?php settings_errors('apiplatform_retention_test'); ?>
        <div class="apiplatform-card">
            <h2>Retention Certification Matrix</h2>
            <p><strong>Development/testing only.</strong> Enter one or more real API IDs (one per line or separated by commas). Current ownership, membership plan, and effective retention are resolved through production services.</p>
            <form method="post">
                <?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?>
                <input type="hidden" name="retention_test_action" value="validate">
                <p><label for="retention-test-api-ids"><strong>Certification API IDs</strong></label><br><textarea id="retention-test-api-ids" name="retention_test_api_ids" rows="4" cols="30"><?php echo esc_textarea(implode("\n", $ids)); ?></textarea></p>
                <?php submit_button('Validate Certification APIs', 'secondary', 'submit', false); ?>
            </form>
            <p class="description">Clearing the API ID box does not delete certification rows. Use Delete All Certification Test Rows to remove synthetic fixtures.</p>
            <?php if (!$ids): ?><form method="post" style="margin-top:8px"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="create"><?php submit_button('Create All Certification Rows', 'secondary', 'submit', false); ?></form><?php endif; ?>
            <?php if ($ids): ?><table class="widefat"><thead><tr><th>API ID</th><th>Owner</th><th>Detected Plan</th><th>Plan Retention</th><th>Platform Maximum</th><th>Effective Retention</th><th>Validation</th></tr></thead><tbody><?php foreach ($validation as $row): ?><tr><td><?php echo esc_html($row['api_id']); ?></td><td><?php echo esc_html($row['owner']); ?></td><td><?php echo esc_html(ucfirst($row['plan'])); ?></td><td><?php echo esc_html($row['plan_days'] . ' days'); ?></td><td><?php echo esc_html($row['platform_max'] . ' days'); ?></td><td><?php echo esc_html($row['effective_days'] . ' days'); ?></td><td><?php echo $row['valid'] ? 'PASS' : 'FAIL'; ?></td></tr><?php endforeach; ?></tbody></table><p>API validation: <?php $valid_count = count(array_filter($validation, function($row){ return $row['valid']; })); echo esc_html($valid_count . '/' . count($validation)); ?> PASS</p><p>Plan coverage: <?php foreach ($coverage as $plan => $present): echo esc_html(ucfirst($plan) . ': ' . ($present ? 'present' : 'missing') . ' '); endforeach; ?><br><em>Full plan certification requires at least one valid API for each canonical plan.</em></p><form method="post" style="margin-top:8px"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="create"><?php foreach ($ids as $id): ?><input type="hidden" name="retention_test_api_ids[]" value="<?php echo esc_attr($id); ?>"><?php endforeach; ?><?php submit_button('Create All Certification Rows', 'secondary', 'submit', false); ?></form><?php endif; ?>
        </div>
        <?php if (!$fixtures): ?><form method="post" style="margin-top:8px"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="verify"><?php submit_button('Verify All Certification Results', 'secondary', 'submit', false); ?></form><?php endif; ?>
        <?php if ($fixtures): ?><div class="apiplatform-card"><h2>Certification Results</h2><table class="widefat"><thead><tr><th>Request ID</th><th>API ID</th><th>Owner</th><th>Created Under Plan</th><th>Current Plan</th><th>Age</th><th>Plan Retention</th><th>Platform Maximum</th><th>Effective Retention</th><th>Expected Action</th><th>Actual State</th><th>Plan Changed</th><th>Result</th></tr></thead><tbody><?php $results = 0; foreach ($fixtures as $fixture): $current = self::resolve_api($fixture['api_id']); $present = isset($existing[$fixture['request_id']]); $plan_changed = sanitize_key($fixture['created_plan'] ?? '') !== sanitize_key($current['plan']); $boundary = in_array(absint($fixture['age']), self::boundary_ages()[$current['plan']] ?? [], true); $expected = $boundary ? (absint($fixture['age']) < absint($current['effective_days']) ? 'KEEP' : 'DELETE') : 'STALE TEST FIXTURE'; $pass = $boundary && (($expected === 'KEEP' && $present) || ($expected === 'DELETE' && !$present)); if ($pass) $results++; ?><tr><td><code><?php echo esc_html($fixture['request_id']); ?></code></td><td><?php echo esc_html($fixture['api_id']); ?></td><td><?php echo esc_html($current['owner']); ?></td><td><?php echo esc_html(ucfirst($fixture['created_plan'] ?? 'unknown')); ?></td><td><?php echo esc_html(ucfirst($current['plan'])); ?></td><td><?php echo esc_html($fixture['age'] . ' days'); ?></td><td><?php echo esc_html($current['plan_days'] . ' days'); ?></td><td><?php echo esc_html($current['platform_max'] . ' days'); ?></td><td><?php echo esc_html($current['effective_days'] . ' days'); ?></td><td><?php echo esc_html($expected); ?></td><td><?php echo $present ? 'exists' : 'missing'; ?></td><td><?php echo $plan_changed ? 'YES' : 'No'; ?></td><td><?php echo $boundary ? ($pass ? 'PASS' : 'FAIL') : 'STALE TEST FIXTURE — recreate recommended'; ?></td></tr><?php endforeach; ?></tbody></table><p>Certification: <?php echo esc_html($results . '/' . count($fixtures)); ?> PASS</p><form method="post" style="margin-top:8px"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="verify"><?php submit_button('Verify All Certification Results', 'secondary', 'submit', false); ?></form><form method="post" style="margin-top:8px" onsubmit="return window.confirm('Delete only rows whose request ID starts with retention_test_?');"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="delete_all"><?php submit_button('Delete All Certification Test Rows', 'delete', 'submit', false); ?></form></div><?php endif; ?>
        <div class="apiplatform-card"><h2>Platform Maximum Certification</h2><p>Separate diagnostic only: verify effective retention equals min(actual plan retention, platform maximum).</p><form method="post"><?php wp_nonce_field(self::NONCE, 'retention_test_nonce'); ?><input type="hidden" name="retention_test_action" value="create_platform"><p><label>Premium test API ID <input type="number" min="1" name="retention_test_api_premium_max" value="<?php echo esc_attr($ids[0] ?? 0); ?>"></label></p><?php submit_button('Create Platform Maximum Test Row', 'secondary', 'submit', false); ?></form></div>
        <?php
    }
}
add_action('admin_init', ['APIPlatform_Request_History_Retention_Test_Utility', 'handle_actions']);



