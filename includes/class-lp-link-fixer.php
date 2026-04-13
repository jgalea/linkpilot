<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Link_Fixer {

    public static function init() {
        if ( get_option( 'lp_enable_link_fixer', 'yes' ) !== 'yes' ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_lp_link_fixer', array( __CLASS__, 'ajax_handler' ) );
        add_action( 'wp_ajax_nopriv_lp_link_fixer', array( __CLASS__, 'ajax_handler' ) );
    }

    public static function enqueue_scripts() {
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_script(
            'lp-link-fixer',
            LP_PLUGIN_URL . 'assets/js/link-fixer.js',
            array(),
            LP_VERSION,
            true
        );

        $prefix = get_option( 'lp_link_prefix', 'go' );

        wp_localize_script( 'lp-link-fixer', 'lpLinkFixer', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'lp_link_fixer' ),
            'linkPrefix' => home_url( $prefix . '/' ),
            'homeUrl'    => home_url( '/' ),
        ) );
    }

    public static function ajax_handler() {
        check_ajax_referer( 'lp_link_fixer', 'nonce' );

        $urls = isset( $_POST['urls'] ) ? array_map( 'esc_url_raw', (array) $_POST['urls'] ) : array();

        if ( empty( $urls ) ) {
            wp_send_json_success( array() );
        }

        $results = array();
        foreach ( $urls as $url ) {
            $slug = self::extract_slug_from_url( $url );
            if ( ! $slug ) {
                continue;
            }

            $link = LP_Link::find_by_slug( $slug );
            if ( ! $link ) {
                continue;
            }

            $results[ $url ] = array(
                'href'   => $link->get_cloaked_url(),
                'rel'    => $link->get_rel_tags(),
                'target' => $link->opens_new_window() ? '_blank' : '',
                'class'  => $link->get_css_classes(),
            );
        }

        wp_send_json_success( $results );
    }

    private static function extract_slug_from_url( $url ) {
        $parsed = wp_parse_url( $url );
        $path   = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
        $prefix = get_option( 'lp_link_prefix', 'go' );

        $home_path = trim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
        if ( $home_path ) {
            $path = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '/#', '', $path );
        }

        if ( preg_match( '#^' . preg_quote( $prefix, '#' ) . '/(.+)$#', $path, $matches ) ) {
            return sanitize_title( $matches[1] );
        }

        return null;
    }
}
