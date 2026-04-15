<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Extract outbound URLs from post content.
 *
 * Skips mailto:, tel:, javascript:, anchors, internal links, LP cloaked URLs,
 * and any URL matching user-configured allowlist domains.
 */
class LP_Scanner_Extractor {

    public static function extract( $content ) {
        if ( empty( $content ) || strpos( $content, '<a' ) === false ) {
            return array();
        }

        $urls = array();

        if ( preg_match_all( '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>/is', $content, $matches ) ) {
            foreach ( $matches[2] as $href ) {
                $url = self::normalize( $href );
                if ( $url ) {
                    $urls[ $url ] = true;
                }
            }
        }

        return array_keys( $urls );
    }

    private static function normalize( $href ) {
        $href = trim( html_entity_decode( $href ) );

        if ( $href === '' ) return '';
        if ( $href[0] === '#' ) return '';
        if ( stripos( $href, 'mailto:' ) === 0 ) return '';
        if ( stripos( $href, 'tel:' ) === 0 ) return '';
        if ( stripos( $href, 'javascript:' ) === 0 ) return '';
        if ( stripos( $href, 'data:' ) === 0 ) return '';
        if ( stripos( $href, '#' ) !== false ) {
            $href = substr( $href, 0, strpos( $href, '#' ) );
        }

        if ( strpos( $href, '//' ) === 0 ) {
            $href = 'https:' . $href;
        } elseif ( strpos( $href, '/' ) === 0 ) {
            $href = home_url( $href );
        }

        $parsed = wp_parse_url( $href );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return '';
        }
        if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
            return '';
        }

        // Skip LP cloaked URLs — LP health checker monitors their destinations separately.
        $lp_prefix = home_url( '/' . get_option( 'lp_link_prefix', 'go' ) . '/' );
        if ( strpos( $href, $lp_prefix ) === 0 ) {
            return '';
        }

        // Skip allowlisted domains (user-configured).
        $allowlist = self::get_allowlist();
        if ( $allowlist ) {
            $host = strtolower( $parsed['host'] );
            foreach ( $allowlist as $domain ) {
                if ( $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
                    return '';
                }
            }
        }

        return $href;
    }

    public static function get_allowlist() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        $raw = get_option( 'lp_scanner_allowlist', '' );
        if ( ! $raw ) {
            $cached = array();
            return $cached;
        }

        $lines = preg_split( '/\R/', $raw );
        $out   = array();
        foreach ( $lines as $line ) {
            $line = strtolower( trim( $line ) );
            if ( ! $line ) continue;
            $line = preg_replace( '#^https?://#', '', $line );
            $line = preg_replace( '#^www\.#', '', $line );
            $line = rtrim( $line, '/' );
            if ( $line ) $out[] = $line;
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $site_host ) {
            $site_host = preg_replace( '#^www\.#', '', strtolower( $site_host ) );
            if ( ! in_array( $site_host, $out, true ) ) {
                $out[] = $site_host;
            }
        }

        $cached = $out;
        return $cached;
    }
}
