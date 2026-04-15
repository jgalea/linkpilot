<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Specialized checkers for embeddable media (YouTube, Vimeo).
 *
 * Uses each provider's oEmbed endpoint — no API key required. oEmbed returns
 * 200 when a video exists and can be embedded, or 401/403/404 when it's been
 * removed, made private, or geo-blocked. That's the correct signal of
 * "this embed will silently fail for your readers."
 *
 * Generic HTTP HEAD often returns 200 for removed videos because the page
 * still renders — that's why a specialized checker matters here.
 */
class LP_Scanner_Embed_Checker {

    /**
     * Return true if this URL should be checked by an embed provider rather
     * than the generic HTTP checker.
     */
    public static function matches( $url ) {
        return self::is_youtube( $url ) || self::is_vimeo( $url );
    }

    /**
     * Check a single embed URL. Returns the same shape as LP_Scanner_Checker:
     *   array( 'status' => ..., 'code' => ..., 'error' => ... )
     */
    public static function check( $url ) {
        if ( self::is_youtube( $url ) ) {
            return self::check_oembed( 'https://www.youtube.com/oembed?url=' . rawurlencode( $url ) . '&format=json' );
        }
        if ( self::is_vimeo( $url ) ) {
            return self::check_oembed( 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( $url ) );
        }
        return null;
    }

    public static function is_youtube( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );

        if ( in_array( $host, array( 'youtu.be' ), true ) ) {
            return $path !== '' && $path !== '/';
        }
        if ( preg_match( '#(?:^|\.)youtube\.[^/]+$#', $host ) ) {
            if ( stripos( $path, '/watch' ) === 0 ) return true;
            if ( stripos( $path, '/embed/' ) === 0 ) return true;
            if ( stripos( $path, '/shorts/' ) === 0 ) return true;
            if ( stripos( $path, '/playlist' ) === 0 ) return true;
        }
        return false;
    }

    public static function is_vimeo( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );

        if ( preg_match( '#(?:^|\.)vimeo\.com$#', $host ) ) {
            // Must have a video ID path — not just vimeo.com/
            if ( preg_match( '#^/\d+#', $path ) ) return true;
            if ( preg_match( '#^/[^/]+/[^/]+#', $path ) && $path !== '/' ) return true;
            if ( stripos( $path, '/video/' ) === 0 ) return true;
            if ( stripos( $path, '/channels/' ) === 0 ) return true;
        }
        return false;
    }

    private static function check_oembed( $api_url ) {
        $response = wp_remote_get( $api_url, array(
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => array( 'User-Agent' => 'LinkPilot Scanner (WordPress)' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'status' => 'error',
                'code'   => 0,
                'error'  => substr( $response->get_error_message(), 0, 500 ),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            return array( 'status' => 'healthy', 'code' => $code, 'error' => '' );
        }
        if ( $code === 400 ) {
            return array( 'status' => 'broken', 'code' => $code, 'error' => 'Invalid embed URL / unknown video ID' );
        }
        if ( $code === 401 ) {
            return array( 'status' => 'broken', 'code' => $code, 'error' => 'Video is private or embed disabled' );
        }
        if ( $code === 403 ) {
            return array( 'status' => 'broken', 'code' => $code, 'error' => 'Video removed or region-blocked' );
        }
        if ( $code === 404 ) {
            return array( 'status' => 'broken', 'code' => $code, 'error' => 'Video not found' );
        }
        if ( $code >= 500 ) {
            return array( 'status' => 'server_error', 'code' => $code, 'error' => 'Provider server error' );
        }
        return array( 'status' => 'unknown', 'code' => $code, 'error' => '' );
    }
}
