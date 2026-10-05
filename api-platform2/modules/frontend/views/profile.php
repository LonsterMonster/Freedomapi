
<div class="apiplatform-profile">

    <h1>Dashboard</h1>

    <?php
    include __DIR__ .
        '/partials/profile/wallet-card.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/profile/usage-card.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/profile/usage-chart.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/profile/api-usage.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/profile/team-card.php';
    ?>

    <?php
    include __DIR__ .
        '/partials/profile/transactions-table.php';
    ?>

</div>

<script>
window.APIPlatformProfileChart = {
    data: <?php echo json_encode(
        $chart_data ?: [0,0,0,0,0,0,0]
    ); ?>
};
</script>