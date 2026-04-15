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
        if ( empty( $content ) ) {
            return array();
        }

        $urls = array();

        // 1. <a href>
        if ( strpos( $content, '<a' ) !== false
             && preg_match_all( '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>/is', $content, $matches ) ) {
            foreach ( $matches[2] as $href ) {
                $url = self::normalize( $href );
                if ( $url ) {
                    $urls[ $url ] = true;
                }
            }
        }

        // 2. <img src> and <source src>
        if ( strpos( $content, '<img' ) !== false || strpos( $content, '<source' ) !== false ) {
            if ( preg_match_all( '/<(?:img|source)\s[^>]*src=(["\'])(.*?)\1[^>]*>/is', $content, $matches ) ) {
                foreach ( $matches[2] as $src ) {
                    $url = self::normalize( $src );
                    if ( $url ) {
                        $urls[ $url ] = true;
                    }
                }
            }
        }

        // 3. srcset (multiple URLs per attribute, comma-separated, with descriptors)
        if ( strpos( $content, 'srcset' ) !== false
             && preg_match_all( '/srcset=(["\'])(.*?)\1/is', $content, $matches ) ) {
            foreach ( $matches[2] as $srcset ) {
                foreach ( preg_split( '/\s*,\s*/', $srcset ) as $candidate ) {
                    $parts = preg_split( '/\s+/', trim( $candidate ), 2 );
                    if ( empty( $parts[0] ) ) continue;
                    $url = self::normalize( $parts[0] );
                    if ( $url ) {
                        $urls[ $url ] = true;
                    }
                }
            }
        }

        // 4. YouTube / Vimeo iframe src (for embed checking)
        if ( strpos( $content, '<iframe' ) !== false
             && preg_match_all( '/<iframe\s[^>]*src=(["\'])(.*?)\1[^>]*>/is', $content, $matches ) ) {
            foreach ( $matches[2] as $src ) {
                $url = self::normalize( $src );
                if ( $url ) {
                    $urls[ $url ] = true;
                }
            }
        }

        // 5. Raw YouTube/Vimeo URLs in content (WP auto-embeds) — match URLs on their own line
        if ( preg_match_all( '#(?:^|\s)(https?://(?:www\.)?(?:youtube\.com|youtu\.be|vimeo\.com)/[^\s<>"\']+)#i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $norm = self::normalize( $url );
                if ( $norm ) {
                    $urls[ $norm ] = true;
                }
            }
        }

        return array_keys( $urls );
    }

    public static function normalize_public( $href ) {
        return self::normalize( $href );
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

        // Skip URLs matching user-configured exclusion patterns.
        foreach ( self::get_url_patterns() as $pattern ) {
            if ( $pattern === '' ) continue;
            if ( $pattern[0] === '/' && substr( $pattern, -1 ) === '/' ) {
                if ( @preg_match( $pattern, $href ) === 1 ) {
                    return '';
                }
            } elseif ( stripos( $href, $pattern ) !== false ) {
                return '';
            }
        }

        return $href;
    }

    public static function get_url_patterns() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        $raw = get_option( 'lp_scanner_url_exclusion', '' );
        $out = array();
        if ( $raw ) {
            foreach ( preg_split( '/\R/', $raw ) as $line ) {
                $line = trim( $line );
                if ( $line !== '' ) $out[] = $line;
            }
        }
        $cached = $out;
        return $cached;
    }

    public static function get_allowlist() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        $out = array();

        // Always allowlist the site's own host (internal links are never "broken outbound links").
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $site_host ) {
            $out[] = preg_replace( '#^www\.#', '', strtolower( $site_host ) );
        }

        $raw = get_option( 'lp_scanner_allowlist', '' );
        if ( $raw ) {
            $lines = preg_split( '/\R/', $raw );
            foreach ( $lines as $line ) {
                $line = strtolower( trim( $line ) );
                if ( ! $line ) continue;
                $line = preg_replace( '#^https?://#', '', $line );
                $line = preg_replace( '#^www\.#', '', $line );
                $line = rtrim( $line, '/' );
                if ( $line && ! in_array( $line, $out, true ) ) {
                    $out[] = $line;
                }
            }
        }

        $cached = $out;
        return $cached;
    }
}
