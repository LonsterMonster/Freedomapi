<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Dynamic_Renderer {

    /*
    |--------------------------------------------------------------------------
    | Render Components
    |--------------------------------------------------------------------------
    */

    public static function render(

        array $components = []

    ){

        $output = '';

        foreach($components as $component){

            /*
            |--------------------------------------------------------------------------
            | Validation
            |--------------------------------------------------------------------------
            */

            if (

                empty($component['component'])
            ){

                continue;
            }

            $name =

                sanitize_key(

                    $component['component']
                );

            $props =

                $component['props']
                ?? [];

            /*
            |--------------------------------------------------------------------------
            | Registry Check
            |--------------------------------------------------------------------------
            */

            if (

                !APIPlatform_Component_Registry::exists(
                    $name
                )
            ){

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Render Component
            |--------------------------------------------------------------------------
            */

            $output .= APIPlatform_Renderer::component(

                $name,

                $props
            );
        }

        return $output;
    }
}