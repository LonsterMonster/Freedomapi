<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Usage_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Total Usage
    |--------------------------------------------------------------------------
    */

    public function get_total_usage($user_id){

        return (int)
            get_user_meta(
                $user_id,
                'apiplatform_total_usage',
                true
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Usage Percent
    |--------------------------------------------------------------------------
    */

    public function get_usage_percent(
        $user_id,
        $limit
    ){

        $used =
            $this->get_total_usage(
                $user_id
            );

        if ($limit == INF) {
            return 0;
        }

        return ($used / max($limit,1)) * 100;
    }
}