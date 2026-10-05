<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Menu {

    const SLUG = 'apiplatform';

    public function __construct(){
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(){

        // 🔥 MAIN MENU
        add_menu_page(
            'API Platform',
            'API Platform',
            'manage_options',
            self::SLUG,
            [$this, 'dashboard'],
            'dashicons-rest-api',
            25
        );
        
        // DASHBOARD
        add_submenu_page(
            self::SLUG,
            'Dashboard',
            'Dashboard',
            'manage_options',
            self::SLUG,
            [$this, 'dashboard']
        );

        // LOGS
        add_submenu_page(
            self::SLUG,
            'Logs',
            'Logs',
            'manage_options',
            'apiplatform-logs',
            [$this, 'logs']
        );

        // SETTINGS
        add_submenu_page(
            self::SLUG,
            'Settings',
            'Settings',
            'manage_options',
            'apiplatform-settings',
            [$this, 'settings']
        );

        add_submenu_page(
            self::SLUG,
            'Routes',
            'Routes',
            'manage_options',
            'apiplatform-routes',
            [$this, 'routes']
        );

        add_submenu_page(
            self::SLUG,
            'Auth Providers',
            'Auth Providers',
            'manage_options',
            'apiplatform-auth-providers',
            [$this, 'auth_providers']
        );

        add_submenu_page(
            self::SLUG,
            'Organizations',
            'Organizations',
            'manage_options',
            'apiplatform-organizations',
            [$this, 'organizations']
        );

        add_submenu_page(
            self::SLUG,
            'Key Analytics',
            'Key Analytics',
            'manage_options',
            'apiplatform-keys',
            [$this, 'keys']
        );

        add_submenu_page(
            self::SLUG,
            'Transactions',
            'Transactions',
            'manage_options',
            'apiplatform-transactions',
            [$this, 'transactions']
        );

        add_submenu_page(
            self::SLUG,
            'Automations',
            'Automations',
            'manage_options',
            'apiplatform-automations',
            [$this, 'automations']
        );

        add_submenu_page(
            self::SLUG,
            'Debug',
            'Debug',
            'manage_options',
            'apiplatform-debug',
            [$this, 'debug']
        );

        add_submenu_page(
            self::SLUG,
            'Bans',
            'Bans',
            'manage_options',
            'apiplatform-bans',
            [$this, 'bans']
        );
        
    }

    public function dashboard(){

		if (!current_user_can('manage_options')) {
			echo '<div class="wrap"><p>Access denied.</p></div>';
			return;
		}

		// INPUTS
		$selected_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
		$search        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		$orderby       = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'usage';

		// USERS
		$users = get_users([
			'fields' => ['ID','user_login']
		]);

		// QUERY
		$args = [
			'post_type'      => 'user_api',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'no_found_rows'  => true,
		];

		if ($selected_user) {
			$args['author'] = $selected_user;
		}

		if ($search) {
			$args['s'] = $search;
		}

		$apis = get_posts($args);

		// SORT (manual because meta sort not reliable here)
		if ($orderby === 'usage') {
			usort($apis, function($a,$b){
				return (int)get_post_meta($b->ID,'api_usage',true)
					 - (int)get_post_meta($a->ID,'api_usage',true);
			});
		}

		echo '<div class="wrap">';
		echo '<h1>API Platform Dashboard</h1>';

		/*
		----------------------------------------
		FILTERS
		----------------------------------------
		*/
		echo '<form method="get" style="margin-bottom:15px;">';
		echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'">';

		// USER FILTER
		echo '<select name="user_id">';
		echo '<option value="0">All Users</option>';
		foreach ($users as $user) {
			$sel = ($selected_user == $user->ID) ? 'selected' : '';
			echo '<option value="'.$user->ID.'" '.$sel.'>'.$user->user_login.'</option>';
		}
		echo '</select>';

		// SEARCH
		echo '<input type="text" name="s" placeholder="Search APIs..." value="'.esc_attr($search).'">';

		// SORT
		echo '<select name="orderby">';
		echo '<option value="usage" '.selected($orderby,'usage',false).'>Sort by Usage</option>';
		echo '</select>';

		echo '<button class="button">Apply</button>';
		echo '</form>';

		/*
		----------------------------------------
		TABLE
		----------------------------------------
		*/
		echo '<table class="widefat fixed striped">';
		echo '<thead>
			<tr>
				<th>Name</th>
				<th>Endpoint</th>
				<th>User</th>
				<th>Plan</th>
				<th>Usage</th>
				<th>Actions</th>
			</tr>
		</thead><tbody>';

		if (empty($apis)) {
			echo '<tr><td colspan="6">No APIs found</td></tr>';
		}

		foreach ($apis as $api){

			$user   = get_userdata($api->post_author);
			$usage  = (int)get_post_meta($api->ID,'api_usage',true);

			$limits = apiplatform_get_plan_limits($api->post_author);
			$limit  = $limits['request_limit'];

			$percent = APIPlatform_Membership_Panel::is_unlimited($limit) ? 0 : min(100, ($usage / max(1,$limit)) * 100);

			$plan = function_exists('apiplatform_get_user_plan')
				? apiplatform_get_user_plan($api->post_author)
				: '—';

			echo '<tr>';

			echo '<td>'.esc_html($api->post_title).'</td>';
			echo '<td>/'.esc_html($api->post_name).'</td>';
			echo '<td>'.esc_html($user ? $user->user_login : 'Unknown').'</td>';
			echo '<td>'.esc_html($plan).'</td>';

			// USAGE BAR
			echo '<td style="width:220px;">';
			echo '<div style="background:#eee;border-radius:6px;height:14px;overflow:hidden;">';
			echo '<div style="
				width:'.$percent.'%;
				background:'.($percent>90?'red':($percent>70?'orange':'lime')).';
				height:100%;
			"></div>';
			echo '</div>';
			echo '<small>'.$usage.' / '.(APIPlatform_Membership_Panel::is_unlimited($limit) ? '∞' : $limit).' ('.round($percent).'%)</small>';
			echo '</td>';

			// ACTIONS
			echo '<td>';

			echo '<a class="button" href="'.admin_url('post.php?post='.$api->ID.'&action=edit').'">Edit</a> ';

			echo '<a class="button" href="'.admin_url('post-new.php?post_type=user_api').'">New</a> ';

			echo '<a class="button button-link-delete" 
				href="'.get_delete_post_link($api->ID).'"
				onclick="return confirm(\'Delete this API?\')"
			>Delete</a>';

			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '</div>';
	}

    public function logs(){
        echo '<div class="wrap">';

        if (class_exists('APIPlatform_Admin_Logs')) {
            apiplatform_bootstrap_class_once('APIPlatform_Admin_Logs')->page();
        } else {
            echo '<p>Logs module not loaded.</p>';
        }

        echo '</div>';
    }

    public function settings(){
    
        echo '<div class="wrap">';
    
        if (class_exists('APIPlatform_Admin_Settings')) {
    
            $admin = apiplatform_bootstrap_class_once('APIPlatform_Admin_Settings');
            $admin->render();
    
        } else {
            echo '<p>Admin settings module not loaded.</p>';
        }
    
        echo '</div>';
    }

    public function routes(){

        echo '<div class="wrap">';

        if (class_exists('APIPlatform_Admin_Settings')) {
            $admin = apiplatform_bootstrap_class_once('APIPlatform_Admin_Settings');
            echo '<h1>API Platform Routes</h1>';
            $admin->routes_tab();
        } else {
            echo '<p>Admin settings module not loaded.</p>';
        }

        echo '</div>';
    }

    public function auth_providers(){

        echo '<div class="wrap">';
        echo '<h1>Authentication Providers</h1>';

        echo class_exists('APIPlatform_Auth_Settings')
            ? APIPlatform_Auth_Settings::render()
            : '<p>Authentication provider module is not loaded.</p>';

        echo '</div>';
    }

    public function organizations(){
        if (!current_user_can('manage_options')) {
            echo '<div class="wrap"><p>Access denied.</p></div>';
            return;
        }

        if (!class_exists('APIPlatform_Organization_Service')) {
            echo '<div class="wrap"><h1>Organizations</h1><p>Organization services are not loaded.</p></div>';
            return;
        }

        if (!empty($_POST['apiplatform_admin_org_settings'])) {
            check_admin_referer('apiplatform_admin_org_settings');
            $limit_type = sanitize_key(wp_unslash($_POST['seat_limit_type'] ?? 'unlimited'));
            $limit = $limit_type === 'custom' ? max(1, absint($_POST['seat_limit'] ?? 1)) : 'unlimited';
            update_option('apiplatform_default_organization_seat_limit', $limit, false);
            $default_plan = sanitize_key(wp_unslash($_POST['default_plan'] ?? 'free'));
            if (class_exists('APIPlatform_Organization_Plans') && in_array($default_plan, APIPlatform_Organization_Plans::plan_ids(), true)) update_option('apiplatform_default_organization_plan', $default_plan, false);
            echo '<div class="notice notice-success"><p>Organization plan defaults saved.</p></div>';
        }
        if (!empty($_POST['apiplatform_admin_org_plan']) && class_exists('APIPlatform_Organization_Plans')) {
            check_admin_referer('apiplatform_admin_org_plan');
            $result = APIPlatform_Organization_Plans::set_plan(absint($_POST['organization_id'] ?? 0), wp_unslash($_POST['plan_id'] ?? ''), wp_unslash($_POST['plan_status'] ?? 'active'), get_current_user_id());
            echo is_wp_error($result) ? '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>' : '<div class="notice notice-success"><p>Organization plan updated.</p></div>';
        }

        $organizations = APIPlatform_Organization_Service::admin_list();
        $current_limit = get_option('apiplatform_default_organization_seat_limit', 'unlimited');
        $current_plan = class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::default_plan_id() : 'free';

        echo '<div class="wrap"><h1>Organizations</h1>';
        echo '<form method="post" style="margin:16px 0;padding:12px;background:#fff;border:1px solid #ccd0d4;">';
        wp_nonce_field('apiplatform_admin_org_settings');
        echo '<input type="hidden" name="apiplatform_admin_org_settings" value="1">';
        echo '<h2>Default Organization Plan</h2><p>Entitlements are managed by FreedomAPI; this does not process payments.</p><label>Plan <select name="default_plan">';
        foreach ((class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::definitions() : []) as $id => $plan) echo '<option value="' . esc_attr($id) . '"' . selected($current_plan, $id, false) . '>' . esc_html($plan['name']) . '</option>';
        echo '</select></label>';
        echo '<label><input type="radio" name="seat_limit_type" value="unlimited" ' . checked($current_limit, 'unlimited', false) . '> Unlimited</label> ';
        echo '<label><input type="radio" name="seat_limit_type" value="custom" ' . checked($current_limit !== 'unlimited', true, false) . '> Custom</label> ';
        echo '<input type="number" min="1" name="seat_limit" value="' . esc_attr($current_limit === 'unlimited' ? 10 : absint($current_limit)) . '"> ';
        submit_button('Save Seat Policy', 'secondary', 'submit', false);
        echo '</form>';
        echo '<table class="widefat fixed striped"><thead><tr><th>Organization</th><th>Slug</th><th>Status</th><th>Plan</th><th>Owners</th><th>Members</th><th>Seats</th><th>APIs</th><th>Created</th></tr></thead><tbody>';
        if (!$organizations) {
            echo '<tr><td colspan="9">No organizations found.</td></tr>';
        }
        foreach ($organizations as $org) {
            $members = APIPlatform_Organization_Membership_Service::list_members($org['id']);
            $owners = array_filter($members, function($member){ return ($member['role'] ?? '') === 'owner' && ($member['status'] ?? '') === 'active'; });
            $api_count = count(get_posts(['post_type' => 'user_api', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'fields' => 'ids', 'numberposts' => -1, 'no_found_rows' => true, 'meta_query' => [['key' => APIPlatform_Ownership_Service::META_OWNER_TYPE, 'value' => 'organization'], ['key' => APIPlatform_Ownership_Service::META_OWNER_ID, 'value' => absint($org['id'])]]]));
            echo '<tr>';
            echo '<td>' . esc_html($org['name']) . '</td>';
            echo '<td>' . esc_html($org['slug']) . '</td>';
            echo '<td>' . esc_html($org['status']) . '</td>';
            $plan = class_exists('APIPlatform_Organization_Plans') ? APIPlatform_Organization_Plans::get_plan($org['id']) : ['name' => 'Free Plan', 'status' => 'active'];
            echo '<td>' . esc_html($plan['name']) . '<br><small>' . esc_html(ucwords(str_replace('_', ' ', $plan['status'])) . ' · owner membership') . '</small></td>';            echo '<td>' . esc_html(count($owners)) . '</td>';
            echo '<td>' . esc_html(count($members)) . '</td>';
            echo '<td>' . esc_html(APIPlatform_Organization_Seats::used_count($org['id']) . ' / ' . APIPlatform_Organization_Seats::label($org['id'])) . '</td>';
            echo '<td>' . esc_html($api_count) . '</td>';
            echo '<td>' . esc_html($org['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function keys(){
        if (class_exists('APIPlatform_Admin_Keys')) {
            apiplatform_bootstrap_class_once('APIPlatform_Admin_Keys')->page();
        }
    }

    public function transactions(){
        if (class_exists('APIPlatform_Admin_Transactions')) {
            apiplatform_bootstrap_class_once('APIPlatform_Admin_Transactions')->page();
        }
    }

    public function automations(){
        if (class_exists('APIPlatform_Admin_Automations')) {
            echo apiplatform_bootstrap_class_once('APIPlatform_Admin_Automations')->render();
        }
    }

    public function debug(){
        if (class_exists('APIPlatform_Admin_Debug')) {
            echo apiplatform_bootstrap_class_once('APIPlatform_Admin_Debug')->render();
        }
    }

    public function bans(){
        if (class_exists('APIPlatform_Admin_Bans')) {
            apiplatform_bootstrap_class_once('APIPlatform_Admin_Bans')->page();
        }
    }
}

