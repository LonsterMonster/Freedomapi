<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Data_Router {

    private static $routes = [];

    public static function register(
        $route,
        $callback
    ){

        self::$routes[$route] = $callback;
    }

    public static function dispatch($route){

        if (!isset(self::$routes[$route])) {

            return [
                'error' => 'Route not found'
            ];
        }

        return call_user_func(
            self::$routes[$route]
        );
    }
}