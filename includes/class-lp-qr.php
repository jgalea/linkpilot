<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_QR {

    public static function init() {
        if ( get_option( 'lp_enable_qr', 'no' ) !== 'yes' ) {
            return;
        }
        add_action( 'admin_post_lp_qr_download', array( __CLASS__, 'handle_download' ) );
    }

    public static function is_enabled() {
        return get_option( 'lp_enable_qr', 'no' ) === 'yes';
    }

    public static function get_download_url( $post_id ) {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=lp_qr_download&link=' . (int) $post_id ),
            'lp_qr_download_' . $post_id
        );
    }

    public static function handle_download() {
        $post_id = isset( $_GET['link'] ) ? (int) $_GET['link'] : 0;
        if ( ! $post_id ) {
            wp_die( 'Invalid link' );
        }

        check_admin_referer( 'lp_qr_download_' . $post_id );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized' );
        }

        $link = new LP_Link( $post_id );
        $url  = $link->get_cloaked_url();

        if ( ! $url ) {
            wp_die( 'Link not found' );
        }

        $slug = $link->get_slug() ?: 'link';

        $api = 'https://api.qrserver.com/v1/create-qr-code/?size=512x512&data=' . rawurlencode( $url );
        $response = wp_remote_get( $api, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            wp_die( 'QR generation failed. Try again.' );
        }

        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: attachment; filename="linkpilot-qr-' . sanitize_file_name( $slug ) . '.png"' );
        header( 'Cache-Control: private, max-age=3600' );

        $body = wp_remote_retrieve_body( $response );
        // Binary PNG passthrough — escaping would corrupt the bytes.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $body;
        exit;
    }
}
