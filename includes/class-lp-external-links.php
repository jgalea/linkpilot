<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * External Links module.
 *
 * Processes raw outbound <a> tags in post content and applies the user's
 * configured rel / target attributes. Links already pointing through the
 * cloaked prefix (handled by LP_Redirect) are left untouched.
 */
class LP_External_Links {

    public static function init() {
        if ( is_admin() ) {
            add_action( 'admin_notices', array( __CLASS__, 'maybe_show_wpel_notice' ) );
            add_action( 'admin_post_lp_dismiss_wpel_notice', array( __CLASS__, 'dismiss_wpel_notice' ) );
        }

        if ( get_option( 'lp_ext_enabled', 'no' ) !== 'yes' ) {
            return;
        }
        add_filter( 'the_content', array( __CLASS__, 'process_content' ), 30 );
    }

    /**
     * Show a notice if WP External Links is active and we haven't been dismissed.
     */
    public static function maybe_show_wpel_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'lp_ext_wpel_notice_dismissed' ) ) {
            return;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active( 'wp-external-links/wp-external-links.php' ) ) {
            return;
        }

        $settings_url  = admin_url( 'edit.php?post_type=lp_link&page=lp-settings#lp_external_links' );
        $dismiss_url   = wp_nonce_url( admin_url( 'admin-post.php?action=lp_dismiss_wpel_notice' ), 'lp_dismiss_wpel_notice' );
        $plugins_url   = admin_url( 'plugins.php' );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e( 'LinkPilot can replace WP External Links', 'linkpilot' ); ?></strong>
                —
                <?php
                /* translators: 1: LinkPilot settings URL, 2: plugins page URL */
                $message = __( 'LinkPilot now handles rel and target attributes on outbound links. <a href="%1$s">Enable External Links in LinkPilot settings</a>, then <a href="%2$s">deactivate WP External Links</a>.', 'linkpilot' );
                echo wp_kses(
                    sprintf( $message, esc_url( $settings_url ), esc_url( $plugins_url ) ),
                    array( 'a' => array( 'href' => array() ) )
                );
                ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left: 8px;"><?php esc_html_e( 'Dismiss', 'linkpilot' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Persist dismissal of the WP External Links notice.
     */
    public static function dismiss_wpel_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_dismiss_wpel_notice' );
        update_option( 'lp_ext_wpel_notice_dismissed', 1 );
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        exit;
    }

    /**
     * Filter the_content: add rel and target attributes to external links.
     *
     * @param string $content Post content HTML.
     * @return string
     */
    public static function process_content( $content ) {
        if ( '' === $content || is_admin() || is_feed() ) {
            return $content;
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $site_host ) ) {
            return $content;
        }

        $allowlist      = self::parse_lines( get_option( 'lp_ext_allowlist_domains', '' ) );
        $excluded_class = self::parse_lines( get_option( 'lp_ext_excluded_classes', '' ) );
        $rel_parts      = self::get_rel_parts();
        $target         = get_option( 'lp_ext_target', 'blank' );
        $prefix         = get_option( 'lp_link_prefix', 'go' );

        $cloaked_prefix_path = '/' . trim( $prefix, '/' ) . '/';

        return preg_replace_callback(
            '/<a\s[^>]*href=(["\'])([^"\']+)\1[^>]*>/i',
            function ( $match ) use ( $site_host, $allowlist, $excluded_class, $rel_parts, $target, $cloaked_prefix_path ) {
                $tag  = $match[0];
                $href = $match[2];

                // Skip non-http(s) links (mailto:, tel:, #anchor, etc.).
                if ( ! preg_match( '#^https?://#i', $href ) ) {
                    return $tag;
                }

                $host = wp_parse_url( $href, PHP_URL_HOST );
                if ( empty( $host ) ) {
                    return $tag;
                }

                // Same host as site = internal link, skip.
                if ( strcasecmp( $host, $site_host ) === 0 ) {
                    return $tag;
                }

                // Host matches the site's base domain (e.g. subdomain, or www vs apex) = allowed.
                if ( self::is_same_root_domain( $host, $site_host ) ) {
                    return $tag;
                }

                // User allowlist.
                foreach ( $allowlist as $allowed ) {
                    if ( '' === $allowed ) {
                        continue;
                    }
                    if ( strcasecmp( $host, $allowed ) === 0 ) {
                        return $tag;
                    }
                    // Subdomain match.
                    if ( substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
                        return $tag;
                    }
                }

                // Skip cloaked LinkPilot links — they have their own rules.
                $path = wp_parse_url( $href, PHP_URL_PATH );
                if ( $path && strpos( $path, $cloaked_prefix_path ) === 0 ) {
                    return $tag;
                }

                // Skip if existing class matches an excluded one.
                if ( ! empty( $excluded_class ) && preg_match( '/class=(["\'])([^"\']*)\1/i', $tag, $cmatch ) ) {
                    $classes = preg_split( '/\s+/', $cmatch[2] );
                    foreach ( $classes as $cls ) {
                        if ( in_array( $cls, $excluded_class, true ) ) {
                            return $tag;
                        }
                    }
                }

                return self::apply_attrs( $tag, $rel_parts, $target );
            },
            $content
        );
    }

    /**
     * Apply rel and target attributes to an <a> tag.
     *
     * @param string $tag       The <a ...> opening tag.
     * @param array  $rel_parts Rel values to merge.
     * @param string $target    Target value: blank or self.
     * @return string
     */
    private static function apply_attrs( $tag, $rel_parts, $target ) {
        // Merge rel.
        if ( ! empty( $rel_parts ) ) {
            if ( preg_match( '/\srel=(["\'])([^"\']*)\1/i', $tag, $rel_match ) ) {
                $existing = preg_split( '/\s+/', $rel_match[2] );
                $merged   = array_values( array_unique( array_filter( array_merge( $existing, $rel_parts ) ) ) );
                $new_rel  = 'rel="' . esc_attr( implode( ' ', $merged ) ) . '"';
                $tag      = str_replace( $rel_match[0], ' ' . $new_rel, $tag );
            } else {
                $tag = preg_replace( '/<a\s/i', '<a rel="' . esc_attr( implode( ' ', $rel_parts ) ) . '" ', $tag, 1 );
            }
        }

        // Set target.
        if ( 'blank' === $target ) {
            if ( preg_match( '/\starget=(["\'])([^"\']*)\1/i', $tag ) ) {
                $tag = preg_replace( '/\starget=(["\'])([^"\']*)\1/i', ' target="_blank"', $tag, 1 );
            } else {
                $tag = preg_replace( '/<a\s/i', '<a target="_blank" ', $tag, 1 );
            }
        }

        return $tag;
    }

    /**
     * Build the rel attribute parts from saved options.
     *
     * @return array
     */
    private static function get_rel_parts() {
        $parts = array();
        $map   = array(
            'lp_ext_rel_nofollow'   => 'nofollow',
            'lp_ext_rel_sponsored'  => 'sponsored',
            'lp_ext_rel_ugc'        => 'ugc',
            'lp_ext_rel_noopener'   => 'noopener',
            'lp_ext_rel_noreferrer' => 'noreferrer',
        );
        foreach ( $map as $option => $rel ) {
            if ( 'yes' === get_option( $option, self::default_for( $option ) ) ) {
                $parts[] = $rel;
            }
        }
        return $parts;
    }

    /**
     * Default value for a rel option.
     *
     * @param string $option Option name.
     * @return string
     */
    private static function default_for( $option ) {
        $defaults = array(
            'lp_ext_rel_nofollow'   => 'yes',
            'lp_ext_rel_sponsored'  => 'no',
            'lp_ext_rel_ugc'        => 'no',
            'lp_ext_rel_noopener'   => 'yes',
            'lp_ext_rel_noreferrer' => 'yes',
        );
        return isset( $defaults[ $option ] ) ? $defaults[ $option ] : 'no';
    }

    /**
     * Parse a textarea value into an array of trimmed, non-empty lines.
     *
     * @param string $raw Raw textarea content.
     * @return array
     */
    private static function parse_lines( $raw ) {
        if ( empty( $raw ) ) {
            return array();
        }
        $lines = preg_split( '/[\r\n]+/', $raw );
        $out   = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Check whether $host shares a root domain with $site_host (e.g. foo.example.com and example.com).
     *
     * @param string $host
     * @param string $site_host
     * @return bool
     */
    private static function is_same_root_domain( $host, $site_host ) {
        $host      = strtolower( $host );
        $site_host = strtolower( $site_host );

        // Strip a leading www. for comparison.
        $site_root = preg_replace( '/^www\./', '', $site_host );

        if ( $host === $site_root ) {
            return true;
        }
        // Subdomain match: host ends with ".site_root".
        if ( substr( $host, -strlen( '.' . $site_root ) ) === '.' . $site_root ) {
            return true;
        }
        return false;
    }
}
