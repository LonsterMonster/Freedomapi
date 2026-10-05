<?php
if (!defined('ABSPATH')) exit;
?>
<div class="apiplatform-dashboard">
    <h2 class="apiplatform-page-title">Dashboard</h2>

    <div class="apiplatform-grid">
        <div class="apiplatform-card">
            <h3>Total Requests</h3>
            <p class="apiplatform-metric-value"><?php echo esc_html(number_format_i18n($metrics['total_requests'] ?? 0)); ?></p>
        </div>

        <div class="apiplatform-card">
            <h3>Your APIs</h3>
            <p class="apiplatform-metric-value"><?php echo esc_html(number_format_i18n($metrics['api_count'] ?? 0)); ?></p>
        </div>
    </div>

    <div class="apiplatform-card" style="height: 300px;">
        <h3>Last 7 Days Usage</h3>
        <canvas id="apiplatformUsageChart" height="120"></canvas>
    </div>

    <div class="apiplatform-card">
        <h3>Test API</h3>

        <form id="apiTestForm">
            <div class="apiplatform-form-row">
                <label for="endpoint">Endpoint</label>
                <input type="text" id="endpoint" placeholder="Endpoint (e.g. api2)" required>
            </div>

            <div class="apiplatform-form-row">
                <label for="api_key">API Key</label>
                <input type="text" id="api_key" placeholder="API Key" required>
            </div>

            <div class="apiplatform-form-row">
                <label for="params">Query Parameters</label>
                <input type="text" id="params" placeholder="name=John">
            </div>

            <button type="submit">Run Test</button>
        </form>

        <h4>Response</h4>
        <pre id="apiResponse"></pre>
        <button id="copyResponse" type="button">Copy Response</button>
    </div>

    <div class="apiplatform-card">
        <h3>API Docs</h3>
        <code><?php echo esc_html($docs_url); ?></code>
    </div>

    <div class="apiplatform-card">
        <h3>Recent Requests</h3>

        <?php if (empty($history)): ?>
            <p>No requests yet.</p>
        <?php else: ?>
            <table class="apiplatform-table">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Speed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row->endpoint); ?></td>
                            <td>
                                <span class="status-<?php echo ((int) $row->status === 200) ? 'ok' : 'fail'; ?>">
                                    <?php echo esc_html($row->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->response_time); ?>s</td>
                            <td>
                                <button type="button" onclick="replayRequest('<?php echo esc_js($row->endpoint); ?>')">Replay</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
