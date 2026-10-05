<?php

$class = $class ?? '';

?>

<div class="apiplatform-card <?php echo esc_attr($class); ?>">

    <?php if (!empty($title)): ?>

        <h3>
            <?php echo esc_html($title); ?>
        </h3>

    <?php endif; ?>

    <?php echo $content ?? ''; ?>

</div>
