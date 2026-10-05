<?php

$label = $label ?? '';
$value = $value ?? '';
$icon  = $icon ?? '';

?>

<div class="apiplatform-stat">

    <?php if($icon): ?>

        <div class="stat-icon">

            <?php echo esc_html($icon); ?>

        </div>

    <?php endif; ?>

    <small>

        <?php echo esc_html($label); ?>

    </small>

    <h2>

        <?php echo esc_html($value); ?>

    </h2>

</div>