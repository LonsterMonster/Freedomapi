<?php

$payment_methods = $payment_methods ?? [];

if (empty($payment_methods)){

    $content = '

<p>

No payment methods found.

</p>
';
} else {

    $rows = [];

    foreach($payment_methods as $method){

        $rows[] = [

            esc_html($method['gateway'] ?? 'Payment Method'),

            esc_html($method['details'] ?? ''),

            esc_html($method['status'] ?? 'Available')
        ];
    }

    $content = APIPlatform_Renderer::component(

        'table',

        [

            'headers' => [

                'Gateway',

                'Details',

                'Status'
            ],

            'rows' => $rows
        ]
    );
}

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Payment Methods',

        'content' => $content
    ]
);
?>
