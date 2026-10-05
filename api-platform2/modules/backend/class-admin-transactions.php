<?php

if (!defined('ABSPATH')) exit;

class APIPlatform_Admin_Transactions {

    public function page(){

        if (!current_user_can('manage_options')) return;

        if (!class_exists('APIPlatform_Billing_Service')) {
            echo '<div class="wrap"><h1>Transactions</h1><p>Billing service is not loaded.</p></div>';
            return;
        }

        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $transactions = APIPlatform_Billing_Service::instance()->get_transactions($user_id, 100);

        echo '<div class="wrap"><h1>Transactions</h1>';

        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="apiplatform-transactions">';
        echo '<input type="number" min="1" name="user_id" placeholder="User ID" value="' . esc_attr($user_id ?: '') . '">';
        echo '<button class="button">Filter</button>';
        echo '</form>';

        if (empty($transactions)) {
            echo '<p>No transactions found.</p></div>';
            return;
        }

        echo '<table class="widefat"><tr>
        <th>User</th><th>API</th><th>Amount</th><th>Type</th><th>Description</th><th>Date</th></tr>';

        foreach($transactions as $t){
            echo "<tr>
            <td>".esc_html($t->user_id)."</td>
            <td>".esc_html($t->api_id)."</td>
            <td>".esc_html($t->amount)."</td>
            <td>".esc_html($t->type)."</td>
            <td>".esc_html($t->description)."</td>
            <td>".esc_html($t->created_at)."</td>
            </tr>";
        }

        echo '</table></div>';
    }
}
