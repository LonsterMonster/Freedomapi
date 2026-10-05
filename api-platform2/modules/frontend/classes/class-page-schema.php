<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Page_Schema {

    /*
    |--------------------------------------------------------------------------
    | Registered Schemas
    |--------------------------------------------------------------------------
    */

    protected static $schemas = [];

    /*
    |--------------------------------------------------------------------------
    | Register Schema
    |--------------------------------------------------------------------------
    */

    public static function register(

        $name,

        array $schema = []

    ){

        self::$schemas[$name] = $schema;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Schema
    |--------------------------------------------------------------------------
    */

    public static function get(

        $name

    ){

        return self::$schemas[$name] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Render Schema
    |--------------------------------------------------------------------------
    */

    public static function render(

        $name

    ){

        $schema = self::get($name);

        if (empty($schema)) {

            return '';
        }

        return APIPlatform_Dynamic_Renderer::render(
            $schema
        );
    }
}