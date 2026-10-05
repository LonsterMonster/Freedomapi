<?php

$content = $content ?? '';
$class   = $class ?? '';

$allowed_html = wp_kses_allowed_html('post');

$allowed_html['form'] = [

    'action' => true,
    'class' => true,
    'id' => true,
    'method' => true
];

$allowed_html['a'] = array_merge($allowed_html['a'] ?? [], [

    'aria-current' => true,
    'class' => true,
    'href' => true
]);

$allowed_html['label'] = array_merge($allowed_html['label'] ?? [], [

    'class' => true,
    'for' => true
]);

$allowed_html['select'] = [

    'class' => true,
    'id' => true,
    'name' => true,
    'required' => true
];

$allowed_html['option'] = [

    'selected' => true,
    'value' => true
];

$allowed_html['div'] = array_merge($allowed_html['div'] ?? [], [

    'aria-label' => true,
    'class' => true,
    'data-api-id' => true,
    'data-doc-panel' => true,
    'data-rows' => true,
    'id' => true,
    'role' => true
]);

$allowed_html['section'] = array_merge($allowed_html['section'] ?? [], [

    'class' => true,
    'id' => true
]);

$allowed_html['article'] = array_merge($allowed_html['article'] ?? [], [

    'class' => true,
    'id' => true
]);

$allowed_html['nav'] = array_merge($allowed_html['nav'] ?? [], [

    'aria-label' => true,
    'class' => true,
    'id' => true,
    'role' => true
]);

$allowed_html['header'] = array_merge($allowed_html['header'] ?? [], [

    'class' => true,
    'id' => true
]);

$allowed_html['pre'] = array_merge($allowed_html['pre'] ?? [], [

    'class' => true,
    'id' => true
]);

$allowed_html['table'] = array_merge($allowed_html['table'] ?? [], [

    'class' => true
]);

$allowed_html['thead'] = array_merge($allowed_html['thead'] ?? [], [

    'class' => true
]);

$allowed_html['tbody'] = array_merge($allowed_html['tbody'] ?? [], [

    'class' => true
]);

$allowed_html['tr'] = array_merge($allowed_html['tr'] ?? [], [

    'class' => true
]);

$allowed_html['th'] = array_merge($allowed_html['th'] ?? [], [

    'class' => true,
    'scope' => true
]);

$allowed_html['td'] = array_merge($allowed_html['td'] ?? [], [

    'class' => true,
    'colspan' => true,
    'rowspan' => true
]);

$allowed_html['code'] = array_merge($allowed_html['code'] ?? [], [

    'class' => true,
    'id' => true
]);

$allowed_html['progress'] = array_merge($allowed_html['progress'] ?? [], [

    'class' => true,
    'max' => true,
    'value' => true
]);

$allowed_html['span'] = array_merge($allowed_html['span'] ?? [], [

    'aria-hidden' => true,
    'class' => true
]);

$allowed_html['strong'] = array_merge($allowed_html['strong'] ?? [], [

    'class' => true
]);

$allowed_html['input'] = [

    'autocomplete' => true,
    'checked' => true,
    'class' => true,
    'data-apiplatform-endpoint-source' => true,
    'data-apiplatform-endpoint-target' => true,
    'id' => true,
    'name' => true,
    'placeholder' => true,
    'readonly' => true,
    'required' => true,
    'type' => true,
    'value' => true
];

$allowed_html['textarea'] = [

    'class' => true,
    'id' => true,
    'name' => true,
    'placeholder' => true,
    'required' => true,
    'rows' => true
];

$allowed_html['button'] = array_merge($allowed_html['button'] ?? [], [

    'aria-label' => true,
    'class' => true,
    'data-add-row' => true,
    'data-doc-tab' => true,
    'data-history-toggle' => true,
    'data-copy-success' => true,
    'data-copy-source' => true,
    'data-copy-value' => true,
    'id' => true,
    'name' => true,
    'type' => true,
    'value' => true
]);

?>

<section class="
    apiplatform-section
    <?php echo esc_attr($class); ?>
">

    <div class="apiplatform-section-inner">

        <?php echo wp_kses($content, $allowed_html); ?>

    </div>

</section>
