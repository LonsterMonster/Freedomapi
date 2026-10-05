<?php

$api = $api ?? [];

$delete_nonce = $delete_nonce ?? '';

$content = '<form method="post" class="apiplatform-delete-api-form">';

$content .= '<input type="hidden" name="apiplatform_edit_api_action" value="delete">';

$content .= '<input type="hidden" name="apiplatform_delete_api_nonce" value="' . esc_attr($delete_nonce) . '">';

$content .= '<p>Deleting this API permanently removes it and invalidates its endpoint.</p>';

$content .= '<label class="apiplatform-confirm-delete">';

$content .= '<input type="checkbox" name="confirm_delete" value="1" required>';

$content .= '<span>I understand this API will be permanently deleted.</span>';

$content .= '</label>';

$content .= APIPlatform_Renderer::component(

    'button',

    [

        'type' => 'danger',

        'label' => 'Delete API',

        'html_type' => 'submit'
    ]
);

$content .= '</form>';

echo APIPlatform_Renderer::component(

    'card',

    [

    'title' => 'Delete API',

        'class' => 'apiplatform-edit-api-card apiplatform-danger-zone',

        'content' => '<div id="apiplatform-danger-zone"></div><p><strong>Archive is recommended before permanent deletion.</strong> Deletion is irreversible and can remove the API endpoint from owner workflows. Existing request logs are not intentionally cascade-deleted by this form.</p>' . $content
    ]
);
?>
