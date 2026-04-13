<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap lp-settings-wrap">
    <h1><?php esc_html_e( 'LinkPilot Settings', 'linkpilot' ); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'lp_settings_group' );
        do_settings_sections( 'lp-settings' );
        submit_button();
        ?>
    </form>
</div>
