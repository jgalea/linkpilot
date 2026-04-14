<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save_link_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_filter( 'manage_lp_link_posts_columns', array( __CLASS__, 'custom_columns' ) );
        add_action( 'manage_lp_link_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'add_dashboard_page' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_migration_page' ) );
        add_action( 'admin_post_lp_check_health_now', array( __CLASS__, 'handle_health_check' ) );
    }

    public static function add_dashboard_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Dashboard', 'linkpilot' ),
            __( 'Dashboard', 'linkpilot' ),
            'edit_posts',
            'lp-dashboard',
            array( __CLASS__, 'render_dashboard' ),
            0
        );
    }

    public static function render_dashboard() {
        include LP_PLUGIN_DIR . 'views/admin-dashboard.php';
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'lp_link_settings',
            __( 'Link Settings', 'linkpilot' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'normal',
            'high'
        );

        add_meta_box(
            'lp_link_stats',
            __( 'Click Statistics', 'linkpilot' ),
            array( __CLASS__, 'render_stats_box' ),
            'lp_link',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lp_save_link_meta', 'lp_link_nonce' );
        $link = new LP_Link( $post->ID );
        include LP_PLUGIN_DIR . 'views/link-meta-box.php';
    }

    public static function render_stats_box( $post ) {
        $total  = LP_Clicks_DB::get_total_clicks( $post->ID );
        $last30 = LP_Clicks_DB::get_clicks_for_link( $post->ID, 30 );
        $last7  = LP_Clicks_DB::get_clicks_for_link( $post->ID, 7 );
        $bar_pct = $total > 0 ? round( ( $last30 / $total ) * 100 ) : 0;
        ?>
        <div class="lp-stats-box">
            <div class="lp-stats-grid">
                <div class="lp-stat-row">
                    <span class="lp-stat-row-label"><?php esc_html_e( 'Total clicks', 'linkpilot' ); ?></span>
                    <span class="lp-stat-row-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
                </div>
                <div class="lp-stat-row">
                    <span class="lp-stat-row-label"><?php esc_html_e( 'Last 30 days', 'linkpilot' ); ?></span>
                    <span class="lp-stat-row-value"><?php echo esc_html( number_format_i18n( $last30 ) ); ?></span>
                </div>
                <div class="lp-stat-row">
                    <span class="lp-stat-row-label"><?php esc_html_e( 'Last 7 days', 'linkpilot' ); ?></span>
                    <span class="lp-stat-row-value"><?php echo esc_html( number_format_i18n( $last7 ) ); ?></span>
                </div>
            </div>
            <?php if ( $total > 0 ) : ?>
            <div class="lp-mini-bar">
                <div class="lp-mini-bar-label">
                    <span><?php esc_html_e( '30-day share', 'linkpilot' ); ?></span>
                    <span><?php echo esc_html( $bar_pct ); ?>%</span>
                </div>
                <div class="lp-mini-bar-track">
                    <div class="lp-mini-bar-fill" style="width: <?php echo esc_attr( $bar_pct ); ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save_link_meta( $post_id, $post ) {
        if ( ! isset( $_POST['lp_link_nonce'] ) || ! wp_verify_nonce( $_POST['lp_link_nonce'], 'lp_save_link_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Use esc_url_raw for destination URL specifically
        if ( isset( $_POST['lp_destination_url'] ) ) {
            update_post_meta( $post_id, '_lp_destination_url', esc_url_raw( $_POST['lp_destination_url'] ) );
        }

        $text_fields = array( 'redirect_type', 'nofollow', 'sponsored', 'new_window', 'pass_query_str', 'css_classes', 'rel_tags' );
        foreach ( $text_fields as $field ) {
            $post_key = 'lp_' . $field;
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, '_lp_' . $field, sanitize_text_field( $_POST[ $post_key ] ) );
            }
        }

        if ( isset( $_POST['lp_js_redirect'] ) ) {
            update_post_meta( $post_id, '_lp_js_redirect', sanitize_text_field( $_POST['lp_js_redirect'] ) );
        }
    }

    public static function custom_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $val ) {
            $new[ $key ] = $val;
            if ( $key === 'title' ) {
                $new['lp_destination'] = __( 'Destination', 'linkpilot' );
                $new['lp_cloaked_url'] = __( 'Cloaked URL', 'linkpilot' );
                $new['lp_clicks_30d']  = __( 'Clicks (30d)', 'linkpilot' );
                $new['lp_clicks']      = __( 'Clicks (all)', 'linkpilot' );
                $new['lp_health']      = __( 'Health', 'linkpilot' );
                $new['lp_qr']          = __( 'QR', 'linkpilot' );
            }
        }
        return $new;
    }

    public static function column_content( $column, $post_id ) {
        $link = new LP_Link( $post_id );
        switch ( $column ) {
            case 'lp_destination':
                $url = $link->get_destination_url();
                echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( wp_trim_words( $url, 5, '...' ) ) . '</a>';
                break;
            case 'lp_cloaked_url':
                $cloaked = $link->get_cloaked_url();
                echo '<code>' . esc_html( str_replace( home_url(), '', $cloaked ) ) . '</code>';
                break;
            case 'lp_clicks_30d':
                $count = LP_Clicks_DB::get_clicks_for_link( $post_id, 30 );
                echo '<span class="lp-click-count" title="' . esc_attr__( 'Human clicks in the last 30 days (bots excluded)', 'linkpilot' ) . '">' . esc_html( number_format_i18n( $count ) ) . '</span>';
                break;
            case 'lp_clicks':
                $total = LP_Clicks_DB::get_total_clicks( $post_id );
                echo '<span class="lp-click-count" title="' . esc_attr__( 'All-time human clicks recorded by LinkPilot (bots excluded). Includes clicks migrated from other plugins.', 'linkpilot' ) . '">' . esc_html( number_format_i18n( $total ) ) . '</span>';
                break;
            case 'lp_health':
                $status = get_post_meta( $post_id, LP_Link_Health::META_STATUS, true );
                $code   = get_post_meta( $post_id, LP_Link_Health::META_HTTP_CODE, true );
                $color  = LP_Link_Health::get_status_color( $status ?: 'unchecked' );
                $label  = LP_Link_Health::get_status_label( $status ?: 'unchecked' );
                echo '<span style="color:' . esc_attr( $color ) . '; font-weight: 600;">' . esc_html( $label ) . '</span>';
                if ( $code ) {
                    echo ' <small>(' . esc_html( $code ) . ')</small>';
                }
                break;
            case 'lp_qr':
                echo '<a href="' . esc_url( LP_QR::get_download_url( $post_id ) ) . '" title="' . esc_attr__( 'Download QR code', 'linkpilot' ) . '"><span class="dashicons dashicons-download"></span></a>';
                break;
        }
    }

    public static function add_migration_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Migrate', 'linkpilot' ),
            __( 'Migrate', 'linkpilot' ),
            'manage_options',
            'lp-migrate',
            array( __CLASS__, 'render_migration_page' )
        );
    }

    public static function render_migration_page() {
        include LP_PLUGIN_DIR . 'views/admin-migration.php';
    }

    public static function handle_health_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'lp_check_health_now' );

        $checked = LP_Link_Health::check_all_now();

        wp_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-dashboard&lp_health_checked=' . $checked ) );
        exit;
    }

    public static function enqueue_admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'lp_link' ) {
            return;
        }
        wp_enqueue_style( 'lp-admin', LP_PLUGIN_URL . 'assets/css/admin.css', array(), LP_VERSION );
        wp_enqueue_script( 'lp-admin', LP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), LP_VERSION, true );
    }
}
