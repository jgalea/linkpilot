<?php
// includes/class-lp-redirect.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Redirect {

    public static function maybe_redirect_prefixed() {
        if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
            return;
        }

        $request_uri = self::get_request_path();
        $prefix      = get_option( 'lp_link_prefix', 'go' );

        if ( ! preg_match( '#^/' . preg_quote( $prefix, '#' ) . '/([^/\?]+)/?$#i', $request_uri, $matches ) ) {
            return;
        }

        $slug = sanitize_title( $matches[1] );
        $link = LP_Link::find_by_slug( $slug );

        if ( ! $link ) {
            return;
        }

        self::do_redirect( $link );
    }

    public static function maybe_redirect_fallback() {
        if ( ! is_404() ) {
            return;
        }

        $request_uri = self::get_request_path();

        if ( ! preg_match( '#^/([^/\?]+)/?$#', $request_uri, $matches ) ) {
            return;
        }

        $slug = sanitize_title( $matches[1] );
        $link = LP_Link::find_by_slug( $slug );

        if ( ! $link ) {
            return;
        }

        self::do_redirect( $link );
    }

    private static function do_redirect( LP_Link $link ) {
        $query_string = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '';
        $destination  = $link->get_final_destination_url( $query_string );

        if ( ! $destination ) {
            return;
        }

        if ( get_option( 'lp_enable_click_tracking', 'yes' ) === 'yes' ) {
            try {
                LP_Clicks::record( $link );
            } catch ( \Throwable $e ) {
                // Silently fail — redirect must always work
            }
        }

        header( 'X-Robots-Tag: noindex, nofollow', true );
        nocache_headers();

        $redirect_type = $link->get_redirect_type();
        wp_redirect( $destination, $redirect_type );
        exit;
    }

    private static function get_request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        $parsed      = wp_parse_url( $request_uri );
        $path        = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        $home_path = wp_parse_url( home_url(), PHP_URL_PATH );
        if ( $home_path && $home_path !== '/' ) {
            $path = substr( $path, strlen( $home_path ) );
            if ( $path === false ) {
                $path = '/';
            }
        }

        return $path;
    }
}
