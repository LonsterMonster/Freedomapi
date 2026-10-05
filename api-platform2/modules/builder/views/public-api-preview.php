<?php
if (!defined('ABSPATH')) exit;

$response_data = isset($response_data) && is_array($response_data) ? $response_data : [];
?>
<pre class="apiplatform-api-preview"><?php echo esc_html(wp_json_encode($response_data, JSON_PRETTY_PRINT)); ?></pre>
