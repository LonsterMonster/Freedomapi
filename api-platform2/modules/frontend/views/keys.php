<div class="apiplatform-profile">

    <h1>API Keys</h1>

    <?php if (!$apis): ?>

        <?php
        include __DIR__ .
            '/partials/keys/no-apis.php';
        ?>

    <?php endif; ?>

    <?php foreach($apis as $api): ?>

        <?php

        $keys = get_post_meta(
            $api->ID,
            'api_keys',
            true
        );

        if (!is_array($keys)) {
            $keys = [];
        }

        include __DIR__ .
            '/partials/keys/api-card.php';

        ?>

    <?php endforeach; ?>

</div>