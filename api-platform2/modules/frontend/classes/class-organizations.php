<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Organizations {
    public function __construct(){
        add_shortcode('api_organizations_page', [$this, 'render_organizations']);
        add_shortcode('api_organization_page', [$this, 'render_organization']);
        add_shortcode('api_organization_invite_page', [$this, 'render_invite']);
        add_action('template_redirect', [$this, 'maybe_handle_action']);
    }

    public function maybe_handle_action(){
        if (empty($_POST['apiplatform_org_action'])) return;
        if (!is_user_logged_in()) wp_die('Login required.');

        $user_id = get_current_user_id();
        $action = sanitize_key(wp_unslash($_POST['apiplatform_org_action']));
        $org_id = absint($_POST['organization_id'] ?? 0);
        $nonce_action = $org_id ? 'apiplatform_org_action_' . $org_id : 'apiplatform_org_action';
        $nonce = sanitize_text_field(wp_unslash($_POST['apiplatform_org_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, $nonce_action)) wp_die('Security check failed.');

        $message = 'Organization updated.';
        $type = 'success';
        $redirect = $this->page_url('organizations');
        $notice_metadata = [];
        $notice_organization_id = 0;

        if ($action === 'create') {
            $result = APIPlatform_Organization_Service::create([
                'name' => wp_unslash($_POST['organization_name'] ?? ''),
                'slug' => wp_unslash($_POST['organization_slug'] ?? ''),
                'description' => wp_unslash($_POST['organization_description'] ?? ''),
                'website_url' => wp_unslash($_POST['organization_website_url'] ?? ''),
                'logo_url' => wp_unslash($_POST['organization_logo_url'] ?? ''),
            ], $user_id);
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $type = 'error';
            } else {
                update_user_meta($user_id, 'apiplatform_selected_owner_type', 'organization');
                update_user_meta($user_id, 'apiplatform_selected_owner_id', absint($result['id']));
                $message = 'Organization created.';
                $redirect = $this->organization_url($result['slug']);
            }
        } elseif ($action === 'select-context') {
            if (isset($_POST['owner_context'])) {
                $owner_context = explode(':', sanitize_text_field(wp_unslash($_POST['owner_context'])), 2);
                $_POST['owner_type'] = $owner_context[0] ?? 'personal';
                $_POST['owner_id'] = absint($owner_context[1] ?? 0);
            }
            $owner_type = sanitize_key(wp_unslash($_POST['owner_type'] ?? 'personal'));
            $owner_id = absint($_POST['owner_id'] ?? 0);
            if ($owner_type === 'organization' && !APIPlatform_Organization_Permissions::can($user_id, $owner_id, 'organization.view')) {
                $message = 'You cannot access that organization.';
                $type = 'error';
            } else {
                update_user_meta($user_id, 'apiplatform_selected_owner_type', $owner_type === 'organization' ? 'organization' : 'personal');
                update_user_meta($user_id, 'apiplatform_selected_owner_id', $owner_type === 'organization' ? $owner_id : $user_id);
                $message = 'Workspace context updated.';
            }
        } elseif ($action === 'update' && $org_id) {
            $result = APIPlatform_Organization_Service::update($org_id, [
                'name' => wp_unslash($_POST['organization_name'] ?? ''),
                'description' => wp_unslash($_POST['organization_description'] ?? ''),
                'website_url' => wp_unslash($_POST['organization_website_url'] ?? ''),
                'logo_url' => wp_unslash($_POST['organization_logo_url'] ?? ''),
            ], $user_id);
            [$message, $type] = $this->result_notice($result, 'Organization updated.');
            $redirect = $this->organization_url_by_id($org_id, 'settings');
        } elseif ($action === 'invite' && $org_id) {
            $result = APIPlatform_Organization_Invitation_Service::create($org_id, wp_unslash($_POST['email'] ?? ''), wp_unslash($_POST['role'] ?? 'viewer'), $user_id, wp_unslash($_POST['message'] ?? ''));
            if (is_wp_error($result)) {
                [$message, $type] = $this->entitlement_result_notice($result, $org_id);
                $notice_metadata = $this->notice_metadata($result);
                $notice_organization_id = $org_id;
            } else {
                $message = 'Invitation created. Email was accepted for sending: ' . ($result['mail_accepted'] ? 'yes' : 'no') . '. Invitation URL: ' . esc_url($result['url']);
            }
            $redirect = $this->organization_url_by_id($org_id, 'invitations');
        } elseif ($action === 'revoke-invitation' && $org_id) {
            $result = APIPlatform_Organization_Invitation_Service::revoke(absint($_POST['invitation_id'] ?? 0), $user_id);
            [$message, $type] = $this->result_notice($result, 'Invitation revoked.');
            $redirect = $this->organization_url_by_id($org_id, 'invitations');
        } elseif ($action === 'change-role' && $org_id) {
            $result = APIPlatform_Organization_Membership_Service::change_role($org_id, absint($_POST['target_user_id'] ?? 0), wp_unslash($_POST['role'] ?? 'viewer'), $user_id);
            [$message, $type] = $this->result_notice($result, 'Role changed.');
            $redirect = $this->organization_url_by_id($org_id, 'members');
        } elseif (in_array($action, ['suspend-member', 'restore-member', 'remove-member'], true) && $org_id) {
            $status = $action === 'restore-member' ? 'active' : ($action === 'remove-member' ? 'removed' : 'suspended');
            $result = APIPlatform_Organization_Membership_Service::set_status($org_id, absint($_POST['target_user_id'] ?? 0), $status, $user_id);
            [$message, $type] = $this->result_notice($result, 'Member updated.');
            $redirect = $this->organization_url_by_id($org_id, 'members');
        } elseif ($action === 'leave' && $org_id) {
            $result = APIPlatform_Organization_Membership_Service::leave($org_id, $user_id);
            [$message, $type] = $this->result_notice($result, 'You left the organization.');
        } elseif ($action === 'archive' && $org_id) {
            $result = APIPlatform_Organization_Service::archive($org_id, $user_id);
            [$message, $type] = $this->result_notice($result, 'Organization archived.');
            $redirect = $this->organization_url_by_id($org_id, 'settings');
        } elseif ($action === 'transfer-api') {
            $api_id = absint($_POST['api_id'] ?? 0);
            if (isset($_POST['target_owner_context'])) {
                $target_context = explode(':', sanitize_text_field(wp_unslash($_POST['target_owner_context'])), 2);
                $_POST['target_owner_type'] = $target_context[0] ?? 'personal';
                $_POST['target_owner_id'] = absint($target_context[1] ?? 0);
            }
            $result = APIPlatform_Ownership_Service::transfer($api_id, wp_unslash($_POST['target_owner_type'] ?? 'personal'), absint($_POST['target_owner_id'] ?? 0), $user_id, wp_unslash($_POST['confirmation'] ?? ''));
            [$message, $type] = $this->result_notice($result, 'API ownership transferred.');
            $redirect = add_query_arg(['apipage' => 'api', 'api_id' => $api_id, 'section' => 'settings'], class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'));
        } elseif (in_array($action, ['accept-invite', 'decline-invite'], true)) {
            $token = sanitize_text_field(wp_unslash($_POST['invite_token'] ?? ''));
            $result = $action === 'accept-invite'
                ? APIPlatform_Organization_Invitation_Service::accept($token, $user_id)
                : APIPlatform_Organization_Invitation_Service::decline($token, $user_id);
            if (is_wp_error($result)) {
                [$message, $type] = $this->entitlement_result_notice($result, 0, $action === 'accept-invite');
                $redirect = $this->page_url('organization-invite', ['token' => rawurlencode($token)]);
            } else {
                $message = $action === 'accept-invite' ? 'Invitation accepted.' : 'Invitation declined.';
                $redirect = is_array($result) && !empty($result['slug']) ? $this->organization_url($result['slug']) : $this->page_url('organizations');
            }
        } else {
            $message = 'Unknown organization action.';
            $type = 'error';
        }

        set_transient($this->notice_key($user_id, $notice_organization_id), array_merge(['type' => $type, 'message' => wp_kses_post($message)], $notice_metadata), 60);
        wp_safe_redirect($redirect);
        exit;
    }

    public function render_organizations(){
        if (!is_user_logged_in()) return $this->alert('error', 'Login required.');
        $this->enqueue();
        $user_id = get_current_user_id();
        $organizations = APIPlatform_Organization_Service::list_for_user($user_id, true);
        $content = '<div class="apiplatform-publisher-page apiplatform-organizations-page apiplatform-org-workspace">';
        $content .= $this->notice();
        $content .= class_exists('APIPlatform_Plan_Simulation') ? APIPlatform_Plan_Simulation::publisher_notice() : '';
        $content .= '<div class="apiplatform-publisher-page-head apiplatform-org-header"><div><p class="apiplatform-publisher-kicker">Organizations</p><h1>Workspaces and teams</h1><p>Create organizations, manage members, and prepare shared API ownership.</p></div></div>';
        $content .= $this->context_switcher($user_id, $organizations);
        $content .= '<div class="apiplatform-publisher-dashboard-grid apiplatform-org-grid">';
        $content .= $this->panel('Create Organization', $this->create_form(), 'apiplatform-org-card');
        $content .= $this->panel('Your Organizations', $this->organization_cards($organizations));
        $content .= '</div></div>';
        return $this->section($content);
    }

    public function render_organization(){
        if (!is_user_logged_in()) return $this->alert('error', 'Login required.');
        $this->enqueue();
        $user_id = get_current_user_id();
        $organization = $this->current_organization();
        if (!$organization || !APIPlatform_Organization_Permissions::can($user_id, $organization['id'], 'organization.view')) {
            return $this->section($this->alert('error', 'You cannot access that organization.'));
        }

        $view = sanitize_key($_GET['org_view'] ?? 'home');
        $members = APIPlatform_Organization_Membership_Service::list_members($organization['id']);
        $current_member = APIPlatform_Organization_Membership_Service::member($organization['id'], $user_id);
        $api_ids = $this->organization_api_ids($organization['id']);
        $plan = class_exists('APIPlatform_Organization_Entitlements') ? APIPlatform_Organization_Entitlements::get_plan($organization['id']) : ['name' => 'Free', 'status' => 'active'];
        $member_usage = class_exists('APIPlatform_Organization_Entitlements') ? APIPlatform_Organization_Entitlements::status($organization['id'], 'members') : ['usage' => APIPlatform_Organization_Seats::used_count($organization['id']), 'limit' => null];
        $api_usage = class_exists('APIPlatform_Organization_Entitlements') ? APIPlatform_Organization_Entitlements::status($organization['id'], 'apis') : ['usage' => count($api_ids), 'limit' => null];
        $content = '<div class="apiplatform-publisher-page apiplatform-organization-page apiplatform-org-workspace">' . $this->notice($organization['id']);
        $content .= class_exists('APIPlatform_Plan_Simulation') ? APIPlatform_Plan_Simulation::publisher_notice($organization['id']) : '';
        $content .= $this->organization_header($organization, $current_member);
        $content .= $this->org_nav($organization, $view);
        $content .= '<div class="apiplatform-org-content">';

        if ($view === 'members') {
            $content .= $this->members_view($organization, $members);
        } elseif ($view === 'invitations') {
            $content .= $this->invitations_view($organization);
        } elseif ($view === 'activity') {
            $content .= $this->activity_view($organization);
        } elseif ($view === 'settings') {
            $content .= $this->settings_view($organization);
        } elseif ($view === 'apis') {
            $content .= $this->apis_view($organization, $api_ids);
        } else {
            $stats = [
                ['label' => 'Plan', 'value' => $plan['name'], 'meta' => ucfirst($plan['status']) . ' plan status'],
                ['label' => 'Seats Reserved', 'value' => $member_usage['usage'] . ' / ' . (class_exists('APIPlatform_Organization_Entitlements') ? APIPlatform_Organization_Entitlements::limit_label($member_usage['limit']) : APIPlatform_Organization_Seats::label($organization['id'])), 'meta' => APIPlatform_Membership_Panel::seat_policy_label()],
                ['label' => 'APIs', 'value' => $api_usage['usage'] . ' / ' . (class_exists('APIPlatform_Organization_Entitlements') ? APIPlatform_Organization_Entitlements::limit_label($api_usage['limit']) : 'Unlimited'), 'meta' => 'Organization-owned APIs'],
                ['label' => 'Pending Invitations', 'value' => APIPlatform_Organization_Seats::pending_invitation_count($organization['id']), 'meta' => 'Valid pending invitations reserve seats until accepted, declined, revoked, or expired.'],
                                ['label' => 'Your Role', 'value' => APIPlatform_Organization_Membership_Service::role_label($current_member['role'] ?? 'viewer'), 'meta' => 'Current workspace permission set'],
            ];
            $content .= $this->metric_grid($stats);
            $content .= '<div class="apiplatform-publisher-dashboard-grid apiplatform-org-grid">' . $this->panel('Recent Activity', $this->activity_list(APIPlatform_Organization_Service::events($organization['id'], 8)), 'apiplatform-org-card') . $this->panel('Quick Actions', $this->quick_actions($organization), 'apiplatform-org-card') . '</div>';
        }

        return $this->section($content . '</div></div>');
    }

    public function render_invite(){
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $invitation = APIPlatform_Organization_Invitation_Service::find_by_token($token);
        if (!$invitation) return $this->section($this->alert('error', 'This invitation is unavailable.'));
        $this->enqueue();
        $organization = APIPlatform_Organization_Service::find($invitation['organization_id']);
        $safe_email = $this->mask_email($invitation['email']);
        $content = '<div class="apiplatform-publisher-page apiplatform-org-workspace"><div class="apiplatform-publisher-page-head apiplatform-org-header"><div><p class="apiplatform-publisher-kicker">Invitation</p><h1>' . esc_html($organization['name'] ?? 'Organization') . '</h1><p>You were invited as ' . esc_html(APIPlatform_Organization_Membership_Service::role_label($invitation['role'])) . '.</p></div></div>';
        $content .= $this->notice();
        $content .= '<p class="apiplatform-publisher-muted">Invited email: ' . esc_html($safe_email) . '</p>';
        if (!is_user_logged_in()) {
            $content .= $this->alert('info', 'Sign in with the invited account to accept or decline this invitation.');
            $content .= '<p><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url(wp_login_url(APIPlatform_Organization_Invitation_Service::url($token))) . '">Sign in to continue</a></p>';
            return $this->section($content . '</div>');
        }
        $content .= '<form method="post" class="apiplatform-publisher-actions">' . wp_nonce_field('apiplatform_org_action', 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="apiplatform_org_action" value="accept-invite"><input type="hidden" name="invite_token" value="' . esc_attr($token) . '"><button class="apiplatform-button apiplatform-button-primary">Accept Invitation</button></form>';
        $content .= '<form method="post" class="apiplatform-publisher-actions">' . wp_nonce_field('apiplatform_org_action', 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="apiplatform_org_action" value="decline-invite"><input type="hidden" name="invite_token" value="' . esc_attr($token) . '"><button class="apiplatform-button apiplatform-button-secondary">Decline</button></form>';
        return $this->section($content . '</div>');
    }

    private function current_organization(){
        $slug = sanitize_title($_GET['organization'] ?? '');
        $id = absint($_GET['organization_id'] ?? 0);
        return $slug ? APIPlatform_Organization_Service::find_by_slug($slug) : APIPlatform_Organization_Service::find($id);
    }

    private function organization_header(array $org, $member){
        $logo = !empty($org['logo_url']) ? '<img class="apiplatform-org-logo" src="' . esc_url($org['logo_url']) . '" alt="">' : '<span class="apiplatform-publisher-avatar apiplatform-org-logo-fallback">' . esc_html(strtoupper(substr($org['name'], 0, 1))) . '</span>';
        $website = !empty($org['website_url']) ? '<a class="apiplatform-org-website" href="' . esc_url($org['website_url']) . '" target="_blank" rel="noopener">Website</a>' : '';
        $role = is_array($member) ? ($member['role'] ?? 'viewer') : 'viewer';
        return '<div class="apiplatform-publisher-page-head apiplatform-org-header"><div class="apiplatform-org-title-row">' . $logo . '<div><p class="apiplatform-publisher-kicker">Organization</p><h1>' . esc_html($org['name']) . '</h1><div class="apiplatform-org-subhead"><code>' . esc_html($org['slug']) . '</code>' . $website . '</div></div></div><div class="apiplatform-org-header-badges">' . $this->badge($org['status'], 'status') . $this->badge(class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::get_plan_name($org['id']) : 'Free', 'plan') . $this->badge($role, 'role') . '</div></div>';
    }

    private function organization_cards(array $organizations){
        if (!$organizations) return $this->empty_state('No organizations yet.', 'Create one when you are ready to collaborate.');
        $html = '<div class="apiplatform-publisher-api-grid apiplatform-org-grid">';
        foreach ($organizations as $org) {
            $plan_id = APIPlatform_Organization_Plans::get_plan_id($org['id']);
            $plan_label = APIPlatform_Membership_Panel::get_plan_label($plan_id);
            $seat_limit = APIPlatform_Organization_Entitlements::get_limit($org['id'], 'members.max');
            $seat_display = APIPlatform_Membership_Panel::display_limit($seat_limit, true);
            $simulation_label = class_exists('APIPlatform_Plan_Simulation') && APIPlatform_Plan_Simulation::organization_is_simulated_for_current_user($org['id']) ? '<p class="apiplatform-publisher-muted">Testing as ' . esc_html($plan_label) . '</p>' : '';
            $html .= '<article class="apiplatform-publisher-card apiplatform-org-card"><div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html(strtoupper(substr($org['name'], 0, 1))) . '</span><div><h2>' . esc_html($org['name']) . '</h2><p><code>' . esc_html($org['slug']) . '</code></p></div></div><div class="apiplatform-publisher-badges">' . $this->badge($org['status'], 'status') . $this->badge($plan_id, 'plan', $plan_label) . $this->badge($org['current_user_role'] ?? 'viewer', 'role') . '</div>' . $simulation_label . '<dl class="apiplatform-publisher-meta"><div><dt>Seats Reserved</dt><dd>' . esc_html(APIPlatform_Organization_Seats::used_count($org['id']) . ' / ' . $seat_display) . '</dd></div></dl><div class="apiplatform-publisher-actions apiplatform-org-actions"><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url($this->organization_url($org['slug'])) . '">Open</a></div></article>';
        }
        return $html . '</div>';
    }

    private function create_form(){
        return '<form method="post" class="apiplatform-publisher-form apiplatform-org-form">' . wp_nonce_field('apiplatform_org_action', 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="apiplatform_org_action" value="create"><label class="apiplatform-form-row"><span>Organization Name</span><input class="apiplatform-input" name="organization_name" required></label><label class="apiplatform-form-row"><span>Organization Slug</span><input class="apiplatform-input" name="organization_slug" placeholder="freedom-studios"></label><label class="apiplatform-form-row"><span>Description</span><textarea class="apiplatform-input" name="organization_description" rows="3"></textarea></label><label class="apiplatform-form-row"><span>Website URL</span><input class="apiplatform-input" name="organization_website_url" type="url"></label><label class="apiplatform-form-row"><span>Logo URL</span><input class="apiplatform-input" name="organization_logo_url" type="url"></label><button class="apiplatform-button apiplatform-button-primary">Create Organization</button></form>';
    }

    private function context_switcher($user_id, array $organizations){
        $selected_type = sanitize_key(get_user_meta($user_id, 'apiplatform_selected_owner_type', true) ?: 'personal');
        $selected_id = absint(get_user_meta($user_id, 'apiplatform_selected_owner_id', true) ?: $user_id);
        $html = '<form method="post" class="apiplatform-publisher-filters apiplatform-org-context-switcher">' . wp_nonce_field('apiplatform_org_action', 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="apiplatform_org_action" value="select-context"><label><span>Workspace Context</span><select name="owner_context">';
        $html .= '<option value="personal:' . absint($user_id) . '"' . selected($selected_type . ':' . $selected_id, 'personal:' . $user_id, false) . '>Personal Workspace</option>';
        foreach ($organizations as $org) {
            $value = 'organization:' . absint($org['id']);
            $html .= '<option value="' . esc_attr($value) . '"' . selected($selected_type . ':' . $selected_id, $value, false) . '>' . esc_html($org['name']) . '</option>';
        }
        return $html . '</select></label><input type="hidden" name="owner_type" value="' . esc_attr($selected_type) . '"><input type="hidden" name="owner_id" value="' . esc_attr($selected_id) . '"><button class="apiplatform-button apiplatform-button-secondary" onclick="var v=this.form.owner_context.value.split(\':\');this.form.owner_type.value=v[0];this.form.owner_id.value=v[1];">Switch</button></form>';
    }

    private function org_nav(array $org, $active){
        $links = ['home' => 'Overview', 'members' => 'Members', 'apis' => 'APIs', 'invitations' => 'Invitations', 'activity' => 'Activity', 'settings' => 'Settings'];
        $html = '<nav class="apiplatform-publisher-api-nav apiplatform-org-tabs" aria-label="Organization sections">';
        foreach ($links as $view => $label) {
            $html .= '<a class="' . ($active === $view ? 'is-active' : '') . '" href="' . esc_url($this->organization_url($org['slug'], $view)) . '"' . ($active === $view ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        return $html . '</nav>';
    }

    private function members_view(array $org, array $members){
        if (!APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'members.view')) return $this->alert('error', 'You cannot view members.');
        if (!$members) return $this->empty_state('No members yet.', 'Invite members to collaborate in this organization.');
        $html = '<div class="apiplatform-publisher-table-wrap apiplatform-org-table-wrap"><table class="apiplatform-publisher-table apiplatform-org-table"><thead><tr><th scope="col">Member</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Joined</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($members as $member) {
            $name = $member['display_name'] ?: 'User #' . $member['user_id'];
            $html .= '<tr><td><div class="apiplatform-org-member"><span class="apiplatform-publisher-avatar">' . esc_html(strtoupper(substr($name, 0, 1))) . '</span><strong>' . esc_html($name) . '</strong></div></td><td>' . esc_html($member['user_email']) . '</td><td>' . $this->badge($member['role'], 'role') . '</td><td>' . $this->badge($member['status'], 'status') . '</td><td><time datetime="' . esc_attr($member['joined_at']) . '">' . esc_html($this->date_label($member['joined_at'])) . '</time></td><td>' . $this->member_actions($org, $member) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function member_actions(array $org, array $member){
        $user_id = get_current_user_id();
        $html = '<div class="apiplatform-org-actions apiplatform-org-member-actions">';
        if (APIPlatform_Organization_Permissions::can($user_id, $org['id'], 'members.change_role')) {
            $html .= '<form method="post" class="apiplatform-inline-form apiplatform-org-role-form" aria-label="Change member role">' . wp_nonce_field('apiplatform_org_action_' . absint($org['id']), 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="organization_id" value="' . absint($org['id']) . '"><input type="hidden" name="target_user_id" value="' . absint($member['user_id']) . '"><input type="hidden" name="apiplatform_org_action" value="change-role"><select class="apiplatform-input" name="role" aria-label="Role">';
            foreach (APIPlatform_Organization_Membership_Service::roles() as $role) $html .= '<option value="' . esc_attr($role) . '"' . selected($member['role'], $role, false) . '>' . esc_html(APIPlatform_Organization_Membership_Service::role_label($role)) . '</option>';
            $html .= '</select><button class="apiplatform-button apiplatform-button-secondary">Save</button></form>';
        }
        if (APIPlatform_Organization_Permissions::can($user_id, $org['id'], 'members.suspend')) {
            $html .= $this->small_post_button($org['id'], $member['status'] === 'suspended' ? 'restore-member' : 'suspend-member', $member['status'] === 'suspended' ? 'Restore' : 'Suspend', ['target_user_id' => $member['user_id']]);
        }
        if (APIPlatform_Organization_Permissions::can($user_id, $org['id'], 'members.remove')) $html .= $this->small_post_button($org['id'], 'remove-member', 'Remove', ['target_user_id' => $member['user_id']]);
        return $html === '<div class="apiplatform-org-actions apiplatform-org-member-actions">' ? '<span class="apiplatform-publisher-muted">No actions</span>' : $html . '</div>';
    }

    private function invitations_view(array $org){
        $can_invite = APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'members.invite');
        $html = $can_invite ? $this->panel('Invite a Member', '<p class="apiplatform-publisher-muted">Invite teammates by email and assign the least-privileged role they need.</p>' . $this->invite_form($org), 'apiplatform-org-card') : $this->alert('error', 'You cannot invite members.');
        $rows = APIPlatform_Organization_Invitation_Service::pending_for_org($org['id']);
        if (!$rows) return $html . $this->panel('Pending Invitations', $this->empty_state('No pending invitations.', 'Invitations you send will appear here until they are accepted, declined, revoked, or expired.'), 'apiplatform-org-card');
        $table = '<div class="apiplatform-publisher-table-wrap apiplatform-org-table-wrap"><table class="apiplatform-publisher-table apiplatform-org-table"><thead><tr><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Invited</th><th scope="col">Expires</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $table .= '<tr><td>' . esc_html($this->mask_email($row['email'])) . '</td><td>' . $this->badge($row['role'], 'role') . '</td><td>' . $this->badge($row['status'], 'status') . '</td><td><time datetime="' . esc_attr($row['created_at']) . '">' . esc_html($this->date_label($row['created_at'])) . '</time></td><td><time datetime="' . esc_attr($row['expires_at']) . '">' . esc_html($this->date_label($row['expires_at'])) . '</time></td><td><div class="apiplatform-org-actions">' . ($row['status'] === 'pending' && $can_invite ? $this->small_post_button($org['id'], 'revoke-invitation', 'Revoke', ['invitation_id' => $row['id']], 'danger') : '<span class="apiplatform-publisher-muted">No actions</span>') . '</div></td></tr>';
        }
        return $html . $this->panel('Pending Invitations', $table . '</tbody></table></div>', 'apiplatform-org-card');
    }

    private function invite_form(array $org){
        $state = APIPlatform_Organization_Entitlements::status($org['id'], 'members');
        $limit = $state['limit'];
        $limit_label = APIPlatform_Organization_Entitlements::limit_label($limit);
        $plan_label = APIPlatform_Organization_Entitlements::get_plan_name($org['id']);
        $available = !empty($state['within_limit']) && ($org['status'] ?? '') === APIPlatform_Organization_Service::STATUS_ACTIVE;
        $testing = class_exists('APIPlatform_Plan_Simulation') && APIPlatform_Plan_Simulation::organization_is_simulated_for_current_user($org['id']);
        $context = '<dl class="apiplatform-publisher-meta"><div><dt>Seats Reserved</dt><dd>' . esc_html($state['usage'] . ' / ' . $limit_label) . '</dd></div><div><dt>Plan</dt><dd>' . esc_html($plan_label) . '</dd></div></dl>';
        $context .= '<div class="apiplatform-org-note">' . ($available ? 'Seats are available. A valid pending invitation reserves a seat until it is accepted, declined, revoked, or expires.' : APIPlatform_Error_Codes::title('organization_seat_limit_reached') . '. No additional seats can be reserved on the current plan.') . ($testing ? ' Testing is active for this organization.' : '') . '</div>';
        $button = '<button class="apiplatform-button apiplatform-button-primary"' . ($available ? '' : ' disabled aria-disabled="true"') . '>Send Invitation</button>';
        return '<form method="post" class="apiplatform-publisher-form apiplatform-org-form">' . wp_nonce_field('apiplatform_org_action_' . absint($org['id']), 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="organization_id" value="' . absint($org['id']) . '"><input type="hidden" name="apiplatform_org_action" value="invite">' . $context . '<label class="apiplatform-form-row"><span>Email</span><input class="apiplatform-input" type="email" name="email" required></label><label class="apiplatform-form-row"><span>Role</span><select class="apiplatform-input" name="role">' . $this->role_options('viewer') . '</select></label><label class="apiplatform-form-row"><span>Optional Message</span><textarea class="apiplatform-input" name="message" rows="2"></textarea></label>' . $button . '</form>';
    }

    private function activity_view(array $org){
        if (!APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'organization_activity.view')) return $this->alert('error', 'You cannot view organization activity.');
        return $this->activity_list(APIPlatform_Organization_Service::events($org['id'], 50));
    }

    private function settings_view(array $org){
        $html = '<div class="apiplatform-publisher-dashboard-grid apiplatform-org-grid apiplatform-org-settings-grid">';
        if (class_exists('APIPlatform_Organization_Entitlements')) {
            $member_usage = APIPlatform_Organization_Entitlements::status($org['id'], 'members');
            $api_usage = APIPlatform_Organization_Entitlements::status($org['id'], 'apis');
            $html .= $this->panel('Plan & Usage', '<p><strong>' . esc_html(APIPlatform_Organization_Entitlements::get_plan_name($org['id'])) . '</strong> plan</p><dl class="apiplatform-publisher-meta"><div><dt>Seats Reserved</dt><dd>' . esc_html($member_usage['usage'] . ' / ' . APIPlatform_Organization_Entitlements::limit_label($member_usage['limit'])) . '</dd></div><div><dt>APIs</dt><dd>' . esc_html($api_usage['usage'] . ' / ' . APIPlatform_Organization_Entitlements::limit_label($api_usage['limit'])) . '</dd></div></dl><p class="apiplatform-publisher-muted">Plan changes are managed by a platform administrator. Billing and checkout are not available here.</p>', 'apiplatform-org-card');
        }
        if (APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'organization.edit')) {
            $html .= $this->panel('General Settings', '<form method="post" class="apiplatform-publisher-form apiplatform-org-form">' . wp_nonce_field('apiplatform_org_action_' . absint($org['id']), 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="organization_id" value="' . absint($org['id']) . '"><input type="hidden" name="apiplatform_org_action" value="update"><label class="apiplatform-form-row"><span>Name</span><input class="apiplatform-input" name="organization_name" value="' . esc_attr($org['name']) . '" required></label><label class="apiplatform-form-row"><span>Description</span><textarea class="apiplatform-input" name="organization_description" rows="3">' . esc_textarea($org['description']) . '</textarea></label><label class="apiplatform-form-row"><span>Website URL</span><input class="apiplatform-input" name="organization_website_url" value="' . esc_attr($org['website_url']) . '"></label><label class="apiplatform-form-row"><span>Logo URL</span><input class="apiplatform-input" name="organization_logo_url" value="' . esc_attr($org['logo_url']) . '"></label><div class="apiplatform-publisher-actions apiplatform-org-actions"><button class="apiplatform-button apiplatform-button-primary">Save Settings</button></div></form>', 'apiplatform-org-card');
        }
        $html .= $this->panel('Membership', '<p class="apiplatform-publisher-muted">Final owners must promote another owner before leaving.</p><div class="apiplatform-org-actions">' . $this->small_post_button($org['id'], 'leave', 'Leave Organization') . '</div>', 'apiplatform-org-card');
        if (APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'organization.archive')) $html .= $this->panel('Danger Zone', '<div class="apiplatform-org-danger-zone"><h3>Archive Organization</h3><p>Archive preserves data but blocks new organization actions.</p><div class="apiplatform-org-actions">' . $this->small_post_button($org['id'], 'archive', 'Archive Organization', [], 'danger') . '</div></div>', 'apiplatform-org-card apiplatform-org-card-danger');
        return $html . '</div>';
    }

    private function apis_view(array $org, array $api_ids){
        if (!$api_ids) return $this->empty_state('No organization APIs yet', 'Create an API and choose ' . $org['name'] . ' as its owner, or transfer an existing personal API.') . '<div class="apiplatform-org-actions"><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url($this->create_api_url($org)) . '">Create API</a></div>';
        $request_counts = $this->request_counts_by_api($api_ids);
        $html = '<div class="apiplatform-publisher-api-grid apiplatform-org-grid">';
        $rendered = 0;
        foreach ($api_ids as $api_id) {
            $post = get_post($api_id);
            if (!$post) continue;
            $rendered++;
            $visibility = get_post_meta($api_id, 'apiplatform_portal_visibility', true) ?: 'private';
            $lifecycle = get_post_meta($api_id, 'apiplatform_lifecycle_status', true) ?: 'private';
            $version = get_post_meta($api_id, 'api_version', true) ?: get_post_meta($api_id, 'apiplatform_version', true) ?: '1.0.0';
            $gateway_runtime = get_post_meta($api_id, 'apiplatform_gateway_runtime_type', true) ?: 'internal';
            $runtime_status = get_post_meta($api_id, 'apiplatform_status', true) ?: ($post->post_status === 'publish' ? 'active' : $post->post_status);
            $summary = trim((string) ($post->post_excerpt ?: $post->post_content));
            $can_manage = APIPlatform_Organization_Permissions::can(get_current_user_id(), $org['id'], 'apis.edit');
            $html .= '<article class="apiplatform-publisher-card apiplatform-org-card"><div class="apiplatform-publisher-card-head"><span class="apiplatform-publisher-avatar">' . esc_html(strtoupper(substr(get_the_title($post), 0, 1))) . '</span><div><h2>' . esc_html(get_the_title($post)) . '</h2><p>' . esc_html($summary !== '' ? wp_trim_words($summary, 18) : $post->post_name) . '</p></div></div><div class="apiplatform-publisher-badges">' . $this->badge($visibility, 'visibility') . $this->badge($lifecycle, 'lifecycle') . $this->badge($runtime_status, 'status') . '</div><dl class="apiplatform-publisher-meta"><div><dt>Version</dt><dd>' . esc_html($version) . '</dd></div><div><dt>Gateway</dt><dd>' . esc_html(ucwords(str_replace('_', ' ', $gateway_runtime))) . '</dd></div><div><dt>Requests Today</dt><dd>' . esc_html(number_format((int) ($request_counts[$api_id] ?? 0))) . '</dd></div><div><dt>Your Permission</dt><dd>' . esc_html($can_manage ? 'Manage' : 'View') . '</dd></div><div><dt>Last Updated</dt><dd>' . esc_html($this->date_label($post->post_modified)) . '</dd></div></dl><div class="apiplatform-publisher-actions apiplatform-org-actions"><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url(add_query_arg(['apipage' => 'api', 'api_id' => $api_id], class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'))) . '">' . esc_html($can_manage ? 'Manage' : 'View') . '</a></div></article>';
        }
        return $rendered ? $html . '</div>' : $this->empty_state('No organization APIs yet', 'Create an API and choose ' . $org['name'] . ' as its owner, or transfer an existing personal API.') . '<div class="apiplatform-org-actions"><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url($this->create_api_url($org)) . '">Create API</a></div>';
    }

    private function quick_actions(array $org){
        return '<div class="apiplatform-publisher-actions apiplatform-org-actions"><a class="apiplatform-button apiplatform-button-primary" href="' . esc_url($this->organization_url($org['slug'], 'invitations')) . '">Invite Members</a><a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($this->create_api_url($org)) . '">Create API</a><a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($this->organization_url($org['slug'], 'members')) . '">View Members</a><a class="apiplatform-button apiplatform-button-secondary" href="' . esc_url($this->organization_url($org['slug'], 'settings')) . '">Organization Settings</a></div>';
    }

    private function create_api_url(array $org){
        return add_query_arg(['apipage' => 'create', 'owner_type' => 'organization', 'owner_id' => absint($org['id'])], class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard'));
    }

    private function organization_api_ids($organization_id){
        return class_exists('APIPlatform_Ownership_Service') ? APIPlatform_Ownership_Service::api_ids_for_owner('organization', $organization_id) : [];
    }

    private function request_counts_by_api(array $api_ids){
        global $wpdb;
        $api_ids = array_values(array_filter(array_map('absint', $api_ids)));
        if (!$api_ids) return [];
        $table = $wpdb->prefix . 'apiplatform_logs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        $placeholders = implode(',', array_fill(0, count($api_ids), '%d'));
        $query = "SELECT api_id, COUNT(*) AS total FROM `{$table}` WHERE api_id IN ({$placeholders}) AND created_at >= %s GROUP BY api_id";
        $rows = $wpdb->get_results(call_user_func_array([$wpdb, 'prepare'], array_merge([$query], $api_ids, [date('Y-m-d 00:00:00', current_time('timestamp'))])), ARRAY_A);
        $counts = [];
        foreach ($rows as $row) $counts[(int) $row['api_id']] = (int) $row['total'];
        return $counts;
    }

    private function role_options($selected){
        $html = '';
        foreach (APIPlatform_Organization_Membership_Service::roles() as $role) $html .= '<option value="' . esc_attr($role) . '"' . selected($selected, $role, false) . '>' . esc_html(APIPlatform_Organization_Membership_Service::role_label($role)) . '</option>';
        return $html;
    }

    private function activity_list(array $events){
        if (!$events) return $this->empty_state('No activity yet.', 'Organization events will appear here.');
        $html = '<div class="apiplatform-publisher-activity-list apiplatform-org-activity-list">';
        foreach ($events as $event) {
            $title = $event['message'] ?: $this->event_label($event['event_type']);
            $html .= '<div class="apiplatform-publisher-activity-item apiplatform-org-activity-item"><span class="apiplatform-org-activity-marker" aria-hidden="true"></span><div><strong>' . esc_html($title) . '</strong><span>' . esc_html(($event['actor_name'] ?: 'System') . ' - ' . $this->date_label($event['created_at'])) . '</span></div></div>';
        }
        return $html . '</div>';
    }

    private function small_post_button($org_id, $action, $label, array $fields = [], $variant = 'secondary'){
        $button_class = $variant === 'danger' ? 'apiplatform-button-danger' : 'apiplatform-button-secondary';
        $html = '<form method="post" class="apiplatform-inline-form">' . wp_nonce_field('apiplatform_org_action_' . absint($org_id), 'apiplatform_org_nonce', true, false) . '<input type="hidden" name="organization_id" value="' . absint($org_id) . '"><input type="hidden" name="apiplatform_org_action" value="' . esc_attr($action) . '">';
        foreach ($fields as $key => $value) $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        return $html . '<button class="apiplatform-button ' . esc_attr($button_class) . '">' . esc_html($label) . '</button></form>';
    }

    private function result_notice($result, $success){ return is_wp_error($result) ? $this->entitlement_result_notice($result) : [$success, 'success']; }
    private function entitlement_result_notice($result, $organization_id = 0, $acceptance = false){
        if (!is_wp_error($result)) return ['', 'success'];
        $code = $result->get_error_code();
        $data = (array) $result->get_error_data($code);
        $definition = class_exists('APIPlatform_Error_Codes') ? APIPlatform_Error_Codes::get_by_key($code) : null;
        if (!$definition) {
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[FreedomAPI organization action failed] ' . sanitize_key($code));
            $definition = APIPlatform_Error_Codes::get_by_key('platform_error');
        }
        $error_id = 'FAPI-' . absint($definition['code']);
        if (!in_array($code, ['organization_seat_limit_reached', 'organization_api_limit_reached'], true)) return ['<strong>' . esc_html($definition['title']) . '</strong><br>' . esc_html($definition['message']) . '<br><small>Error code: ' . esc_html($error_id) . '</small>', 'error'];
        $organization_id = absint($data['organization_id'] ?? $organization_id);
        $organization = $organization_id ? APIPlatform_Organization_Service::find($organization_id) : null;
        if ($code === 'organization_seat_limit_reached' && $acceptance) return ['<strong>' . esc_html($definition['title']) . '</strong><br>This organization no longer has an available seat. Please contact the organization owner.<br><small>Error code: ' . esc_html($error_id) . '</small>', 'error'];
        $usage = max(0, absint($data['usage'] ?? 0));
        $limit = APIPlatform_Membership_Panel::display_limit($data['limit'] ?? 0, true);
        $plan_label = APIPlatform_Membership_Panel::get_plan_label($data['plan'] ?? 'free');
        $name = $organization['name'] ?? 'This organization';
        $resource = $code === 'organization_seat_limit_reached' ? 'seats' : 'APIs';
        $message = '<strong>' . esc_html($definition['title']) . '</strong><br>' . esc_html($name . ' is using ' . $usage . ' of ' . $limit . ' available ' . $resource . ' on the ' . $plan_label . '.');
        if (class_exists('APIPlatform_Plan_Simulation') && $organization_id && APIPlatform_Plan_Simulation::organization_is_simulated_for_current_user($organization_id)) $message .= '<br>' . esc_html('Testing as ' . $plan_label . '. Actual plan: ' . APIPlatform_Membership_Panel::get_plan_label(APIPlatform_Membership_Panel::get_user_plan(get_current_user_id())) . '.');
        $message .= '<br>' . esc_html($definition['resolution']) . '<br><small>Error code: ' . esc_html($error_id) . '</small>';
        return [$message, 'error'];
    }
    private function notice_metadata($result){
        $code = is_wp_error($result) ? $result->get_error_code() : 'platform_error';
        $definition = APIPlatform_Error_Codes::get_by_key($code) ?: APIPlatform_Error_Codes::get_by_key('platform_error');
        $data = is_wp_error($result) ? (array) $result->get_error_data($code) : [];
        return ['fapi_code' => absint($definition['code']), 'fapi_id' => 'FAPI-' . absint($definition['code']), 'title' => $definition['title'], 'resolution' => $definition['resolution'], 'http_status' => absint($definition['http_status']), 'context' => ['organization_id' => absint($data['organization_id'] ?? 0), 'usage' => max(0, absint($data['usage'] ?? 0)), 'limit' => APIPlatform_Membership_Panel::display_limit($data['limit'] ?? 0, true), 'resource' => sanitize_key($data['resource'] ?? ''), 'plan_id' => sanitize_key($data['plan'] ?? ''), 'plan' => sanitize_key($data['plan'] ?? '')]];
    }
    private function notice($organization_id = 0){
        $user_id = get_current_user_id();
        $key = $this->notice_key($user_id, $organization_id);
        $notice = get_transient($key);
        if (!$notice && $organization_id) {
            $key = $this->notice_key($user_id);
            $notice = get_transient($key);
        }
        if (!$notice || !is_array($notice)) return '';
        delete_transient($key);
        return APIPlatform_Renderer::component('alert', ['type' => $notice['type'] ?? 'success', 'content' => $notice['message'] ?? '', 'fapi_id' => $notice['fapi_id'] ?? '', 'title' => $notice['title'] ?? '', 'message' => $notice['message'] ?? '', 'resolution' => $notice['resolution'] ?? '']);
    }
    private function notice_key($user_id, $organization_id = 0){ return 'apiplatform_org_notice_' . absint($user_id) . ($organization_id ? '_organization_' . absint($organization_id) : ''); }
    private function page_url($page, array $args = []){ return add_query_arg(array_merge(['apipage' => sanitize_key($page)], $args), class_exists('APIPlatform_Routes') ? APIPlatform_Routes::dashboard_url() : site_url('/dashboard')); }
    private function organization_url($slug, $view = 'home'){ return $this->page_url('organization', ['organization' => sanitize_title($slug), 'org_view' => sanitize_key($view)]); }
    private function organization_url_by_id($id, $view = 'home'){ $org = APIPlatform_Organization_Service::find($id); return $org ? $this->organization_url($org['slug'], $view) : $this->page_url('organizations'); }
    private function section($content){ return APIPlatform_Renderer::component('section', ['content' => $content]); }
    private function panel($title, $content, $class = ''){ return '<article class="apiplatform-publisher-card apiplatform-org-card ' . esc_attr($class) . '"><h2>' . esc_html($title) . '</h2>' . $content . '</article>'; }
    private function alert($type, $content){ return APIPlatform_Renderer::component('alert', ['type' => $type, 'content' => $content]); }
    private function empty_state($title, $text){ return '<div class="apiplatform-publisher-empty apiplatform-org-empty"><h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p></div>'; }
    private function metric_grid(array $stats){ $html = '<div class="apiplatform-publisher-metrics apiplatform-org-metrics">'; foreach ($stats as $stat) $html .= '<article class="apiplatform-publisher-metric apiplatform-org-metric"><span>' . esc_html($stat['label']) . '</span><strong>' . esc_html($stat['value']) . '</strong><small>' . esc_html($stat['meta']) . '</small></article>'; return $html . '</div>'; }
    private function mask_email($email){ $email = sanitize_email($email); if (!$email || strpos($email, '@') === false) return 'invited address'; [$name, $domain] = explode('@', $email, 2); return substr($name, 0, 1) . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain; }
    private function badge($value, $type, $label = null){ $value = sanitize_key((string) $value); if ($label === null) $label = $type === 'role' ? APIPlatform_Organization_Membership_Service::role_label($value) : ucwords(str_replace(['-', '_'], ' ', $value)); return '<span class="apiplatform-publisher-badge apiplatform-org-badge apiplatform-org-badge-' . esc_attr($type) . ' apiplatform-org-badge-' . esc_attr($value) . '">' . esc_html($label) . '</span>'; }
    private function date_label($date){ $timestamp = strtotime((string) $date); return $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : 'Not available'; }
    private function event_label($event_type){
        $labels = ['organization_created' => 'Organization created', 'organization_updated' => 'Organization updated', 'organization_archived' => 'Organization archived', 'member_added' => 'Member added', 'member_role_changed' => 'Member role changed', 'member_suspended' => 'Member suspended', 'member_restored' => 'Member restored', 'member_removed' => 'Member removed', 'member_left' => 'Member left', 'member_invited' => 'Member invited', 'invitation_accepted' => 'Invitation accepted', 'invitation_declined' => 'Invitation declined', 'invitation_revoked' => 'Invitation revoked', 'api_created' => 'API assigned', 'api_transferred_in' => 'API transferred in', 'api_transferred_out' => 'API transferred out', 'api_archived' => 'API archived', 'api_restored' => 'API restored', 'api_lifecycle_changed' => 'API lifecycle changed', 'api_version_updated' => 'API version updated', 'api_documentation_updated' => 'API documentation updated', 'api_documentation_published' => 'API documentation published', 'api_changelog_updated' => 'API changelog updated', 'api_schema_updated' => 'API schema updated', 'api_models_updated' => 'API models updated', 'api_validation_updated' => 'API validation updated', 'api_gateway_updated' => 'API gateway updated', 'api_schema_imported' => 'API schema imported', 'api_sdk_settings_updated' => 'API SDK settings updated', 'api_sdk_previewed' => 'API SDK previewed', 'api_sdk_generated' => 'API SDK generated', 'api_unpublished' => 'API unpublished', 'api_application_access_updated' => 'API application access updated', 'seat_limit_reached' => 'Seat limit reached', 'plan_changed' => 'Plan changed', 'member_invitation_blocked' => 'Member invitation blocked', 'api_creation_blocked' => 'API creation blocked'];
        $key = sanitize_key((string) $event_type);
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
    private function enqueue(){ if (class_exists('APIPlatform_Frontend_Assets')) { APIPlatform_Frontend_Assets::enqueue_common(); wp_enqueue_style('apiplatform-dashboard-style'); } }
}
