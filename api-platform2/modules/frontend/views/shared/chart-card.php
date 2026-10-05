<?php

$title   = $title ?? 'Chart';
$chart   = $chart ?? '';
$content = $content ?? '';

?>

<div class="apiplatform-card apiplatform-chart-card">

    <div class="chart-card-header">

        <h3>
            <?php echo esc_html($title); ?>
        </h3>

    </div>

    <div class="chart-card-body">

        <?php echo $chart; ?>

    </div>

    <?php if($content): ?>

        <div class="chart-card-footer">

            <?php echo wp_kses_post($content); ?>

        </div>

    <?php endif; ?>

</div>