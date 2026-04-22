<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Link preview cards.
 *
 * Fetches Open Graph metadata (title, description, image, site_name) for
 * outbound URLs and renders a rich preview card. Cached as a transient per
 * URL. Exposed as a shortcode [lp_preview url="..."] and a server-rendered
 * Gutenberg block.
 *
 * Cache lifetime: 7 days by default. Users can clear cache via admin.
 */
class LP_Previews {

    const CACHE_PREFIX = 'lp_prev_';
    const CACHE_TTL    = 7 * DAY_IN_SECONDS;
    const USER_AGENT   = 'LinkPilot Preview Fetcher/1.0 (+https://linkpilothq.com/)';

    public static function init() {
        add_shortcode( 'lp_preview', array( __CLASS__, 'render_shortcode' ) );

        if ( is_admin() ) {
            add_action( 'admin_post_lp_previews_clear', array( __CLASS__, 'handle_clear_cache' ) );
        }
    }

    /**
     * Shortcode: [lp_preview url="https://example.com"]
     *
     * @param array $atts
     * @return string
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'url' => '' ), $atts, 'lp_preview' );
        $url  = esc_url_raw( $atts['url'] );
        if ( empty( $url ) ) {
            return '';
        }

        $data = self::get_preview_data( $url );
        if ( empty( $data ) ) {
            return '';
        }
        return self::render_card( $url, $data );
    }

    /**
     * Render the HTML card.
     *
     * @param string $url
     * @param array  $data
     * @return string
     */
    public static function render_card( $url, $data ) {
        $title       = isset( $data['title'] ) ? $data['title'] : wp_parse_url( $url, PHP_URL_HOST );
        $description = isset( $data['description'] ) ? $data['description'] : '';
        $image       = isset( $data['image'] ) ? $data['image'] : '';
        $site_name   = isset( $data['site_name'] ) ? $data['site_name'] : wp_parse_url( $url, PHP_URL_HOST );

        ob_start();
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="lp-preview-card" target="_blank" rel="noopener noreferrer">
            <?php if ( ! empty( $image ) ) : ?>
                <div class="lp-preview-card__image">
                    <img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
                </div>
            <?php endif; ?>
            <div class="lp-preview-card__body">
                <div class="lp-preview-card__site"><?php echo esc_html( $site_name ); ?></div>
                <div class="lp-preview-card__title"><?php echo esc_html( $title ); ?></div>
                <?php if ( ! empty( $description ) ) : ?>
                    <div class="lp-preview-card__desc"><?php echo esc_html( $description ); ?></div>
                <?php endif; ?>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    /**
     * Get cached or fetched preview data for a URL.
     *
     * @param string $url
     * @return array|null
     */
    public static function get_preview_data( $url ) {
        $key    = self::CACHE_PREFIX . md5( $url );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : null;
        }

        $data = self::fetch( $url );
        // Cache even negative results briefly to prevent hammering.
        set_transient( $key, $data ?: array(), $data ? self::CACHE_TTL : HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * Fetch OG metadata.
     *
     * @param string $url
     * @return array|null
     */
    private static function fetch( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout'     => 6,
            'redirection' => 3,
            'user-agent'  => self::USER_AGENT,
            'headers'     => array( 'Accept' => 'text/html' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return null;
        }

        // Parse <head> with a simple regex approach. DOMDocument would be more
        // robust but heavier and noisy with warnings on bad HTML.
        $data = array();

        $title = self::match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html );
        if ( ! $title ) {
            $title = self::match( '/<title[^>]*>([^<]+)<\/title>/i', $html );
        }
        if ( $title ) {
            $data['title'] = self::clean( $title );
        }

        $desc = self::match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html );
        if ( ! $desc ) {
            $desc = self::match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html );
        }
        if ( $desc ) {
            $data['description'] = self::clean( $desc );
        }

        $image = self::match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html );
        if ( $image ) {
            $data['image'] = self::resolve_url( $image, $url );
        }

        $site = self::match( '/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']/i', $html );
        if ( $site ) {
            $data['site_name'] = self::clean( $site );
        }

        return empty( $data ) ? null : $data;
    }

    private static function match( $pattern, $haystack ) {
        if ( preg_match( $pattern, $haystack, $m ) ) {
            return isset( $m[1] ) ? $m[1] : null;
        }
        return null;
    }

    private static function clean( $str ) {
        $str = html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $str = trim( $str );
        return mb_substr( $str, 0, 400 );
    }

    private static function resolve_url( $url, $base ) {
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }
        $parts = wp_parse_url( $base );
        if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return $url;
        }
        if ( 0 === strpos( $url, '//' ) ) {
            return $parts['scheme'] . ':' . $url;
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return $parts['scheme'] . '://' . $parts['host'] . $url;
        }
        return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim( $url, '/' );
    }

    /**
     * Clear all preview caches.
     */
    public static function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_previews_clear' );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . self::CACHE_PREFIX . '%',
            '_transient_timeout_' . self::CACHE_PREFIX . '%'
        ) );

        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=lp_link&page=lp-settings' ) );
        exit;
    }
}
