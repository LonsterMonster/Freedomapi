<?php
if (!defined('ABSPATH')) exit;

function apiplatform_detect_abuse($user_id, $api_id){

    // simple rate check example
    $count = get_transient("apiplatform_abuse_$user_id") ?: 0;
    $count++;

    set_transient("apiplatform_abuse_$user_id", $count, 60);

    if ($count > 100){
		if (defined('WP_DEBUG') && WP_DEBUG){

			error_log("⚠️ Abuse detected for user $user_id");

		}
        // optional:
        // apiplatform_notify($user_id, 'Abuse detected', ['email']);
    }
}