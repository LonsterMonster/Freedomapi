<?php

$content = '';

$type = 'info';

if ($percent >= 90){

    $type = 'fail';

    $content .= '

    ⚠️ You are at 90% usage

    ';

} elseif ($percent >= 75){

    $type = 'warning';

    $content .= '

    ⚠️ Approaching limit

    ';
}

if (!empty($content)){

    echo APIPlatform_Renderer::component(

        'alert',

        [

            'type' => $type,

            'content' => $content
        ]
    );
}
?>