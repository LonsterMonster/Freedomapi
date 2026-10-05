<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Frontend_Billing {

    public function __construct(){

        add_shortcode(

            'api_billing_page',

            [$this, 'render']
        );
    }

    public function render(){

        /*
        |--------------------------------------------------------------------------
        | Assets
        |--------------------------------------------------------------------------
        */

        APIPlatform_Frontend_Assets::enqueue_common();

        /*
        |--------------------------------------------------------------------------
        | Auth Check
        |--------------------------------------------------------------------------
        */

        if (!is_user_logged_in()) {

            return APIPlatform_Renderer::component(

                'alert',

                [

                    'type' => 'error',

                    'content' => 'Login required'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | User
        |--------------------------------------------------------------------------
        */

        $user_id = get_current_user_id();

        if (user_can($user_id, 'manage_options')){

            $plan = $this->get_admin_access_plan();

            $payment_methods = [

                [

                    'gateway' => 'Admin Access',

                    'details' => 'Included',

                    'status' => 'Admin'
                ]
            ];

            $invoices = [];
        } else {

            /*
            |--------------------------------------------------------------------------
            | ARMember Data
            |--------------------------------------------------------------------------
            */

            $plan = $this->get_active_armember_plan(

                $user_id
            );

            $payment_methods = $this->get_armember_payment_methods(

                $user_id,

                $plan
            );

            $invoices = $this->get_armember_invoices(

                $user_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Debug
        |--------------------------------------------------------------------------
        */

        $this->debug_log(

            'BILLING PAGE PLAN: ' .

            ($plan['name'] ?? 'missing')
        );

        /*
        |--------------------------------------------------------------------------
        | Build Content
        |--------------------------------------------------------------------------
        */

        $content = '';

        /*
        |--------------------------------------------------------------------------
        | Current Plan
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'billing/current-plan',

            [

                'plan' => $plan
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Payment Methods
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'billing/payment-methods',

            [

                'payment_methods' => $payment_methods
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Invoices
        |--------------------------------------------------------------------------
        */

        $content .= APIPlatform_Renderer::partial(

            'billing/invoices',

            [

                'invoices' => $invoices
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Final Layout
        |--------------------------------------------------------------------------
        */

        return APIPlatform_Renderer::component(

            'section',

            [

                'content' => APIPlatform_Renderer::component(

                    'stack',

                    [

                        'items' => [

                            '<h1>Billing</h1>',

                            $content
                        ]
                    ]
                )
            ]
        );
    }

    private function get_active_armember_plan($user_id){

        if (!$this->armember_available()){

            $this->debug_log(

                'BILLING MISSING ARMEMBER INTEGRATION'
            );
        }

        $plan_ids = get_user_meta(

            $user_id,

            'arm_user_plan_ids',

            true
        );

        if (!is_array($plan_ids)){

            $plan_ids = [];
        }

        $plan_id = !empty($plan_ids)

            ? (int) reset($plan_ids)

            : 0;

        $slug = function_exists('apiplatform_get_user_plan')

            ? apiplatform_get_user_plan()

            : 'free';

        $status = function_exists('apiplatform_get_plan_status')

            ? apiplatform_get_plan_status($user_id)

            : ($plan_id ? 'active' : 'free');

        $plan = [

            'id' => $plan_id,

            'slug' => $slug,

            'name' => $this->format_plan_name($slug),

            'status' => $this->format_status($status)
        ];

        $plan_name = $this->get_armember_plan_name(

            $plan_id
        );

        if ($plan_name){

            $plan['name'] = $plan_name;
        }

        $plan_meta = $this->get_armember_plan_meta(

            $user_id,

            $plan_id
        );

        if (!empty($plan_meta['arm_expire_plan'])){

            $plan['renews_at'] = $plan_meta['arm_expire_plan'];
        }

        if (!empty($plan_meta['arm_next_due_payment'])){

            $plan['renews_at'] = $plan_meta['arm_next_due_payment'];
        }

        if (!$plan_id && $slug === 'free'){

            $this->debug_log(

                'BILLING MISSING ACTIVE ARMEMBER PLAN FOR USER: ' .

                $user_id
            );
        }

        return $plan;
    }

    private function get_admin_access_plan(){

        return [

            'id' => 0,

            'slug' => 'admin',

            'name' => 'Admin Access',

            'status' => 'Admin',

            'requests' => 'Unlimited',

            'apis' => 'Unlimited',

            'action' => 'Included'
        ];
    }

    private function get_armember_payment_methods($user_id, $plan){

        $methods = [];

        $plan_meta = $this->get_armember_plan_meta(

            $user_id,

            (int) ($plan['id'] ?? 0)
        );

        if (!empty($plan_meta['arm_user_gateway'])){

            $methods[] = [

                'gateway' => $this->format_gateway_name(

                    $plan_meta['arm_user_gateway']
                ),

                'details' => 'Active membership gateway',

                'status' => 'Active'
            ];
        }

        $user_meta_method = $this->get_user_meta_payment_method(

            $user_id
        );

        if (!empty($user_meta_method)){

            $methods[] = $user_meta_method;
        }

        $latest_payment = $this->get_latest_armember_payment(

            $user_id
        );

        if (!empty($latest_payment['gateway'])){

            $methods[] = [

                'gateway' => $this->format_gateway_name(

                    $latest_payment['gateway']
                ),

                'details' => $latest_payment['payer_email']

                    ? $latest_payment['payer_email']

                    : (

                        $latest_payment['transaction_id']

                            ? 'Transaction ' . $latest_payment['transaction_id']

                            : 'Latest payment gateway'
                    ),

                'status' => $latest_payment['status'] ?: 'Available'
            ];
        }

        $methods = $this->unique_payment_methods(

            $methods
        );

        if (empty($methods)){

            $this->debug_log(

                'BILLING PAYMENT METHOD LOADING MISSING DATA FOR USER: ' .

                $user_id
            );
        } else {

            $this->debug_log(

                'BILLING PAYMENT METHODS LOADED: ' .

                count($methods)
            );
        }

        return $methods;
    }

    private function get_armember_invoices($user_id){

        $payments = $this->get_armember_payment_rows(

            $user_id,

            20
        );

        if (empty($payments)){

            $this->debug_log(

                'BILLING INVOICE LOADING MISSING DATA FOR USER: ' .

                $user_id
            );

            return [];
        }

        $invoices = [];

        foreach($payments as $payment){

            $invoices[] = [

                'date' => $payment['date'],

                'amount' => $this->format_amount(

                    $payment['amount'],

                    $payment['currency']
                ),

                'status' => $payment['status'],

                'invoice_id' => $payment['invoice_id']
            ];
        }

        $this->debug_log(

            'BILLING INVOICES LOADED: ' .

            count($invoices)
        );

        return $invoices;
    }

    private function get_armember_plan_name($plan_id){

        if (!$plan_id){

            return '';
        }

        global $wpdb;

        $table = $wpdb->prefix . 'arm_subscription_plans';

        if (!$this->table_exists($table)){

            return '';
        }

        $columns = $this->get_table_columns($table);

        $id_column = $this->first_existing_column(

            $columns,

            ['arm_subscription_plan_id', 'arm_plan_id', 'id']
        );

        $name_column = $this->first_existing_column(

            $columns,

            ['arm_subscription_plan_name', 'arm_plan_name', 'name']
        );

        if (!$id_column || !$name_column){

            return '';
        }

        return (string) $wpdb->get_var(

            $wpdb->prepare(

                "SELECT `{$name_column}` FROM `{$table}` WHERE `{$id_column}` = %d LIMIT 1",

                $plan_id
            )
        );
    }

    private function get_armember_plan_meta($user_id, $plan_id){

        if (!$plan_id){

            return [];
        }

        $meta = get_user_meta(

            $user_id,

            'arm_user_plan_' . $plan_id,

            true
        );

        if (is_array($meta)){

            return $meta;
        }

        return [];
    }

    private function get_latest_armember_payment($user_id){

        $rows = $this->get_armember_payment_rows(

            $user_id,

            1
        );

        return $rows[0] ?? [];
    }

    private function get_user_meta_payment_method($user_id){

        $gateway = '';

        foreach([

            'arm_user_gateway',

            'arm_payment_gateway',

            'arm_selected_payment_gateway'
        ] as $key){

            $value = get_user_meta(

                $user_id,

                $key,

                true
            );

            if ($value){

                $gateway = $value;

                break;
            }
        }

        if (!$gateway){

            return [];
        }

        $customer_id = '';

        foreach([

            'arm_stripe_customer_id',

            'arm_authorize_net_customer_id',

            'arm_paypal_subscriber_id'
        ] as $key){

            $value = get_user_meta(

                $user_id,

                $key,

                true
            );

            if ($value){

                $customer_id = $value;

                break;
            }
        }

        return [

            'gateway' => $this->format_gateway_name($gateway),

            'details' => $customer_id

                ? 'Customer ' . $customer_id

                : 'Saved membership gateway',

            'status' => 'Available'
        ];
    }

    private function get_armember_payment_rows($user_id, $limit = 20){

        global $wpdb;

        $table = $this->first_existing_table([

            $wpdb->prefix . 'arm_payment_log',

            $wpdb->prefix . 'arm_payment_logs',

            $wpdb->prefix . 'arm_transactions'
        ]);

        if (!$table){

            $this->debug_log(

                'BILLING MISSING ARMEMBER PAYMENT LOG TABLE'
            );

            return [];
        }

        $columns = $this->get_table_columns($table);

        $user_column = $this->first_existing_column(

            $columns,

            ['arm_user_id', 'user_id']
        );

        if (!$user_column){

            $this->debug_log(

                'BILLING MISSING ARMEMBER PAYMENT USER COLUMN'
            );

            return [];
        }

        $date_column = $this->first_existing_column(

            $columns,

            ['arm_created_date', 'arm_payment_date', 'arm_transaction_date', 'created_date', 'created_at', 'payment_date']
        );

        $amount_column = $this->first_existing_column(

            $columns,

            ['arm_paid_amount', 'arm_amount', 'arm_plan_amount', 'arm_transaction_amount', 'paid_amount', 'amount']
        );

        $status_column = $this->first_existing_column(

            $columns,

            ['arm_payment_status', 'arm_transaction_status', 'arm_status', 'payment_status', 'status']
        );

        $invoice_column = $this->first_existing_column(

            $columns,

            ['arm_invoice_id', 'arm_transaction_id', 'arm_transaction_reference', 'transaction_id', 'id', 'arm_log_id']
        );

        $gateway_column = $this->first_existing_column(

            $columns,

            ['arm_payment_gateway', 'arm_gateway', 'payment_gateway', 'gateway']
        );

        $currency_column = $this->first_existing_column(

            $columns,

            ['arm_currency', 'currency']
        );

        $payer_email_column = $this->first_existing_column(

            $columns,

            ['arm_payer_email', 'payer_email', 'email']
        );

        if (!$date_column || !$amount_column || !$status_column || !$invoice_column){

            $this->debug_log(

                'BILLING MISSING ARMEMBER INVOICE COLUMNS'
            );
        }

        $select_columns = [

            $date_column,

            $amount_column,

            $status_column,

            $invoice_column,

            $gateway_column,

            $currency_column,

            $payer_email_column
        ];

        $select_columns = array_values(

            array_filter(

                array_unique($select_columns)
            )
        );

        if (empty($select_columns)){

            return [];
        }

        $select_sql = implode(

            ', ',

            array_map(

                function($column){

                    return '`' . $column . '`';
                },

                $select_columns
            )
        );

        $order_column = $date_column ?: (

            $invoice_column ?: $user_column
        );

        $results = $wpdb->get_results(

            $wpdb->prepare(

                "SELECT {$select_sql} FROM `{$table}` WHERE `{$user_column}` = %d ORDER BY `{$order_column}` DESC LIMIT %d",

                $user_id,

                (int) $limit
            ),

            ARRAY_A
        );

        if (empty($results)){

            return [];
        }

        $rows = [];

        foreach($results as $row){

            $rows[] = [

                'date' => $date_column && isset($row[$date_column])

                    ? $row[$date_column]

                    : '',

                'amount' => $amount_column && isset($row[$amount_column])

                    ? $row[$amount_column]

                    : '',

                'status' => $status_column && isset($row[$status_column])

                    ? $row[$status_column]

                    : '',

                'invoice_id' => $invoice_column && isset($row[$invoice_column])

                    ? $row[$invoice_column]

                    : '',

                'gateway' => $gateway_column && isset($row[$gateway_column])

                    ? $row[$gateway_column]

                    : '',

                'transaction_id' => $invoice_column && isset($row[$invoice_column])

                    ? $row[$invoice_column]

                    : '',

                'currency' => $currency_column && isset($row[$currency_column])

                    ? $row[$currency_column]

                    : '',

                'payer_email' => $payer_email_column && isset($row[$payer_email_column])

                    ? $row[$payer_email_column]

                    : ''
            ];
        }

        return $rows;
    }

    private function armember_available(){

        return (

            shortcode_exists('arm_setup') ||

            shortcode_exists('arm_member_plan') ||

            defined('MEMBERSHIP_DIR_NAME') ||

            class_exists('ARM_membership')
        );
    }

    private function table_exists($table){

        global $wpdb;

        return $wpdb->get_var(

            $wpdb->prepare(

                'SHOW TABLES LIKE %s',

                $table
            )
        ) === $table;
    }

    private function first_existing_table($tables){

        foreach($tables as $table){

            if ($this->table_exists($table)){

                return $table;
            }
        }

        return '';
    }

    private function get_table_columns($table){

        global $wpdb;

        $columns = $wpdb->get_col(

            "DESC `{$table}`",

            0
        );

        return is_array($columns)

            ? $columns

            : [];
    }

    private function first_existing_column($columns, $candidates){

        foreach($candidates as $candidate){

            if (in_array($candidate, $columns, true)){

                return $candidate;
            }
        }

        return '';
    }

    private function unique_payment_methods($methods){

        $seen = [];

        $unique = [];

        foreach($methods as $method){

            $key = strtolower(

                $method['gateway'] ?? ''
            );

            if (!$key || isset($seen[$key])){

                continue;
            }

            $seen[$key] = true;

            $unique[] = $method;
        }

        return $unique;
    }

    private function format_plan_name($slug){

        if ($slug === 'admin'){

            return 'Admin Access';
        }

        return ucwords(

            str_replace(

                ['-', '_'],

                ' ',

                (string) $slug
            )
        ) . ' Plan';
    }

    private function format_gateway_name($gateway){

        return ucwords(

            str_replace(

                ['-', '_'],

                ' ',

                (string) $gateway
            )
        );
    }

    private function format_status($status){

        return ucwords(

            str_replace(

                ['-', '_'],

                ' ',

                (string) $status
            )
        );
    }

    private function format_amount($amount, $currency = ''){

        if ($amount === ''){

            return '';
        }

        $formatted = is_numeric($amount)

            ? number_format((float) $amount, 2)

            : (string) $amount;

        return trim(

            ($currency ? strtoupper((string) $currency) . ' ' : '') .

            $formatted
        );
    }

    private function debug_log($message){

        if (

            defined('WP_DEBUG') &&

            WP_DEBUG
        ){

            error_log($message);
        }
    }
}
