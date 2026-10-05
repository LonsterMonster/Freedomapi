<form method="post">

    <?php
    wp_nonce_field(
        'apiplatform_keys',
        'apiplatform_keys_nonce'
    );
    ?>

    <input
        type="hidden"
        name="api_id"
        value="<?php echo $api->ID; ?>"
    >

    <input
        type="text"
        name="key_name"
        placeholder="Key Name"
    >

    <button
        type="submit"
        name="create_key"
        class="button"
    >
        Create Key
    </button>

</form>