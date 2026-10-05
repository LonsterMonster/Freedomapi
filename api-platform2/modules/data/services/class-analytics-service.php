<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Analytics_Service {

    private static $instance = null;

    public static function instance(){

        if (self::$instance === null) {

            self::$instance = new self();
        }

        return self::$instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Request Count
    |--------------------------------------------------------------------------
    */

    public function get_total_requests(){

        global $wpdb;

        $table =
            $wpdb->prefix .
            'apiplatform_logs';

        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $table"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Recent Logs
    |--------------------------------------------------------------------------
    */

    public function get_recent_logs(
        $limit = 50
    ){

        global $wpdb;

        $table =
            $wpdb->prefix .
            'apiplatform_logs';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT *
                FROM $table
                ORDER BY created_at DESC
                LIMIT %d
            ", $limit)
        );
    }
}