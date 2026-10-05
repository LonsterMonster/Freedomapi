<?php

if (!defined('ABSPATH')) exit;

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

if (
    !defined(
        'APIPLATFORM_DATA_PATH'
    )
){

    define(

        'APIPLATFORM_DATA_PATH',

        plugin_dir_path(__FILE__)
    );
}

/*
|--------------------------------------------------------------------------
| Load Classes
|--------------------------------------------------------------------------
*/

foreach ([

    APIPLATFORM_DATA_PATH .
        'classes/class-data-router.php',

    APIPLATFORM_DATA_PATH .
        'services/class-usage-service.php',

    APIPLATFORM_DATA_PATH .
        'services/class-key-service.php',

    APIPLATFORM_DATA_PATH .
        'services/class-billing-service.php',

    APIPLATFORM_DATA_PATH .
        'services/class-plans-service.php',

    APIPLATFORM_DATA_PATH .
        'services/class-user-service.php',

    APIPLATFORM_DATA_PATH .
        'services/class-analytics-service.php',

] as $file){

    if (file_exists($file)) {

        require_once $file;
    }
}

/*
|--------------------------------------------------------------------------
| Test Route
|--------------------------------------------------------------------------
*/

APIPlatform_Data_Router::register(

    'usage',

    function(){

        return [

            'success' => true,

            'message' => 'Data layer works'
        ];
    }
);
