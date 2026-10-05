<?php

$requests = $requests ?? [];

$rows = [];

foreach($requests as $request){

    $rows[] = [

        esc_html($request['api_name'] ?? ''),

        esc_html($request['endpoint'] ?? ''),

        esc_html($request['method'] ?? ''),

        esc_html($request['status'] ?? ''),

        esc_html($request['response_time'] ?? ''),

        esc_html($request['created_at'] ?? '')
    ];
}

$content = APIPlatform_Renderer::component(

    'table',

    [

        'headers' => [

            'API Name',

            'Endpoint',

            'Method',

            'Status',

            'Response Time',

            'Time'
        ],

        'rows' => $rows
    ]
);

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Recent Requests',

        'content' => $content
    ]
);
?>
