<?php
if (!defined('ABSPATH')) exit;

function apiplatform_get_templates(){

    return [

        'social' => [
            'label' => 'Social Media',
            'templates' => [

                'profile' => [
                    'label' => 'User Profile API',
                    'fields' => [
                        'username' => ['label'=>'Username']
                    ],
                    'logic' => [
                        'steps' => [
                            [
                                'type'=>'response',
                                'data'=>[
                                    'username'=>'{{username}}',
                                    'followers'=>'1000',
                                    'bio'=>'Demo user'
                                ]
                            ]
                        ]
                    ]
                ]

            ]
        ],

        'store' => [
            'label' => 'Store',
            'templates' => [

                'product' => [
                    'label' => 'Product API',
                    'fields' => [
                        'product' => ['label'=>'Product'],
                        'price'   => ['label'=>'Price']
                    ],
                    'logic' => [
                        'steps' => [
                            [
                                'type'=>'response',
                                'data'=>[
                                    'product'=>'{{product}}',
                                    'price'=>'{{price}}'
                                ]
                            ]
                        ]
                    ]
                ]

            ]
        ]

    ];
}

/*
----------------------------------------
APPLY VARIABLES
----------------------------------------
*/
function apiplatform_apply_template_fields($logic, $fields){

    array_walk_recursive($logic, function(&$value) use ($fields){

        if (is_string($value)){
            $value = preg_replace_callback('/{{(.*?)}}/', function($m) use ($fields){
                return $fields[$m[1]] ?? '';
            }, $value);
        }

    });

    return $logic;
}