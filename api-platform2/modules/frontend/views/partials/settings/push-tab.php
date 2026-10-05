<div class="tab-content" id="tab-push">

    <h4>Provider</h4>

    <select
        name="push_provider"
        id="pushProvider"
    >

        <option
            value="internal"

            <?php selected(
                get_option(
                    'apiplatform_push_provider'
                ),
                'internal'
            ); ?>
        >
            Internal
        </option>

        <option
            value="firebase"

            <?php selected(
                get_option(
                    'apiplatform_push_provider'
                ),
                'firebase'
            ); ?>
        >
            Firebase
        </option>

        <option
            value="pusher"

            <?php selected(
                get_option(
                    'apiplatform_push_provider'
                ),
                'pusher'
            ); ?>
        >
            Pusher
        </option>

    </select>

    <?php

    $provider = get_option(
        'apiplatform_push_provider',
        'internal'
    );

    $status = 'status-warning';

    $text = 'Not configured';

    if ($provider === 'internal') {

        $status = 'status-ok';

        $text = 'Ready';
    }

    elseif (
        $provider === 'firebase' &&
        apiplatform_is_firebase_ready()
    ){

        $status = 'status-ok';

        $text = 'Connected';
    }

    elseif (
        $provider === 'pusher' &&
        apiplatform_is_pusher_ready()
    ){

        $status = 'status-ok';

        $text = 'Connected';
    }

    ?>

    <p>

        Status →

        <strong class="<?php echo esc_attr($status); ?>">

            <?php echo esc_html($text); ?>

        </strong>

    </p>

</div>