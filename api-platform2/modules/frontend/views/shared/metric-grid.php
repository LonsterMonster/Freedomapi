<?php

$items = $items ?? [];

?>

<div class="apiplatform-metric-grid">

    <?php foreach($items as $item): ?>

        <div class="metric-grid-item">

            <?php echo $item; ?>

        </div>

    <?php endforeach; ?>

</div>