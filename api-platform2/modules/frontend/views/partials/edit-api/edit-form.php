<?php

$api = $api ?? [];

$edit_nonce = $edit_nonce ?? '';

$status = $api['status'] ?? 'active';

$content = '<form method="post" class="apiplatform-edit-api-form">';

$content .= '<input type="hidden" name="apiplatform_edit_api_action" value="save">';

$content .= '<input type="hidden" name="apiplatform_edit_api_nonce" value="' . esc_attr($edit_nonce) . '">';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-api-name">API Name</label>';
$content .= '<input id="apiplatform-api-name" class="apiplatform-input" type="text" name="api_name" value="' . esc_attr($api['name'] ?? '') . '" required>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-api-slug">API Slug</label>';
$content .= '<input id="apiplatform-api-slug" class="apiplatform-input" type="text" name="api_slug" value="' . esc_attr($api['slug'] ?? '') . '" required>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-api-description">Description</label>';
$content .= '<textarea id="apiplatform-api-description" class="apiplatform-input" name="description" rows="5">' . esc_textarea($api['description'] ?? '') . '</textarea>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-api-status">Status</label>';
$content .= '<select id="apiplatform-api-status" class="apiplatform-input" name="apiplatform_status">';
$content .= '<option value="active" ' . selected($status, 'active', false) . '>Active</option>';
$content .= '<option value="inactive" ' . selected($status, 'inactive', false) . '>Inactive</option>';
$content .= '<option value="archived" ' . selected($status, 'archived', false) . '>Archived</option>';
$content .= '</select>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label for="apiplatform-response-json">Response JSON</label>';
$content .= !empty($api['response_json_error'])
    ? '<div class="apiplatform-json-error">' . esc_html($api['response_json_error']) . '</div>'
    : '';
$content .= '<textarea id="apiplatform-response-json" class="apiplatform-input apiplatform-json-editor" name="response_json" rows="12" required>' . esc_textarea($api['response_json'] ?? '') . '</textarea>';
$content .= '</div>';

$content .= '<div class="apiplatform-form-row">';
$content .= '<label>Created Date</label>';
$content .= '<input class="apiplatform-input" type="text" value="' . esc_attr($api['created_date'] ?? '') . '" readonly>';
$content .= '</div>';

$content .= APIPlatform_Renderer::component(

    'button',

    [

        'type' => 'primary',

        'label' => 'Save Changes',

        'html_type' => 'submit'
    ]
);

$content .= '</form>';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'API Details',

        'class' => 'apiplatform-edit-api-card',

        'content' => $content
    ]
);
?>
