<?php

$content = $content ?? '';

$width = $width ?? '1';

$class = $class ?? '';

?>

<div class="
    apiplatform-column
    apiplatform-column-<?php echo esc_attr($width); ?>
    <?php echo esc_attr($class); ?>
">

    <?php echo wp_kses_post($content); ?>

</div>