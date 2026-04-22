<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affiliate disclosure auto-insert.
 *
 * When a post contains at least one cloaked link marked as `sponsored`,
 * LinkPilot prepends (or appends) a disclosure notice to the post content.
 *
 * Options:
 *   lp_disclosure_enabled (yes/no, default no)
 *   lp_disclosure_position (top | bottom, default top)
 *   lp_disclosure_text     (HTML string — the disclosure itself)
 *
 * The post must contain at least one link to the site's own cloaked prefix
 * that resolves to an lp_link with sponsored=yes (or the global sponsored
 * default is yes).
 */
class LP_Disclosure {

    const DEFAULT_TEXT = '<em>Some links in this post are affiliate links. If you click through and make a purchase, I may earn a small commission at no extra cost to you.</em>';

    public static function init() {
        if ( get_option( 'lp_disclosure_enabled', 'no' ) !== 'yes' ) {
            return;
        }
        add_filter( 'the_content', array( __CLASS__, 'maybe_insert' ), 50 );
    }

    public static function maybe_insert( $content ) {
        if ( is_admin() || is_feed() ) {
            return $content;
        }
        if ( ! is_singular() ) {
            return $content;
        }

        if ( ! self::post_has_sponsored_link( $content ) ) {
            return $content;
        }

        $position = get_option( 'lp_disclosure_position', 'top' );
        $text     = self::get_text();
        $notice   = '<div class="lp-disclosure" style="padding: 12px 16px; background: #fff8e1; border-left: 4px solid #d4ac0d; margin: 16px 0; font-size: 14px; color: #4d4d4d;">' . $text . '</div>';

        return ( 'bottom' === $position ) ? $content . $notice : $notice . $content;
    }

    /**
     * Does the content contain any sponsored cloaked link?
     *
     * Fast path: resolve links that match the cloaked prefix and check their
     * sponsored meta. Fallback: global sponsored default.
     *
     * @param string $content
     * @return bool
     */
    private static function post_has_sponsored_link( $content ) {
        $prefix = trim( (string) get_option( 'lp_link_prefix', 'go' ), '/' );
        if ( '' === $prefix ) {
            return false;
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_host ) ) {
            return false;
        }

        $pattern = '@href=["\']https?://(?:www\.)?' . preg_quote( $site_host, '@' ) . '/' . preg_quote( $prefix, '@' ) . '/([^/"\']+)/?@i';
        if ( ! preg_match_all( $pattern, $content, $matches ) ) {
            return false;
        }

        $slugs = array_unique( $matches[1] );
        if ( empty( $slugs ) ) {
            return false;
        }

        $global_sponsored = 'yes' === get_option( 'lp_sponsored', 'no' );

        foreach ( $slugs as $slug ) {
            $posts = get_posts( array(
                'post_type'      => 'lp_link',
                'name'           => sanitize_title( $slug ),
                'posts_per_page' => 1,
                'post_status'    => 'any',
            ) );
            if ( empty( $posts ) ) {
                continue;
            }
            $per_link = get_post_meta( $posts[0]->ID, '_lp_sponsored', true );
            if ( 'yes' === $per_link ) {
                return true;
            }
            if ( 'no' === $per_link ) {
                continue;
            }
            // default falls through to global.
            if ( $global_sponsored ) {
                return true;
            }
        }
        return false;
    }

    private static function get_text() {
        $raw = get_option( 'lp_disclosure_text', '' );
        if ( '' === trim( (string) $raw ) ) {
            $raw = self::DEFAULT_TEXT;
        }
        return wp_kses_post( $raw );
    }
}
