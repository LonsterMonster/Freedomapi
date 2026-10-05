<?php

$items = $items ?? [];

$class = $class ?? '';

?>

<div class="
    apiplatform-stack
    <?php echo esc_attr($class); ?>
">

    <?php foreach($items as $item): ?>

        <div class="apiplatform-stack-item">

            <?php echo $item; ?>

        </div>

    <?php endforeach; ?>

</div>