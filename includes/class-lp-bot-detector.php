<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Bot_Detector {

    public static function is_bot( $user_agent = '' ) {
        if ( empty( $user_agent ) ) {
            $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        }

        if ( empty( $user_agent ) ) {
            return true;
        }

        $bot_list = get_option( 'lp_excluded_bots', '' );
        if ( empty( $bot_list ) ) {
            return false;
        }

        $bots = array_filter( array_map( 'trim', explode( "\n", strtolower( $bot_list ) ) ) );
        $ua   = strtolower( $user_agent );

        foreach ( $bots as $bot ) {
            if ( $bot && strpos( $ua, $bot ) !== false ) {
                return true;
            }
        }

        return false;
    }
}
