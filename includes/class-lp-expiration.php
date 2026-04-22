<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Link expiration / scheduling.
 *
 * Each link can optionally have an expiration date and an expiration action:
 *   - disable:  the redirect stops working; clicks hit the fallback URL
 *   - fallback: redirect to a secondary destination instead
 *
 * Enforced at redirect time via the lp_redirect_destination filter, and by a
 * daily cron that flips expired links to post_status=draft so they stop
 * appearing in the public UI.
 */
class LP_Expiration {

    const META_EXPIRES_AT   = '_lp_expires_at';
    const META_ACTION       = '_lp_expires_action';
    const META_FALLBACK_URL = '_lp_fallback_url';

    const CRON_HOOK = 'lp_expire_links';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'maybe_block_or_fallback' ), 5, 2 );
        add_filter( 'lp_redirect_should_block', array( __CLASS__, 'maybe_block' ), 10, 2 );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'expire_links' ) );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
            add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
        }
    }

    /**
     * Called on plugin deactivation to unschedule the cron job.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * If the link is expired with action=fallback, replace the destination.
     *
     * @param string  $destination Destination URL.
     * @param LP_Link $link        Link object.
     * @return string
     */
    public static function maybe_block_or_fallback( $destination, $link ) {
        $expiry = self::get_expiry_timestamp( $link->get_id() );
        if ( ! $expiry || time() < $expiry ) {
            return $destination;
        }

        $action = get_post_meta( $link->get_id(), self::META_ACTION, true );
        if ( 'fallback' === $action ) {
            $fallback = get_post_meta( $link->get_id(), self::META_FALLBACK_URL, true );
            if ( ! empty( $fallback ) ) {
                return esc_url_raw( $fallback );
            }
        }

        return $destination;
    }

    /**
     * If the link is expired with action=disable, tell LP_Redirect to abort.
     *
     * @param bool    $should_block Current block decision.
     * @param LP_Link $link         Link object.
     * @return bool
     */
    public static function maybe_block( $should_block, $link ) {
        if ( $should_block ) {
            return true;
        }
        $expiry = self::get_expiry_timestamp( $link->get_id() );
        if ( ! $expiry || time() < $expiry ) {
            return false;
        }
        $action = get_post_meta( $link->get_id(), self::META_ACTION, true );
        return 'disable' === $action;
    }

    /**
     * Daily cron: move published links whose expiry has passed to draft,
     * so they stop appearing in listings. The actual redirect-time behavior
     * is handled by the filters above and works even before the cron runs.
     */
    public static function expire_links() {
        $args = array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => self::META_EXPIRES_AT,
                    'value'   => gmdate( 'Y-m-d H:i:s' ),
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ),
            ),
        );
        $ids = get_posts( $args );
        foreach ( $ids as $id ) {
            $action = get_post_meta( $id, self::META_ACTION, true );
            if ( 'disable' !== $action ) {
                continue;
            }
            wp_update_post( array(
                'ID'          => $id,
                'post_status' => 'draft',
            ) );
        }
    }

    /**
     * Parse stored "expires_at" into a timestamp (UTC).
     *
     * @param int $link_id Link post ID.
     * @return int|null Timestamp or null if no expiry.
     */
    public static function get_expiry_timestamp( $link_id ) {
        $raw = get_post_meta( $link_id, self::META_EXPIRES_AT, true );
        if ( empty( $raw ) ) {
            return null;
        }
        $ts = strtotime( $raw . ' UTC' );
        return $ts ?: null;
    }

    /**
     * Register meta box.
     */
    public static function add_meta_box() {
        add_meta_box(
            'lp_expiration',
            __( 'Expiration', 'linkpilot' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'side',
            'default'
        );
    }

    /**
     * Render meta box: datetime input, action select, fallback URL.
     *
     * @param WP_Post $post Link post.
     */
    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lp_expiration_save', 'lp_expiration_nonce' );
        $expires = get_post_meta( $post->ID, self::META_EXPIRES_AT, true );
        $action  = get_post_meta( $post->ID, self::META_ACTION, true ) ?: 'disable';
        $fallback = get_post_meta( $post->ID, self::META_FALLBACK_URL, true );
        // datetime-local format: YYYY-MM-DDTHH:MM.
        $input_val = '';
        if ( $expires ) {
            $ts = strtotime( $expires );
            if ( $ts ) {
                $input_val = gmdate( 'Y-m-d\TH:i', $ts );
            }
        }
        ?>
        <p>
            <label for="lp_expires_at"><strong><?php esc_html_e( 'Expires at (UTC)', 'linkpilot' ); ?></strong></label><br />
            <input type="datetime-local" id="lp_expires_at" name="lp_expires_at"
                value="<?php echo esc_attr( $input_val ); ?>" class="widefat" />
            <span class="description" style="display:block;margin-top:4px;"><?php esc_html_e( 'Leave blank for no expiry.', 'linkpilot' ); ?></span>
        </p>
        <p>
            <label for="lp_expires_action"><strong><?php esc_html_e( 'When expired', 'linkpilot' ); ?></strong></label><br />
            <select id="lp_expires_action" name="lp_expires_action" class="widefat">
                <option value="disable" <?php selected( $action, 'disable' ); ?>><?php esc_html_e( 'Disable the link (returns 404)', 'linkpilot' ); ?></option>
                <option value="fallback" <?php selected( $action, 'fallback' ); ?>><?php esc_html_e( 'Redirect to a fallback URL', 'linkpilot' ); ?></option>
            </select>
        </p>
        <p>
            <label for="lp_fallback_url"><strong><?php esc_html_e( 'Fallback URL', 'linkpilot' ); ?></strong></label><br />
            <input type="url" id="lp_fallback_url" name="lp_fallback_url"
                value="<?php echo esc_url( $fallback ); ?>" class="widefat" placeholder="https://" />
        </p>
        <?php
    }

    /**
     * Save meta box values.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lp_expiration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_expiration_nonce'] ) ), 'lp_expiration_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['lp_expires_at'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['lp_expires_at'] ) );
            if ( '' === $raw ) {
                delete_post_meta( $post_id, self::META_EXPIRES_AT );
            } else {
                // Input is "YYYY-MM-DDTHH:MM" local-to-the-browser; we store as UTC.
                $ts = strtotime( $raw . ' UTC' );
                if ( $ts ) {
                    update_post_meta( $post_id, self::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', $ts ) );
                }
            }
        }

        if ( isset( $_POST['lp_expires_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_POST['lp_expires_action'] ) );
            if ( in_array( $action, array( 'disable', 'fallback' ), true ) ) {
                update_post_meta( $post_id, self::META_ACTION, $action );
            }
        }

        if ( isset( $_POST['lp_fallback_url'] ) ) {
            $url = esc_url_raw( wp_unslash( $_POST['lp_fallback_url'] ) );
            if ( '' === $url ) {
                delete_post_meta( $post_id, self::META_FALLBACK_URL );
            } else {
                update_post_meta( $post_id, self::META_FALLBACK_URL, $url );
            }
        }
    }
}
