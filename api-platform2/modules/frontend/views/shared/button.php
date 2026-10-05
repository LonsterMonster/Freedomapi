<?php

$type  = $type  ?? 'primary';
$label = $label ?? 'Button';
$url   = $url   ?? '';
$class = $class ?? '';
$html_type = $html_type ?? 'submit';
$value = $value ?? '';
$aria_label = $aria_label ?? '';
$data_attrs = is_array($data_attrs ?? null) ? $data_attrs : [];

$button_class =
    'apiplatform-button ' .
    'apiplatform-button-' . $type .
    ' ' .
    $class;
?>

<?php if($url): ?>

    <a
        href="<?php echo esc_url($url); ?>"
        class="<?php echo esc_attr($button_class); ?>"
    >

        <?php echo esc_html($label); ?>

    </a>

<?php else: ?>

    <button
        class="<?php echo esc_attr($button_class); ?>"
        type="<?php echo esc_attr($html_type); ?>"
        <?php if($value !== ''): ?>
            value="<?php echo esc_attr($value); ?>"
        <?php endif; ?>
        <?php if($aria_label): ?>
            aria-label="<?php echo esc_attr($aria_label); ?>"
        <?php endif; ?>
        <?php foreach($data_attrs as $data_name => $data_value): ?>
            data-<?php echo esc_attr($data_name); ?>="<?php echo esc_attr($data_value); ?>"
        <?php endforeach; ?>
    >

        <?php echo esc_html($label); ?>

    </button>

<?php endif; ?>
