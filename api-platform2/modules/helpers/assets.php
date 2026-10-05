<?php
if (!defined('ABSPATH')) exit;

/*
|--------------------------------------------------------------------------
| Legacy Asset Compatibility
|--------------------------------------------------------------------------
|
| Frontend CSS, JavaScript, Chart.js, dashboard scripts, and sidebar assets are
| registered and enqueued by APIPlatform_Frontend_Assets. This file only keeps
| legacy footer markup that is not yet owned by the frontend asset manager.
|
*/

add_action(
    'wp_footer',
    function(){

        if (!is_page('dashboard')) {
            return;
        }

        ?>

        <button
            class="apiplatform-mobile-toggle"
            type="button"
            aria-label="Toggle API Platform sidebar"
            onclick="toggleSidebar(this)"
        >
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
        </button>

        <?php
    }
);
