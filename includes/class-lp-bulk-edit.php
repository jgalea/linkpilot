<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bulk edit for the lp_link list table.
 *
 * Adds one-click bulk actions to update rel / target / redirect type across
 * selected links without opening each one.
 */
class LP_Bulk_Edit {

    /**
     * Map of action keys -> labels + meta key + meta value.
     *
     * @return array
     */
    private static function actions() {
        return array(
            'lp_nofollow_yes'    => array( __( 'Nofollow: Yes', 'linkpilot' ),    '_lp_nofollow',       'yes' ),
            'lp_nofollow_no'     => array( __( 'Nofollow: No', 'linkpilot' ),     '_lp_nofollow',       'no' ),
            'lp_sponsored_yes'   => array( __( 'Sponsored: Yes', 'linkpilot' ),   '_lp_sponsored',      'yes' ),
            'lp_sponsored_no'    => array( __( 'Sponsored: No', 'linkpilot' ),    '_lp_sponsored',      'no' ),
            'lp_newwin_yes'      => array( __( 'New window: Yes', 'linkpilot' ),  '_lp_new_window',     'yes' ),
            'lp_newwin_no'       => array( __( 'New window: No', 'linkpilot' ),   '_lp_new_window',     'no' ),
            'lp_redirect_301'    => array( __( 'Redirect: 301', 'linkpilot' ),    '_lp_redirect_type',  '301' ),
            'lp_redirect_302'    => array( __( 'Redirect: 302', 'linkpilot' ),    '_lp_redirect_type',  '302' ),
            'lp_redirect_307'    => array( __( 'Redirect: 307', 'linkpilot' ),    '_lp_redirect_type',  '307' ),
        );
    }

    public static function init() {
        add_filter( 'bulk_actions-edit-lp_link', array( __CLASS__, 'register_actions' ) );
        add_filter( 'handle_bulk_actions-edit-lp_link', array( __CLASS__, 'handle' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
    }

    /**
     * Add LinkPilot bulk actions to the dropdown.
     *
     * @param array $actions
     * @return array
     */
    public static function register_actions( $actions ) {
        foreach ( self::actions() as $key => $row ) {
            $actions[ $key ] = 'LinkPilot · ' . $row[0];
        }
        return $actions;
    }

    /**
     * Handle the selected bulk action.
     *
     * @param string $redirect_to
     * @param string $action
     * @param array  $post_ids
     * @return string
     */
    public static function handle( $redirect_to, $action, $post_ids ) {
        $map = self::actions();
        if ( ! isset( $map[ $action ] ) ) {
            return $redirect_to;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return $redirect_to;
        }
        list( , $meta_key, $meta_value ) = $map[ $action ];
        $count = 0;
        foreach ( $post_ids as $pid ) {
            if ( ! current_user_can( 'edit_post', $pid ) ) {
                continue;
            }
            update_post_meta( (int) $pid, $meta_key, $meta_value );
            $count++;
        }
        return add_query_arg( array( 'lp_bulk_updated' => $count ), $redirect_to );
    }

    /**
     * Notice after a bulk update.
     */
    public static function admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
        if ( ! isset( $_GET['lp_bulk_updated'] ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = absint( wp_unslash( $_GET['lp_bulk_updated'] ) );
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                /* translators: %d: number of links updated */
                echo esc_html( sprintf( _n( '%d link updated.', '%d links updated.', $count, 'linkpilot' ), $count ) );
                ?>
            </p>
        </div>
        <?php
    }
}
