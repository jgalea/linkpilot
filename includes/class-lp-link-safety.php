<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Link safety scan.
 *
 * Looks for signs that a link's destination is suspicious or dead beyond the
 * basic reachability check done by LP_Link_Health. Specifically:
 *
 *   - Destination host appears on a user-maintained blocklist
 *   - Response body contains parked-domain markers (sedoparking, godaddy.com/domains,
 *     bodis.com, above.com, etc.)
 *   - Response redirects to a different root domain (possible hijack/parked)
 *
 * Stored as post meta "_lp_safety_flags" (array of strings) and
 * "_lp_safety_checked_at" (datetime) per link.
 */
class LP_Link_Safety {

    const META_FLAGS   = '_lp_safety_flags';
    const META_CHECKED = '_lp_safety_checked_at';

    const PARKED_FINGERPRINTS = array(
        'sedoparking.com',
        'bodis.com',
        'above.com',
        'parkingcrew.net',
        'dan.com',
        'uniregistry.com',
        'hugedomains.com',
        'This domain is for sale',
        'buy this domain',
        'domain is parked free',
    );

    public static function init() {
        if ( is_admin() ) {
            add_action( 'admin_post_lp_safety_scan_one', array( __CLASS__, 'handle_scan_one' ) );
            add_filter( 'manage_edit-lp_link_columns', array( __CLASS__, 'add_column' ) );
            add_action( 'manage_lp_link_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        }
    }

    /**
     * Scan a single link. Returns the list of flags (strings).
     *
     * @param int $link_id
     * @return array
     */
    public static function scan( $link_id ) {
        $flags = array();

        $dest_url = get_post_meta( $link_id, '_lp_destination_url', true );
        if ( empty( $dest_url ) ) {
            $flags[] = 'no_url';
            self::save_flags( $link_id, $flags );
            return $flags;
        }

        $host = wp_parse_url( $dest_url, PHP_URL_HOST );

        // Blocklist check.
        $blocklist = self::get_blocklist();
        if ( ! empty( $host ) && ! empty( $blocklist ) ) {
            foreach ( $blocklist as $bad ) {
                if ( '' === $bad ) {
                    continue;
                }
                if ( strcasecmp( $host, $bad ) === 0 || substr( $host, -strlen( '.' . $bad ) ) === '.' . $bad ) {
                    $flags[] = 'blocklist';
                    break;
                }
            }
        }

        // Fetch destination to check for parking and redirect-to-another-host.
        $response = wp_remote_get( $dest_url, array(
            'timeout'     => 6,
            'redirection' => 3,
            'user-agent'  => 'LinkPilot Safety Check/1.0 (+https://linkpilothq.com/)',
        ) );

        if ( is_wp_error( $response ) ) {
            $flags[] = 'fetch_error';
            self::save_flags( $link_id, $flags );
            return $flags;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 500 ) {
            $flags[] = 'server_error';
        } elseif ( $code >= 400 ) {
            $flags[] = 'broken';
        }

        // Parked-domain detection via body fingerprints.
        $body = wp_remote_retrieve_body( $response );
        if ( ! empty( $body ) ) {
            foreach ( self::PARKED_FINGERPRINTS as $needle ) {
                if ( stripos( $body, $needle ) !== false ) {
                    $flags[] = 'parked';
                    break;
                }
            }
        }

        // Redirect-to-different-host.
        $final_url = wp_remote_retrieve_header( $response, 'x-redirect-url' );
        if ( empty( $final_url ) ) {
            // WP Requests puts final URL on the response object but it's not always exposed.
            // Fallback: read the 'history' if present.
            $history = wp_remote_retrieve_header( $response, 'location' );
            if ( ! empty( $history ) ) {
                $final_host = wp_parse_url( $history, PHP_URL_HOST );
                if ( ! empty( $final_host ) && ! empty( $host ) && strcasecmp( $final_host, $host ) !== 0 ) {
                    $flags[] = 'cross_domain_redirect';
                }
            }
        }

        self::save_flags( $link_id, $flags );
        return $flags;
    }

    /**
     * Persist scan result.
     *
     * @param int   $link_id
     * @param array $flags
     */
    private static function save_flags( $link_id, $flags ) {
        update_post_meta( $link_id, self::META_FLAGS, array_values( array_unique( $flags ) ) );
        update_post_meta( $link_id, self::META_CHECKED, current_time( 'mysql', true ) );
    }

    /**
     * Get the user-configured blocklist (one host per line).
     *
     * @return array<string>
     */
    public static function get_blocklist() {
        $raw = get_option( 'lp_safety_blocklist', '' );
        if ( empty( $raw ) ) {
            return array();
        }
        $lines = preg_split( '/[\r\n]+/', $raw );
        $out   = array();
        foreach ( $lines as $line ) {
            $line = trim( strtolower( $line ) );
            if ( '' !== $line ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * Render a human-readable label for a flag code.
     *
     * @param string $flag
     * @return string
     */
    public static function flag_label( $flag ) {
        switch ( $flag ) {
            case 'no_url':
                return __( 'No destination URL', 'linkpilot' );
            case 'blocklist':
                return __( 'On your blocklist', 'linkpilot' );
            case 'fetch_error':
                return __( 'Fetch failed', 'linkpilot' );
            case 'broken':
                return __( 'Broken (HTTP 4xx)', 'linkpilot' );
            case 'server_error':
                return __( 'Server error (5xx)', 'linkpilot' );
            case 'parked':
                return __( 'Parked / for-sale domain', 'linkpilot' );
            case 'cross_domain_redirect':
                return __( 'Redirects to a different domain', 'linkpilot' );
            default:
                return $flag;
        }
    }

    /**
     * Add "Safety" column to the lp_link list table.
     *
     * @param array $columns
     * @return array
     */
    public static function add_column( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['lp_safety'] = __( 'Safety', 'linkpilot' );
            }
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'lp_safety' !== $column ) {
            return;
        }
        $flags = get_post_meta( $post_id, self::META_FLAGS, true );
        if ( ! is_array( $flags ) || empty( $flags ) ) {
            echo '<span style="color: #999;">&mdash;</span>';
            return;
        }
        // Benign states: keep quiet. Serious flags: red.
        $labels = array();
        foreach ( $flags as $flag ) {
            $labels[] = esc_html( self::flag_label( $flag ) );
        }
        echo '<span style="color:#a00; font-weight:600;">&#9888; ' . implode( ', ', $labels ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- labels escaped above.
    }

    /**
     * Admin action: scan one link.
     */
    public static function handle_scan_one() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'lp_safety_scan_' . $id );
        if ( $id > 0 ) {
            self::scan( $id );
        }
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=lp_link' ) );
        exit;
    }
}
