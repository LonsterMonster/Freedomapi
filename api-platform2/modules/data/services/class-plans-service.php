<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Plan_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Plan Limits
    |--------------------------------------------------------------------------
    */

    public function get_limits($user_id){

        if (
            function_exists(
                'apiplatform_get_plan_limits'
            )
        ){

            return apiplatform_get_plan_limits(
                $user_id
            );
        }

        return [

            'request_limit' => 0,

            'api_limit' => 0
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Seat Limit
    |--------------------------------------------------------------------------
    */

    public function get_seat_limit($user_id){

        if (
            function_exists(
                'apiplatform_get_seat_limit'
            )
        ){

            return apiplatform_get_seat_limit(
                $user_id
            );
        }

        return 1;
    }
}