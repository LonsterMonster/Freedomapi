<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('APIPlatform_Intelligence')) {

class APIPlatform_Intelligence {

    public function __construct(){
        add_action('apiplatform_after_request', [$this,'check'], 10, 4);
    }
    
    public function check($user_id = 0, $api_id = 0, $status = 0, $response_time = 0){

        if (!$user_id) return;

        // 🔥 GET DATA SAFELY
        $credits = function_exists('apiplatform_get_credits') 
            ? apiplatform_get_credits($user_id) 
            : 0;

        /*
        ----------------------------------------
        LOW CREDIT PROMO (WITH COOLDOWN)
        ----------------------------------------
        */
        if ($credits <= 5){

            if (!get_transient('apiplatform_low_credit_'.$user_id)){

                if (function_exists('apiplatform_notify')){
                    apiplatform_notify(
                        $user_id,
                        '⚡ You are running low on credits. Upgrade now: https://yourapp.com/billing',
                        ['email','push']
                    );
                }

                set_transient('apiplatform_low_credit_'.$user_id, 1, 3600); // 1 hour
            }
        }
		
        /*
        ----------------------------------------
        HIGH ERROR RATE
        ----------------------------------------
        */
        if ($status >= 500){

            if (function_exists('apiplatform_create_alert')){
                apiplatform_create_alert($user_id, 'High error rate detected');
            }
        }

        /*
        ----------------------------------------
        SLOW REQUEST
        ----------------------------------------
        */
        if ($response_time > 2){

            if (!get_transient('apiplatform_slow_'.$user_id)){

                if (function_exists('apiplatform_create_alert')){
                    apiplatform_create_alert($user_id, 'Slow API response detected');
                }

                set_transient('apiplatform_slow_'.$user_id, 1, 600); // 10 min
            }
        }

        /*
        ----------------------------------------
        LOW CREDIT WARNING
        ----------------------------------------
        */
        if ($credits <= 10){

            if (!get_transient('apiplatform_warn_'.$user_id)){

                if (function_exists('apiplatform_create_alert')){
                    apiplatform_create_alert($user_id, 'Low credits warning');
                }

                set_transient('apiplatform_warn_'.$user_id, 1, 1800); // 30 min
            }
        }
		$context = [
			'credits' => $credits,
			'status' => $status,
			'response_time' => $response_time
		];

		apiplatform_run_automations($user_id, $context);
    }
}

}