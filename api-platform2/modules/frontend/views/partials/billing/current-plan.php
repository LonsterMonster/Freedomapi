<?php

$plan = $plan ?? [];

$content = '

<p>

Plan:

<strong>

' . esc_html($plan['name'] ?? 'Free Plan') . '

</strong>

</p>
';

if (!empty($plan['requests'])){

    $content .= '

<p>

Requests:

<strong>

' . esc_html($plan['requests']) . '

</strong>

</p>
';
}

if (!empty($plan['apis'])){

    $content .= '

<p>

APIs:

<strong>

' . esc_html($plan['apis']) . '

</strong>

</p>
';
}

if (!empty($plan['status']) || !isset($plan['status'])){

    $content .= '

<p>

Status:

<strong>

' . esc_html($plan['status'] ?? 'Active') . '

</strong>

</p>
';
}

if (!empty($plan['action'])){

    $content .= '

<p>

Action:

<strong>

' . esc_html($plan['action']) . '

</strong>

</p>
';
}

if (!empty($plan['renews_at'])){

    $content .= '

<p>

Renews:

<strong>

' . esc_html($plan['renews_at']) . '

</strong>

</p>
';
}

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Current Plan',

        'content' => $content
    ]
);
?>
