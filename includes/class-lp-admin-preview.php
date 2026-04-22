<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Destination preview meta box on the link edit screen.
 *
 * Fetches OG metadata for the current destination URL (reusing LP_Previews'
 * cache) so the admin can spot-check a link without leaving the edit screen.
 * Handy for catching "destination was a product page, now it's a 404" cases.
 */
class LP_Admin_Preview {

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
    }

    public static function add_meta_box() {
        add_meta_box(
            'lp_admin_preview',
            __( 'Destination preview', 'linkpilot' ),
            array( __CLASS__, 'render' ),
            'lp_link',
            'side',
            'low'
        );
    }

    public static function render( $post ) {
        $url = get_post_meta( $post->ID, '_lp_destination_url', true );
        if ( empty( $url ) ) {
            ?>
            <p style="color: #757575; margin: 0;"><?php esc_html_e( 'No destination URL set yet.', 'linkpilot' ); ?></p>
            <?php
            return;
        }

        if ( ! class_exists( 'LP_Previews' ) ) {
            return;
        }

        $data = LP_Previews::get_preview_data( $url );
        if ( empty( $data ) ) {
            ?>
            <p style="color: #a00; margin: 0;">
                <?php esc_html_e( 'Could not fetch a preview for this destination. The URL may be unreachable, blocked, or missing OG metadata.', 'linkpilot' ); ?>
            </p>
            <?php
            return;
        }

        $title = isset( $data['title'] ) ? $data['title'] : '';
        $desc  = isset( $data['description'] ) ? $data['description'] : '';
        $image = isset( $data['image'] ) ? $data['image'] : '';
        $site  = isset( $data['site_name'] ) ? $data['site_name'] : wp_parse_url( $url, PHP_URL_HOST );
        ?>
        <div class="lp-admin-preview">
            <?php if ( ! empty( $image ) ) : ?>
                <img src="<?php echo esc_url( $image ); ?>" alt="" style="width: 100%; height: auto; margin-bottom: 8px; border-radius: 4px;" />
            <?php endif; ?>
            <p style="font-size: 11px; color: #646970; margin: 0 0 4px; text-transform: uppercase;"><?php echo esc_html( $site ); ?></p>
            <?php if ( ! empty( $title ) ) : ?>
                <p style="font-weight: 600; margin: 0 0 6px;"><?php echo esc_html( $title ); ?></p>
            <?php endif; ?>
            <?php if ( ! empty( $desc ) ) : ?>
                <p style="color: #50575e; font-size: 13px; margin: 0 0 8px;"><?php echo esc_html( wp_trim_words( $desc, 30, '…' ) ); ?></p>
            <?php endif; ?>
            <p style="margin: 0; font-size: 11px; color: #757575;">
                <?php esc_html_e( 'Cached for 7 days.', 'linkpilot' ); ?>
            </p>
        </div>
        <?php
    }
}
