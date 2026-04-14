<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_GA4 {

    public static function init() {
        add_action( 'lp_after_click', array( __CLASS__, 'send_event' ), 10, 2 );
    }

    public static function send_event( $link, $destination ) {
        $measurement_id = get_option( 'lpp_ga4_measurement_id', '' );
        $api_secret     = get_option( 'lpp_ga4_api_secret', '' );

        if ( ! $measurement_id || ! $api_secret ) {
            return;
        }

        $fingerprint = ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ) .
                       ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '' );
        $client_id   = substr( hash( 'sha256', $fingerprint . wp_salt() ), 0, 16 ) . '.' . substr( hash( 'sha256', $fingerprint . 'b' ), 0, 16 );

        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode( $measurement_id ) .
               '&api_secret=' . rawurlencode( $api_secret );

        $payload = array(
            'client_id' => $client_id,
            'events'    => array(
                array(
                    'name'   => 'linkpilot_click',
                    'params' => array(
                        'link_id'     => $link->get_id(),
                        'link_title'  => $link->get_title(),
                        'link_slug'   => $link->get_slug(),
                        'destination' => $destination,
                    ),
                ),
            ),
        );

        wp_remote_post( $url, array(
            'timeout'  => 2,
            'blocking' => false,
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'body'     => wp_json_encode( $payload ),
        ) );
    }
}
