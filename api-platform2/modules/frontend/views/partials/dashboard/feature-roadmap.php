<?php

$roadmap = $roadmap ?? [];

$sections = [
    'available' => 'Available Features',
    'coming_soon' => 'Coming Soon',
    'released' => 'Recently Released'
];

$content = '<div class="apiplatform-roadmap">';

foreach($sections as $key => $label){

    $features = $roadmap[$key] ?? [];

    $content .= '<div class="apiplatform-roadmap-section">';

    $content .= '<h4>' . esc_html($label) . '</h4>';

    if (empty($features)){

        $content .= '<p class="apiplatform-roadmap-empty">No features to show.</p>';
    } else {

        $content .= '<div class="apiplatform-roadmap-list">';

        foreach($features as $feature){

            $content .= APIPlatform_Renderer::partial(

                'dashboard/feature-card',

                [

                    'feature' => $feature
                ]
            );
        }

        $content .= '</div>';
    }

    $content .= '</div>';
}

$content .= '</div>';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Feature Roadmap',

        'class' => 'apiplatform-roadmap-card',

        'content' => $content
    ]
);
?>
