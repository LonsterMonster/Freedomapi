<?php

$headers = $headers ?? [];
$rows    = $rows ?? [];
$class   = $class ?? '';

?>

<div class="apiplatform-table-wrapper">

    <table class="apiplatform-table <?php echo esc_attr($class); ?>">

        <thead>

            <tr>

                <?php foreach($headers as $header): ?>

                    <th>

                        <?php
                        echo esc_html($header);
                        ?>

                    </th>

                <?php endforeach; ?>

            </tr>

        </thead>

        <tbody>

            <?php if(empty($rows)): ?>

                <tr>

                    <td colspan="<?php echo max(1, count($headers)); ?>">

                        No data found.

                    </td>

                </tr>

            <?php else: ?>

                <?php foreach($rows as $row): ?>

                    <tr>

                        <?php foreach($row as $cell): ?>

                            <td>

                                <?php
                                echo wp_kses_post($cell);
                                ?>

                            </td>

                        <?php endforeach; ?>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

        </tbody>

    </table>

</div>
