<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Clicks {

    public static function record( LP_Link $link ) {
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $is_bot     = LP_Bot_Detector::is_bot( $user_agent );
        $referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '';

        if ( strlen( $referrer ) > 2048 ) {
            $referrer = substr( $referrer, 0, 2048 );
        }

        $ua_hash = $user_agent ? md5( $user_agent ) : '';
        $country_code = self::detect_country();

        LP_Clicks_DB::insert( array(
            'link_id'         => $link->get_id(),
            'clicked_at'      => current_time( 'mysql', true ),
            'referrer'        => $referrer,
            'country_code'    => $country_code,
            'user_agent_hash' => $ua_hash,
            'is_bot'          => $is_bot ? 1 : 0,
        ) );
    }

    private static function detect_country() {
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $code = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
            if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code && 'T1' !== $code ) {
                return $code;
            }
        }

        if ( function_exists( 'geoip_country_code_by_name' ) ) {
            $ip = self::get_client_ip();
            if ( $ip ) {
                $code = @geoip_country_code_by_name( $ip );
                if ( $code ) {
                    return $code;
                }
            }
        }

        return (string) apply_filters( 'lp_detect_country', '', self::get_client_ip() );
    }

    private static function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = $_SERVER[ $header ];
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }

        return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    }
}
