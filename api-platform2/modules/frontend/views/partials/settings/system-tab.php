<div class="tab-content active" id="tab-system">

    <label>

        <input
            type="checkbox"
            name="use_gamipress"
            value="1"

            <?php checked(
                get_option(
                    'apiplatform_use_gamipress'
                ),
                1
            ); ?>
        >

        Use GamiPress (Credits)

    </label>

    <br><br>

    <label>

        <input
            type="checkbox"
            name="disable_platform"
            value="1"

            <?php checked(
                get_option(
                    'apiplatform_disabled'
                ),
                1
            ); ?>
        >

        Disable Platform

    </label>

</div>