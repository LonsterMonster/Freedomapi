<div class="apiplatform-card">

    <h3>Recent Requests</h3>

    <?php if(empty($db_logs)): ?>

        <?php
        include __DIR__ .
            '/no-logs.php';
        ?>

    <?php else: ?>

        <table class="apiplatform-table">

            <tr>
                <th>Time</th>
                <th>API</th>
                <th>Status</th>
                <th>IP</th>
                <th>Time (s)</th>
            </tr>

            <?php foreach($db_logs as $log): ?>

            <tr>

                <td>
                    <?php echo esc_html(
                        $log->created_at
                    ); ?>
                </td>

                <td>
                    <?php echo esc_html(
                        $log->endpoint
                    ); ?>
                </td>

                <td>
                    <?php echo esc_html(
                        $log->status
                    ); ?>
                </td>

                <td>
                    <?php echo esc_html(
                        $log->ip
                    ); ?>
                </td>

                <td>
                    <?php echo esc_html(
                        $log->response_time
                    ); ?>
                </td>

            </tr>

            <?php endforeach; ?>

        </table>

    <?php endif; ?>

</div>