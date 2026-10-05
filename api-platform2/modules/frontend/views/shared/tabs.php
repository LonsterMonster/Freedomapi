<?php

$tabs   = $tabs ?? [];
$active = $active ?? '';

?>

<div class="apiplatform-tabs">

    <div class="apiplatform-tab-buttons">

        <?php foreach($tabs as $slug => $tab): ?>

            <button
                class="
                    apiplatform-tab-button
                    <?php echo $slug === $active ? 'active' : ''; ?>
                "
                data-tab="<?php echo esc_attr($slug); ?>"
            >

                <?php
                echo esc_html(
                    $tab['label']
                );
                ?>

            </button>

        <?php endforeach; ?>

    </div>

    <div class="apiplatform-tab-panels">

        <?php foreach($tabs as $slug => $tab): ?>

            <div
                class="
                    apiplatform-tab-panel
                    <?php echo $slug === $active ? 'active' : ''; ?>
                "
                data-panel="<?php echo esc_attr($slug); ?>"
            >

                <?php
                echo $tab['content'];
                ?>

            </div>

        <?php endforeach; ?>

    </div>

</div>