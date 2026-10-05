<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Renderer {

    /*
    |--------------------------------------------------------------------------
    | Render View
    |--------------------------------------------------------------------------
    */

    public static function view(

        $view,

        $data = []

    ){

        $file =

            APIPLATFORM_FRONTEND_PATH .

            'views/' .

            $view .

            '.php';

        if (!file_exists($file)) {

            return '';
        }

        extract($data);

        ob_start();

        include $file;

        return ob_get_clean();
    }

    /*
    |--------------------------------------------------------------------------
    | Render Partial
    |--------------------------------------------------------------------------
    */

    public static function partial(

        $partial,

        $data = []

    ){

        $file =

            APIPLATFORM_FRONTEND_PATH .

            'views/partials/' .

            $partial .

            '.php';

        if (!file_exists($file)) {

            return '';
        }

        extract($data);

        ob_start();

        include $file;

        return ob_get_clean();
    }

    /*
    |--------------------------------------------------------------------------
    | Render Shared Component
    |--------------------------------------------------------------------------
    */

    public static function component(

        $component,

        $data = []

    ){

        $file =

            APIPLATFORM_FRONTEND_PATH .

            'views/shared/' .

            $component .

            '.php';

        if (!file_exists($file)) {

            return '';
        }

        extract($data);

        ob_start();

        include $file;

        return ob_get_clean();
    }
}