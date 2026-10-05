<?php

$activity = $activity ?? [];

$rows = [];

foreach($activity as $item){

    $rows[] = [

        esc_html($item['api_name'] ?? ''),

        esc_html($item['status'] ?? ''),

        esc_html(number_format((int) ($item['requests'] ?? 0))),

        !empty($item['last_activity'])

            ? esc_html($item['last_activity'])

            : 'No activity yet'
    ];
}

$content = APIPlatform_Renderer::component(

    'table',

    [

        'headers' => [

            'API Name',

            'Status',

            'Requests',

            'Last Activity'
        ],

        'rows' => $rows
    ]
);

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Recent API Activity',

        'content' => $content
    ]
);
?>
