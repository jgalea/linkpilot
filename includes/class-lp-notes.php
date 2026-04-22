<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-link admin-only notes.
 *
 * A free-form textarea stored in `_lp_notes` meta. Never rendered on the
 * frontend — purely a place for the admin to leave context for themselves
 * (e.g. "contact: sam@example.com, payout monthly, cookie 30 days").
 */
class LP_Notes {

    const META = '_lp_notes';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save' ), 20, 2 );
    }

    public static function add_meta_box() {
        add_meta_box(
            'lp_notes',
            __( 'Notes (admin only)', 'linkpilot' ),
            array( __CLASS__, 'render' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    public static function render( $post ) {
        wp_nonce_field( 'lp_notes_save', 'lp_notes_nonce' );
        $value = get_post_meta( $post->ID, self::META, true );
        ?>
        <textarea name="lp_notes" rows="4" class="widefat" placeholder="<?php esc_attr_e( 'Contact info, commission terms, reminders — anything you need. Visible only in the admin.', 'linkpilot' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        <?php
    }

    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['lp_notes_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_notes_nonce'] ) ), 'lp_notes_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['lp_notes'] ) ) {
            $value = sanitize_textarea_field( wp_unslash( $_POST['lp_notes'] ) );
            if ( '' === $value ) {
                delete_post_meta( $post_id, self::META );
            } else {
                update_post_meta( $post_id, self::META, $value );
            }
        }
    }
}
