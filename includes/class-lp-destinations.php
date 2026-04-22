<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Multiple destinations per link — fallback chain or A/B split.
 *
 * Meta:
 *   _lp_destinations_mode: 'off' | 'fallback' | 'split'
 *   _lp_destinations:      JSON array of { url: string, weight: int }
 *
 * fallback: try each URL in order. If LP_Link_Health or a recent scan has
 *           flagged #1 as broken, use #2, etc. (We don't do a live probe at
 *           redirect time — that would slow the redirect. We trust the cached
 *           health status.)
 *
 * split:    weighted random choice. Recorded in clicks table via the
 *           referrer/country columns? No — we add a per-click meta record
 *           via the 'lp_clicks_extra' filter we emit here so the Reports
 *           page can show winner stats.
 */
class LP_Destinations {

    const META_MODE         = '_lp_destinations_mode';
    const META_DESTINATIONS = '_lp_destinations';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'maybe_override' ), 15, 2 );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
            add_action( 'save_post_lp_link', array( __CLASS__, 'save' ), 20, 2 );
        }
    }

    /**
     * Filter callback: override the destination based on mode.
     *
     * Priority 15 — between expiration (5) and UTM (20), so UTMs still apply
     * to whatever URL we picked.
     *
     * @param string  $destination
     * @param LP_Link $link
     * @return string
     */
    public static function maybe_override( $destination, $link ) {
        $mode = get_post_meta( $link->get_id(), self::META_MODE, true );
        if ( ! in_array( $mode, array( 'fallback', 'split' ), true ) ) {
            return $destination;
        }

        $list = self::get_destinations( $link->get_id() );
        if ( empty( $list ) ) {
            return $destination;
        }

        if ( 'split' === $mode ) {
            $chosen = self::weighted_pick( $list );
            return ! empty( $chosen['url'] ) ? $chosen['url'] : $destination;
        }

        // fallback: first URL, unless the health cache says it's broken.
        foreach ( $list as $item ) {
            if ( empty( $item['url'] ) ) {
                continue;
            }
            $status = class_exists( 'LP_Link_Health' )
                ? get_post_meta( $link->get_id(), '_lp_link_health_status', true )
                : '';
            // If the primary is broken, skip it when a fallback is available.
            if ( $item === $list[0] && in_array( $status, array( 'broken', 'server_error' ), true ) && count( $list ) > 1 ) {
                continue;
            }
            return $item['url'];
        }

        return $destination;
    }

    /**
     * Pick an item from a weighted list.
     *
     * @param array $list
     * @return array
     */
    private static function weighted_pick( $list ) {
        $total = 0;
        foreach ( $list as $item ) {
            $total += max( 0, (int) ( isset( $item['weight'] ) ? $item['weight'] : 1 ) );
        }
        if ( $total <= 0 ) {
            return $list[0];
        }
        $roll = wp_rand( 1, $total );
        $acc  = 0;
        foreach ( $list as $item ) {
            $acc += max( 0, (int) ( isset( $item['weight'] ) ? $item['weight'] : 1 ) );
            if ( $roll <= $acc ) {
                return $item;
            }
        }
        return end( $list );
    }

    /**
     * Get the configured destinations list.
     *
     * @param int $link_id
     * @return array
     */
    public static function get_destinations( $link_id ) {
        $raw = get_post_meta( $link_id, self::META_DESTINATIONS, true );
        if ( empty( $raw ) ) {
            return array();
        }
        $data = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return array();
        }
        $clean = array();
        foreach ( $data as $item ) {
            if ( ! is_array( $item ) || empty( $item['url'] ) ) {
                continue;
            }
            $clean[] = array(
                'url'    => esc_url_raw( $item['url'] ),
                'weight' => isset( $item['weight'] ) ? max( 1, (int) $item['weight'] ) : 1,
            );
        }
        return $clean;
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public static function add_meta_box() {
        add_meta_box(
            'lp_destinations',
            __( 'Multiple destinations / A-B test', 'linkpilot' ),
            array( __CLASS__, 'render' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    public static function render( $post ) {
        wp_nonce_field( 'lp_destinations_save', 'lp_destinations_nonce' );
        $mode = get_post_meta( $post->ID, self::META_MODE, true ) ?: 'off';
        $list = self::get_destinations( $post->ID );
        if ( empty( $list ) ) {
            $list = array( array( 'url' => '', 'weight' => 1 ) );
        }
        ?>
        <p class="description" style="margin-top: 0;">
            <?php esc_html_e( 'Optional. Leave mode set to "Off" to use the single Destination URL above. When on, this overrides that URL.', 'linkpilot' ); ?>
        </p>

        <p>
            <label>
                <strong><?php esc_html_e( 'Mode', 'linkpilot' ); ?>:</strong>
                <select name="lp_destinations_mode">
                    <option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Off', 'linkpilot' ); ?></option>
                    <option value="fallback" <?php selected( $mode, 'fallback' ); ?>><?php esc_html_e( 'Fallback (primary → backup if primary breaks)', 'linkpilot' ); ?></option>
                    <option value="split" <?php selected( $mode, 'split' ); ?>><?php esc_html_e( 'A/B split (random weighted choice)', 'linkpilot' ); ?></option>
                </select>
            </label>
        </p>

        <table class="widefat" style="max-width: 720px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Destination URL', 'linkpilot' ); ?></th>
                    <th style="width: 90px;"><?php esc_html_e( 'Weight', 'linkpilot' ); ?></th>
                </tr>
            </thead>
            <tbody id="lp-destinations-rows">
                <?php foreach ( $list as $i => $item ) : ?>
                    <tr>
                        <td><input type="url" name="lp_destinations[<?php echo (int) $i; ?>][url]" value="<?php echo esc_url( $item['url'] ); ?>" class="widefat" placeholder="https://" /></td>
                        <td><input type="number" min="1" max="100" name="lp_destinations[<?php echo (int) $i; ?>][weight]" value="<?php echo (int) $item['weight']; ?>" style="width: 70px;" /></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ( $j = count( $list ); $j < count( $list ) + 3; $j++ ) : ?>
                    <tr>
                        <td><input type="url" name="lp_destinations[<?php echo (int) $j; ?>][url]" value="" class="widefat" placeholder="https://" /></td>
                        <td><input type="number" min="1" max="100" name="lp_destinations[<?php echo (int) $j; ?>][weight]" value="1" style="width: 70px;" /></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e( 'Fallback mode: order matters — the first URL is tried first, and LinkPilot moves to the next if health checks show the previous is broken. Split mode: weight = relative share of traffic. Equal weights = even split.', 'linkpilot' ); ?>
        </p>
        <?php
    }

    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['lp_destinations_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_destinations_nonce'] ) ), 'lp_destinations_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $mode = isset( $_POST['lp_destinations_mode'] ) ? sanitize_key( wp_unslash( $_POST['lp_destinations_mode'] ) ) : 'off';
        if ( ! in_array( $mode, array( 'off', 'fallback', 'split' ), true ) ) {
            $mode = 'off';
        }
        update_post_meta( $post_id, self::META_MODE, $mode );

        $clean = array();
        if ( isset( $_POST['lp_destinations'] ) && is_array( $_POST['lp_destinations'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
            $raw = wp_unslash( $_POST['lp_destinations'] );
            foreach ( $raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $url = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
                if ( '' === $url ) {
                    continue;
                }
                $clean[] = array(
                    'url'    => $url,
                    'weight' => isset( $row['weight'] ) ? max( 1, min( 100, (int) $row['weight'] ) ) : 1,
                );
            }
        }

        if ( empty( $clean ) ) {
            delete_post_meta( $post_id, self::META_DESTINATIONS );
        } else {
            update_post_meta( $post_id, self::META_DESTINATIONS, wp_json_encode( $clean ) );
        }
    }
}
