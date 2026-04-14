<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Scheduling {

    const META_START    = '_lpp_schedule_start';
    const META_EXPIRE   = '_lpp_schedule_expire';
    const META_FALLBACK = '_lpp_expire_redirect';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_schedule' ), 20, 2 );
        add_filter( 'lp_redirect_should_block', array( __CLASS__, 'maybe_block' ), 10, 2 );
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
    }

    public static function maybe_block( $block, $link ) {
        if ( $block ) {
            return $block;
        }

        $now      = current_time( 'timestamp', true );
        $start    = get_post_meta( $link->get_id(), self::META_START, true );
        $expire   = get_post_meta( $link->get_id(), self::META_EXPIRE, true );
        $fallback = get_post_meta( $link->get_id(), self::META_FALLBACK, true );

        if ( $start ) {
            $start_ts = strtotime( $start . ' UTC' );
            if ( $start_ts && $now < $start_ts ) {
                return true;
            }
        }

        if ( $expire && ! $fallback ) {
            $expire_ts = strtotime( $expire . ' UTC' );
            if ( $expire_ts && $now >= $expire_ts ) {
                return true;
            }
        }

        return false;
    }

    public static function apply_schedule( $destination, $link ) {
        $expire   = get_post_meta( $link->get_id(), self::META_EXPIRE, true );
        $fallback = get_post_meta( $link->get_id(), self::META_FALLBACK, true );

        if ( ! $expire || ! $fallback ) {
            return $destination;
        }

        $now       = current_time( 'timestamp', true );
        $expire_ts = strtotime( $expire . ' UTC' );

        if ( $expire_ts && $now >= $expire_ts ) {
            return $fallback;
        }

        return $destination;
    }

    public static function add_meta_box() {
        add_meta_box(
            'lpp_scheduling',
            __( 'Link Scheduling', 'linkpilot-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lpp_schedule_save', 'lpp_schedule_nonce' );
        $start    = get_post_meta( $post->ID, self::META_START, true );
        $expire   = get_post_meta( $post->ID, self::META_EXPIRE, true );
        $fallback = get_post_meta( $post->ID, self::META_FALLBACK, true );
        ?>
        <p>
            <label><strong><?php esc_html_e( 'Start (UTC)', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="datetime-local" name="lpp_schedule_start" value="<?php echo esc_attr( $start ); ?>" class="widefat" />
            <span class="description"><?php esc_html_e( 'Before this time, link returns 404.', 'linkpilot-pro' ); ?></span>
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Expire (UTC)', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="datetime-local" name="lpp_schedule_expire" value="<?php echo esc_attr( $expire ); ?>" class="widefat" />
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Expiry Fallback URL', 'linkpilot-pro' ); ?></strong></label><br />
            <input type="url" name="lpp_expire_redirect" value="<?php echo esc_attr( $fallback ); ?>" class="widefat" placeholder="https://..." />
            <span class="description"><?php esc_html_e( 'After expiry, redirect here instead of 404.', 'linkpilot-pro' ); ?></span>
        </p>
        <?php
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lpp_schedule_nonce'] ) || ! wp_verify_nonce( $_POST['lpp_schedule_nonce'], 'lpp_schedule_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $start    = isset( $_POST['lpp_schedule_start'] ) ? sanitize_text_field( $_POST['lpp_schedule_start'] ) : '';
        $expire   = isset( $_POST['lpp_schedule_expire'] ) ? sanitize_text_field( $_POST['lpp_schedule_expire'] ) : '';
        $fallback = isset( $_POST['lpp_expire_redirect'] ) ? esc_url_raw( $_POST['lpp_expire_redirect'] ) : '';

        $start  ? update_post_meta( $post_id, self::META_START, $start )   : delete_post_meta( $post_id, self::META_START );
        $expire ? update_post_meta( $post_id, self::META_EXPIRE, $expire ) : delete_post_meta( $post_id, self::META_EXPIRE );
        $fallback ? update_post_meta( $post_id, self::META_FALLBACK, $fallback ) : delete_post_meta( $post_id, self::META_FALLBACK );
    }
}
