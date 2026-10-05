<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Builder_Component_Product_Card {

    public function get_definition(){
        return [
            'name' => 'product-card',
            'label' => __('Product Card', 'apiplatform'),
            'description' => __('A reusable response block for product and price data.', 'apiplatform'),
            'fields' => [
                ['name' => 'product', 'label' => __('Product', 'apiplatform'), 'type' => 'text', 'placeholder' => 'Premium Plan'],
                ['name' => 'price', 'label' => __('Price', 'apiplatform'), 'type' => 'text', 'placeholder' => '$19.00'],
                ['name' => 'url', 'label' => __('URL', 'apiplatform'), 'type' => 'url', 'placeholder' => 'https://example.com/product'],
            ],
            'output' => [
                'type' => 'product',
                'product' => '{{product}}',
                'price' => '{{price}}',
                'url' => '{{url}}',
            ],
        ];
    }
}
