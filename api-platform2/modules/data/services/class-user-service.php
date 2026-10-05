<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_User_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | User
    |--------------------------------------------------------------------------
    */

    public function get_user($user_id){

        return get_userdata($user_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Team Members
    |--------------------------------------------------------------------------
    */

    public function get_team_members(
        $user_id
    ){

        if (
            function_exists(
                'apiplatform_get_team_members'
            )
        ){

            return apiplatform_get_team_members(
                $user_id
            );
        }

        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    */

    public function get_transactions(
        $user_id
    ){

        if (
            function_exists(
                'apiplatform_get_transactions'
            )
        ){

            return apiplatform_get_transactions(
                $user_id
            );
        }

        return [];
    }
}