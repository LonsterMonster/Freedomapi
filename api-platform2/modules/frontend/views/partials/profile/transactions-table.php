<div class="apiplatform-card">

    <h3>Transactions</h3>

    <table class="apiplatform-table">

        <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Amount</th>
        </tr>

        <?php foreach($transactions as $t): ?>

        <tr>

            <td>
                <?php echo esc_html(
                    $t->created_at
                ); ?>
            </td>

            <td>
                <?php echo esc_html(
                    $t->type
                ); ?>
            </td>

            <td>
                <?php echo esc_html(
                    $t->amount
                ); ?>
            </td>

        </tr>

        <?php endforeach; ?>

    </table>

</div>