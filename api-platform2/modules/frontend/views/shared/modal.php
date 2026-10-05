<?php

$id      = $id ?? 'modal';
$title   = $title ?? 'Modal';
$content = $content ?? '';

?>

<div
    class="apiplatform-modal"
    id="<?php echo esc_attr($id); ?>"
>

    <div class="apiplatform-modal-overlay"></div>

    <div class="apiplatform-modal-content">

        <div class="apiplatform-modal-header">

            <h3>

                <?php echo esc_html($title); ?>

            </h3>

            <button
                class="apiplatform-modal-close"
                onclick="document.getElementById('<?php echo esc_js($id); ?>').style.display='none'"
            >

                ✕

            </button>

        </div>

        <div class="apiplatform-modal-body">

            <?php echo wp_kses_post($content); ?>

        </div>

    </div>

</div>