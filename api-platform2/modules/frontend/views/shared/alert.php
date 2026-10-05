<?php

$allowed_html = wp_kses_allowed_html('post');

$allowed_html['button'] = array_merge($allowed_html['button'] ?? [], [
    'aria-label' => true,
    'class' => true,
    'data-copy-success' => true,
    'data-copy-source' => true,
    'data-copy-value' => true,
    'id' => true,
    'name' => true,
    'type' => true,
    'value' => true,
]);

$allowed_html['code'] = array_merge($allowed_html['code'] ?? [], [
    'class' => true,
    'id' => true,
]);

?>

<div class="apiplatform-alert <?php echo esc_attr($type ?? 'info'); ?>" role="alert" aria-live="assertive">

    <?php
    $alert_content = $content ?? '';
    if ($alert_content === '' && !empty($title)) {
        $alert_content = '<strong>' . esc_html($title) . '</strong>';
        if (!empty($message)) $alert_content .= '<br>' . esc_html($message);
        if (!empty($resolution)) $alert_content .= '<br>' . esc_html($resolution);
        if (!empty($fapi_id)) $alert_content .= '<br><small>Error code: ' . esc_html($fapi_id) . '</small>';
    }
    echo wp_kses($alert_content, $allowed_html);
    ?>

</div>
