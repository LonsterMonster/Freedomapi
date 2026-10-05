<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Automations {

    public function __construct(){

        add_shortcode(
            'api_automations_page',
            [$this,'render']
        );

        add_action(
            'init',
            [$this,'handle']
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Handle Actions
    |--------------------------------------------------------------------------
    */

    public function handle(){

        if (
            !current_user_can(
                'manage_options'
            )
        ){
            return;
        }

        global $wpdb;

        $table =
            $wpdb->prefix .
            'apiplatform_rules';

        /*
        |--------------------------------------------------------------------------
        | Save Rule
        |--------------------------------------------------------------------------
        */

        if (
            isset($_POST['save_rule']) &&
            isset($_POST['apiplatform_nonce'])
        ){

            if (
                !wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST['apiplatform_nonce'])),
                    'apiplatform_rules'
                )
            ){
                return;
            }

            $wpdb->insert(

                $table,

                [

                    'name' => sanitize_text_field(
                        wp_unslash($_POST['name'])
                    ),

                    'condition_key' =>
                        sanitize_text_field(
                            wp_unslash($_POST['condition_key'])
                        ),

                    'condition_value' =>
                        sanitize_text_field(
                            wp_unslash($_POST['condition_value'])
                        ),

                    'action_type' =>
                        sanitize_text_field(
                            wp_unslash($_POST['action_type'])
                        ),

                    'action_data' =>
                        sanitize_textarea_field(
                            wp_unslash($_POST['action_data'])
                        ),

                    'created_at' =>
                        current_time('mysql')
                ]
            );

            wp_safe_redirect(
                remove_query_arg(
                    ['delete_rule']
                )
            );

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Delete Rule
        |--------------------------------------------------------------------------
        */

        if (isset($_GET['delete_rule'])){

            $id =
                intval(
                    $_GET['delete_rule']
                );

            $nonce = isset($_GET['_wpnonce'])
                ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
                : '';

            if (!$id || !wp_verify_nonce($nonce, 'apiplatform_delete_rule_' . $id)){
                return;
            }

            $wpdb->delete(

                $table,

                [
                    'id' => $id
                ]
            );

            wp_safe_redirect(
                remove_query_arg(
                    ['delete_rule']
                )
            );

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | Manual Campaign
        |--------------------------------------------------------------------------
        */

        if (
            isset($_POST['send_campaign']) &&
            isset($_POST['apiplatform_nonce'])
        ){

            if (
                !wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST['apiplatform_nonce'])),
                    'apiplatform_rules'
                )
            ){
                return;
            }

            $message =
                sanitize_textarea_field(
                    wp_unslash($_POST['campaign_message'])
                );

            $channels =
                isset($_POST['channels']) &&
                is_array($_POST['channels'])
                    ? array_map(
                        'sanitize_text_field',
                        wp_unslash($_POST['channels'])
                    )
                    : ['email'];

            $users = get_users();

            foreach($users as $u){

                if (
                    function_exists(
                        'apiplatform_notify'
                    )
                ){

                    apiplatform_notify(

                        $u->ID,

                        $message,

                        $channels
                    );
                }
            }

            wp_safe_redirect(
                remove_query_arg(
                    ['delete_rule']
                )
            );

            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Render Page
    |--------------------------------------------------------------------------
    */

    public function render(){

        if (
            !current_user_can(
                'manage_options'
            )
        ){
            return 'Access denied';
        }

        global $wpdb;

        $table =
            $wpdb->prefix .
            'apiplatform_rules';

        $rules =
            $wpdb->get_results(
                "SELECT * FROM $table ORDER BY id DESC"
            );

        ob_start();
        ?>

        <div class="apiplatform-profile">

            <h1>🤖 Automations</h1>

            <!-- CREATE RULE -->
            <div class="apiplatform-card">

                <h3>Create Automation</h3>

                <form method="post">

                    <?php
                    wp_nonce_field(
                        'apiplatform_rules',
                        'apiplatform_nonce'
                    );
                    ?>

                    <input
                        type="text"
                        name="name"
                        placeholder="Rule name"
                        required
                        style="width:100%;"
                    >

                    <h4>Condition</h4>

                    <select name="condition_key">

                        <option value="credits_below">
                            Credits Below
                        </option>

                        <option value="status_code">
                            Status Code
                        </option>

                        <option value="response_time">
                            Slow Response
                        </option>

                    </select>

                    <input
                        type="number"
                        name="condition_value"
                        placeholder="Value"
                    >

                    <h4>Action</h4>

                    <select name="action_type">

                        <option value="notify">
                            Send Notification
                        </option>

                        <option value="alert">
                            Create Alert
                        </option>

                    </select>

                    <textarea
                        name="action_data"
                        placeholder="Message"
                        style="width:100%;height:60px;"
                    ></textarea>

                    <br><br>

                    <button
                        name="save_rule"
                        class="button button-primary"
                    >
                        Save Rule
                    </button>

                </form>

            </div>

            <!-- RULES -->
            <div class="apiplatform-card">

                <h3>📋 Existing Automations</h3>

                <?php if ($rules): ?>

                    <?php foreach($rules as $r): ?>

                        <div style="
                            padding:10px;
                            border-bottom:1px solid #333;
                        ">

                            <strong>
                                <?php
                                echo esc_html($r->name);
                                ?>
                            </strong>

                            <br>

                            IF

                            <code>
                                <?php
                                echo esc_html(
                                    $r->condition_key
                                );
                                ?>
                            </code>

                            =

                            <code>
                                <?php
                                echo esc_html(
                                    $r->condition_value
                                );
                                ?>
                            </code>

                            →

                            THEN

                            <strong>
                                <?php
                                echo esc_html(
                                    $r->action_type
                                );
                                ?>
                            </strong>

                            <br>

                            <small>
                                <?php
                                echo esc_html(
                                    $r->action_data
                                );
                                ?>
                            </small>

                            <br><br>

                            <a

                                href="<?php echo esc_url(
                                    wp_nonce_url(
                                        add_query_arg(
                                            [
                                                'apipage' =>
                                                    'automations',

                                                'delete_rule' =>
                                                    $r->id
                                            ]
                                        ),
                                        'apiplatform_delete_rule_' . absint($r->id)
                                    )
                                ); ?>"

                                style="color:red;"
                            >
                                Delete
                            </a>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>
                        No automations yet.
                    </p>

                <?php endif; ?>

            </div>

        </div>

        <?php

        return ob_get_clean();
    }
}
