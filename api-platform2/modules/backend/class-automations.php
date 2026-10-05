<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Automations {

    public function __construct(){
        add_action('apiplatform_after_request', [$this,'run'], 10, 4);
    }

    public function run($user_id = 0, $api_id = 0, $status = 0, $response_time = 0){

        if (!$user_id) return;

        // 🔥 SAFE DATA
        $usage = function_exists('apiplatform_get_total_usage') 
            ? apiplatform_get_total_usage($user_id) 
            : 0;

        $limits = function_exists('apiplatform_get_plan_limits') 
            ? apiplatform_get_plan_limits($user_id) 
            : ['request_limit'=>0];

        $limit = $limits['request_limit'] ?? 0;

        /*
        ----------------------------------------
        AUTO WARNING (80%)
        ----------------------------------------
        */
        if ($limit > 0 && ($usage / $limit) > 0.8){

            if (!get_transient('apiplatform_80_'.$user_id)){

                if (function_exists('apiplatform_notify')){
                    apiplatform_notify(
                        $user_id,
                        '⚠️ You are nearing your API limit',
                        ['email','push']
                    );
                }

                set_transient('apiplatform_80_'.$user_id, 1, 3600);
            }
        }

        /*
        ----------------------------------------
        RUN CUSTOM RULES
        ----------------------------------------
        */
        if (function_exists('apiplatform_run_automations')){

            $context = [
                'usage' => $usage,
                'limit' => $limit,
                'status' => $status,
                'response_time' => $response_time
            ];

            apiplatform_run_automations($user_id, $context);
        }
    }
}