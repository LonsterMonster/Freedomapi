<div class="apiplatform-plans-page">

    <?php
    include __DIR__ .
        '/partials/plans/pricing-header.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/plans/basic.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/plans/standard.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/plans/unlimited.php';
    ?>

    <div class="apiplatform-plan-grid">

        <?php foreach($plans as $plan): ?>

            <?php
            include __DIR__ .
                '/partials/plans/plan-card.php';
            ?>

        <?php endforeach; ?>

    </div>

</div>