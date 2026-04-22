<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Auto-UTM builder.
 *
 * Appends configurable UTM parameters (source, medium, campaign, term, content)
 * to outbound destinations at redirect time. Supports a global default plus
 * per-link overrides stored in post meta. Three behaviors when the destination
 * already has UTM parameters: append (leave existing, add missing),
 * override (replace existing), skip (do nothing if any UTM already present).
 */
class LP_UTM {

    const PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );

    public static function init() {
        if ( get_option( 'lp_utm_enabled', 'no' ) !== 'yes' ) {
            return;
        }
        // Priority 20: after geo (10) so UTMs apply to whichever destination was chosen.
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_utms' ), 20, 2 );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
            add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
        }
    }

    /**
     * Filter callback: append/override UTM parameters on the destination URL.
     *
     * @param string  $destination Destination URL.
     * @param LP_Link $link        Link object.
     * @return string
     */
    public static function apply_utms( $destination, $link ) {
        if ( empty( $destination ) ) {
            return $destination;
        }

        $values = self::resolve_values( $link );
        if ( empty( array_filter( $values ) ) ) {
            return $destination;
        }

        $mode = get_option( 'lp_utm_mode', 'append' );
        $parts = wp_parse_url( $destination );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $destination;
        }

        $existing = array();
        if ( ! empty( $parts['query'] ) ) {
            wp_parse_str( $parts['query'], $existing );
        }

        // "Skip" mode: if ANY UTM is already on the destination, do nothing.
        if ( 'skip' === $mode ) {
            foreach ( self::PARAMS as $p ) {
                if ( isset( $existing[ $p ] ) && '' !== $existing[ $p ] ) {
                    return $destination;
                }
            }
        }

        foreach ( self::PARAMS as $p ) {
            if ( empty( $values[ $p ] ) ) {
                continue;
            }
            $already_set = isset( $existing[ $p ] ) && '' !== $existing[ $p ];
            if ( 'append' === $mode && $already_set ) {
                continue;
            }
            $existing[ $p ] = $values[ $p ];
        }

        $parts['query'] = http_build_query( $existing );
        return self::rebuild_url( $parts );
    }

    /**
     * Resolve effective UTM values for a link: per-link overrides > global defaults.
     *
     * @param LP_Link $link Link object.
     * @return array<string, string>
     */
    private static function resolve_values( $link ) {
        $out = array();
        foreach ( self::PARAMS as $p ) {
            $meta_key = '_lp_' . $p;
            $per_link = get_post_meta( $link->get_id(), $meta_key, true );
            if ( '' !== $per_link && null !== $per_link ) {
                $out[ $p ] = (string) $per_link;
                continue;
            }
            $out[ $p ] = (string) get_option( 'lp_' . $p, '' );
        }
        return $out;
    }

    /**
     * Rebuild a URL from parsed components.
     *
     * @param array $parts wp_parse_url() result.
     * @return string
     */
    private static function rebuild_url( $parts ) {
        $scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $user     = isset( $parts['user'] ) ? $parts['user'] : '';
        $pass     = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
        $userpass = $user ? $user . $pass . '@' : '';
        $host     = isset( $parts['host'] ) ? $parts['host'] : '';
        $port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        $path     = isset( $parts['path'] ) ? $parts['path'] : '';
        $query    = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';
        $fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
        return $scheme . $userpass . $host . $port . $path . $query . $fragment;
    }

    /**
     * Register meta box on the link edit screen for per-link UTM overrides.
     */
    public static function add_meta_box() {
        add_meta_box(
            'lp_utm_overrides',
            __( 'UTM Parameters (override)', 'linkpilot' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    /**
     * Render the per-link UTM override fields.
     *
     * @param WP_Post $post Link post.
     */
    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lp_utm_save', 'lp_utm_nonce' );
        ?>
        <p class="description" style="margin-top:0;"><?php esc_html_e( 'Leave blank to use the site-wide defaults from LinkPilot > Settings > UTM. Fill in to override for this link only.', 'linkpilot' ); ?></p>
        <table class="form-table" role="presentation">
            <?php foreach ( self::PARAMS as $param ) :
                $value = get_post_meta( $post->ID, '_lp_' . $param, true );
                ?>
                <tr>
                    <th scope="row">
                        <label for="lp_<?php echo esc_attr( $param ); ?>"><?php echo esc_html( $param ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="lp_<?php echo esc_attr( $param ); ?>"
                            name="lp_<?php echo esc_attr( $param ); ?>"
                            value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    /**
     * Save per-link UTM overrides.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lp_utm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_utm_nonce'] ) ), 'lp_utm_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( self::PARAMS as $param ) {
            $field = 'lp_' . $param;
            if ( isset( $_POST[ $field ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( '' === $value ) {
                    delete_post_meta( $post_id, '_lp_' . $param );
                } else {
                    update_post_meta( $post_id, '_lp_' . $param, $value );
                }
            }
        }
    }
}
