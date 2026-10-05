<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Billing_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate Bill
    |--------------------------------------------------------------------------
    */

    public function calculate_bill($user_id){

        if (
            function_exists(
                'apiplatform_calculate_bill'
            )
        ){

            return apiplatform_calculate_bill(
                $user_id
            );
        }

        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Usage
    |--------------------------------------------------------------------------
    */

    public function get_usage($user_id){

        return get_user_meta(

            $user_id,

            'apiplatform_usage_' .
            date('Ym'),

            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Transactions
    |--------------------------------------------------------------------------
    */

    public function get_transactions($user_id = 0, $limit = 100){

        global $wpdb;

        $table = $wpdb->prefix . 'apiplatform_transactions';
        $limit = max(1, min(500, absint($limit)));

        if ($user_id > 0) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
                    absint($user_id),
                    $limit
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY created_at DESC, id DESC LIMIT %d",
                $limit
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Credits
    |--------------------------------------------------------------------------
    */

    public function get_credits($user_id){

        if (
            function_exists(
                'apiplatform_get_credits'
            )
        ){

            return apiplatform_get_credits(
                $user_id
            );
        }

        return 0;
    }
}
