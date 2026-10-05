<div class="apiplatform-card">

    <h3>APIs</h3>

    <?php foreach($apis as $api): ?>

        <p>

            <?php echo esc_html(
                $api->post_title
            ); ?>

            →

            <?php echo esc_html(
                (int)get_post_meta(
                    $api->ID,
                    'api_usage',
                    true
                )
            ); ?>

        </p>

    <?php endforeach; ?>

</div>