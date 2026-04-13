<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
