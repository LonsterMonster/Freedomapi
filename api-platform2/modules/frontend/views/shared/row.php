<?php

$content = $content ?? '';
$class   = $class ?? '';

?>

<div class="
    apiplatform-row
    <?php echo esc_attr($class); ?>
">

    <?php echo wp_kses_post($content); ?>

</div>