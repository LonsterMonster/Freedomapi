<?php

$items   = $items ?? [];

$columns = $columns ?? 3;

$class   = $class ?? '';

?>

<div class="
    apiplatform-grid
    apiplatform-grid-<?php echo intval($columns); ?>
    <?php echo esc_attr($class); ?>
">

    <?php foreach($items as $item): ?>

        <div class="apiplatform-grid-item">

            <?php echo $item; ?>

        </div>

    <?php endforeach; ?>

</div>