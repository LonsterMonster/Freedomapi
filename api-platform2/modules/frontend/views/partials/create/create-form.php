<?php

$form = $form ?? [];
$owner_options = $form['owner_options'] ?? [];
$selected_owner = ($form['owner_type'] ?? 'personal') . ':' . absint($form['owner_id'] ?? 0);
$owner_select = '';
if (count($owner_options) > 1) {
    $owner_select .= '<div class="apiplatform-form-row"><label for="apiplatform_api_owner">Owner</label><select id="apiplatform_api_owner" class="apiplatform-input" name="owner_context">';
    foreach ($owner_options as $option) {
        $value = sanitize_key($option['owner_type']) . ':' . absint($option['owner_id']);
        $owner_select .= '<option value="' . esc_attr($value) . '"' . selected($selected_owner, $value, false) . '>' . esc_html($option['label']) . '</option>';
    }
    $owner_select .= '</select><input type="hidden" name="owner_type" value="' . esc_attr($form['owner_type'] ?? 'personal') . '"><input type="hidden" name="owner_id" value="' . esc_attr($form['owner_id'] ?? 0) . '"><p class="apiplatform-helper-text">Choose Personal or an organization where you have API creation permission.</p></div>';
} else {
    $only = $owner_options[0] ?? ['owner_type' => 'personal', 'owner_id' => get_current_user_id(), 'label' => 'Personal Workspace'];
    $owner_select .= '<input type="hidden" name="owner_type" value="' . esc_attr($only['owner_type']) . '"><input type="hidden" name="owner_id" value="' . esc_attr($only['owner_id']) . '">';
}

$content = '

<form
    method="post"
    class="apiplatform-create-api-form apiplatform-create-api-wizard"
>

    ' . wp_nonce_field(

        'apiplatform_create_api',

        'apiplatform_create_api_nonce',

        true,

        false
    ) . $owner_select . '

    <ol class="apiplatform-create-wizard-steps" aria-label="Create API steps">
        <li><span>1</span>Basic Information</li>
        <li><span>2</span>Authentication</li>
        <li><span>3</span>Endpoint</li>
        <li><span>4</span>Documentation</li>
        <li><span>5</span>Review</li>
    </ol>

    <section class="apiplatform-create-wizard-step">
        <h3>Step 1: Basic Information</h3>
        <p>Define the owner-facing API identity. Visibility defaults to private publishing settings after creation.</p>

    <div class="apiplatform-form-row">

        <label for="apiplatform_api_name">
            Title
        </label>

        <input
            id="apiplatform_api_name"
            name="api_name"
            type="text"
            value="' . esc_attr($form['api_name'] ?? '') . '"
            placeholder="Example API"
            class="apiplatform-input"
            data-apiplatform-endpoint-source
            required
        >

    </div>

    <div class="apiplatform-form-row">

        <label for="apiplatform_api_slug">
            API Slug
        </label>

        <input
            id="apiplatform_api_slug"
            name="api_slug"
            type="text"
            value="' . esc_attr($form['api_slug'] ?? '') . '"
            placeholder="example-api"
            class="apiplatform-input"
            data-apiplatform-endpoint-target
        >

    </div>

    <div class="apiplatform-form-row">

        <label for="apiplatform_api_description">
            Summary
        </label>

        <textarea
            id="apiplatform_api_description"
            name="description"
            rows="5"
            class="apiplatform-input"
        >' . esc_textarea($form['description'] ?? '') . '</textarea>

    </div>

    <div class="apiplatform-create-wizard-note">
        Category, version, and public visibility are managed from Developer Portal Publishing after the API exists.
    </div>
    </section>

    <section class="apiplatform-create-wizard-step">
        <h3>Step 2: Authentication</h3>
        <p>FreedomAPI will generate a hashed API key and show the plaintext secret once after creation.</p>
        <div class="apiplatform-create-wizard-options">
            <span class="is-selected">API Key</span>
            <span>Public planned</span>
            <span>OAuth future</span>
            <span>JWT future</span>
        </div>
    </section>

    <section class="apiplatform-create-wizard-step">
        <h3>Step 3: Endpoint</h3>
        <p>The first endpoint is created from the slug and returns the JSON response below. Advanced methods, headers, parameters, and rate limits are managed after creation.</p>

    <div class="apiplatform-form-row">

        <label for="apiplatform_response_json">
            Response Type: JSON
        </label>

        ' . (!empty($form['response_json_error'])
            ? '<div class="apiplatform-json-error">' . esc_html($form['response_json_error']) . '</div>'
            : '') . '

        <textarea
            id="apiplatform_response_json"
            name="response_json"
            rows="8"
            class="apiplatform-input apiplatform-json-editor"
            required
        >' . esc_textarea($form['response_json'] ?? '') . '</textarea>

    </div>

    </section>

    <section class="apiplatform-create-wizard-step">
        <h3>Step 4: Documentation</h3>
        <p>Overview, examples, errors, and Quick Start content reuse the existing documentation editor after this API is created.</p>
    </section>

    <section class="apiplatform-create-wizard-step apiplatform-create-wizard-review">
        <h3>Step 5: Review</h3>
        <p>Validation runs before insertion. Duplicate slugs are blocked, and the successful request redirects before the success notice is displayed.</p>

    <p>

        <button
            type="submit"
            name="apiplatform_create_api"
            value="1"
            class="apiplatform-button apiplatform-button-primary"
            onclick="var s=this.form.owner_context;if(s){var v=s.value.split(\':\');this.form.owner_type.value=v[0];this.form.owner_id.value=v[1];}"
        >

            Generate API

        </button>

    </p>

    </section>

</form>
';

echo APIPlatform_Renderer::component(

    'card',

    [

        'title' => 'Create API Wizard',

        'content' => $content
    ]
);
?>
