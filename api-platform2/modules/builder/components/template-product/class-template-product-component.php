<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Component_Template_Product {

    public function get_definition(){
        return [
            'name' => 'template-product',
            'label' => __('Template: Product API', 'apiplatform'),
            'description' => __('Builder component converted from the legacy Store product template.', 'apiplatform'),
            'fields' => [
                ['name' => 'product', 'label' => __('Product', 'apiplatform'), 'type' => 'text', 'placeholder' => 'Example Product', 'default' => 'Example Product'],
                ['name' => 'price', 'label' => __('Price', 'apiplatform'), 'type' => 'text', 'placeholder' => '19.99', 'default' => '19.99'],
            ],
            'output' => [
                'product' => '{{product}}',
                'price' => '{{price}}',
            ],
        ];
    }
}
