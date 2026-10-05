<?php

$invoices = $invoices ?? [];

$rows = [];

foreach($invoices as $invoice){

    $rows[] = [

        esc_html($invoice['date'] ?? ''),

        esc_html($invoice['amount'] ?? ''),

        esc_html($invoice['status'] ?? ''),

        esc_html($invoice['invoice_id'] ?? '')
    ];
}

$content = APIPlatform_Renderer::component(

    'table',

    [

        'headers' => [

            'Date',

            'Amount',

            'Status',

            'Invoice ID'
        ],

        'rows' => $rows
    ]
);

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Invoices',

        'content' => $content
    ]
);
?>
