<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
        if ( class_exists( 'LP_Expiration' ) ) {
            LP_Expiration::unschedule();
        }
        if ( class_exists( 'LP_404_Log' ) ) {
            LP_404_Log::unschedule();
        }
        if ( class_exists( 'LP_Digest' ) ) {
            LP_Digest::unschedule();
        }
    }
}
