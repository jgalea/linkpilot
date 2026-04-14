<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Settings {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'update_option_lp_link_prefix', array( __CLASS__, 'schedule_rewrite_flush' ) );
        add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
    }

    public static function schedule_rewrite_flush() {
        update_option( 'lp_flush_rewrite_rules', true );
    }

    public static function maybe_flush_rewrites() {
        if ( get_option( 'lp_flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
            delete_option( 'lp_flush_rewrite_rules' );
        }
    }

    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'LinkPilot Settings', 'linkpilot' ),
            __( 'Settings', 'linkpilot' ),
            'manage_options',
            'lp-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        add_settings_section( 'lp_link_defaults', __( 'Link Defaults', 'linkpilot' ), array( __CLASS__, 'link_defaults_intro' ), 'lp-settings' );

        self::add_field( 'lp_link_prefix', __( 'URL Prefix', 'linkpilot' ), 'text', 'lp_link_defaults', 'go', array(),
            __( 'The path segment used for all cloaked links. Changing this rewrites your URLs — keep old URLs working by leaving it at "go" unless you have a reason.', 'linkpilot' )
        );
        self::add_field( 'lp_redirect_type', __( 'Default Redirect Type', 'linkpilot' ), 'select', 'lp_link_defaults', '307', array(
            '301' => '301 (Permanent)',
            '302' => '302 (Temporary)',
            '307' => '307 (Temporary Strict)',
        ),
            __( '307 is the safest default for affiliate links — search engines treat the destination as temporary so no SEO signal leaks. Use 301 only if you want Google to associate your link with the destination. Overridable per link.', 'linkpilot' )
        );
        self::add_field( 'lp_nofollow', __( 'Default Nofollow', 'linkpilot' ), 'select', 'lp_link_defaults', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Adds rel="nofollow" so search engines don\'t pass link equity to the destination. Recommended for affiliate and sponsored links. Overridable per link.', 'linkpilot' )
        );
        self::add_field( 'lp_sponsored', __( 'Default Sponsored', 'linkpilot' ), 'select', 'lp_link_defaults', 'no', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Adds rel="sponsored" to disclose paid relationships to Google. Required for affiliate links under FTC and Google guidelines. Overridable per link.', 'linkpilot' )
        );
        self::add_field( 'lp_new_window', __( 'Default Open in New Window', 'linkpilot' ), 'select', 'lp_link_defaults', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Adds target="_blank" so clicked links open in a new tab. Keeps visitors on your site. Overridable per link.', 'linkpilot' )
        );
        self::add_field( 'lp_pass_query_str', __( 'Default Pass Query Strings', 'linkpilot' ), 'select', 'lp_link_defaults', 'no', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Forwards ?params from the cloaked URL to the destination (useful for appending campaign IDs). Off by default to prevent affiliate cookie-stuffing attacks.', 'linkpilot' )
        );

        add_settings_section( 'lp_tracking', __( 'Click Tracking', 'linkpilot' ), array( __CLASS__, 'tracking_intro' ), 'lp-settings' );

        self::add_field( 'lp_enable_click_tracking', __( 'Enable Click Tracking', 'linkpilot' ), 'select', 'lp_tracking', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Records every click in the LinkPilot database. Needed for the dashboard click stats.', 'linkpilot' )
        );
        self::add_field( 'lp_disable_ip_collection', __( 'Disable IP Collection (GDPR)', 'linkpilot' ), 'select', 'lp_tracking', 'yes', array( 'yes' => 'Yes (recommended)', 'no' => 'No' ),
            __( 'When enabled, LinkPilot never stores visitor IP addresses. Country is derived at click time then the IP is discarded. Required for GDPR compliance without a consent banner.', 'linkpilot' )
        );
        self::add_field( 'lp_excluded_bots', __( 'Excluded Bots (one per line)', 'linkpilot' ), 'textarea', 'lp_tracking', '', array(),
            __( 'User agent fragments that mark a click as bot traffic (bot clicks are recorded but excluded from stats). One pattern per line, case-insensitive substring match. Add custom crawlers you want to ignore.', 'linkpilot' )
        );

        add_settings_section( 'lp_modules', __( 'Modules', 'linkpilot' ), array( __CLASS__, 'modules_intro' ), 'lp-settings' );

        self::add_field( 'lp_enable_link_fixer', __( 'Enable Link Fixer', 'linkpilot' ), 'select', 'lp_modules', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Frontend JS that syncs rel, target and class attributes on cloaked links in your content to match your current per-link settings. Lets you update "nofollow" or "open in new window" across every post without editing them. Costs one AJAX request per page load. Safe to disable if you configure link attributes once and rarely change them.', 'linkpilot' )
        );
        self::add_field( 'lp_enable_qr', __( 'QR Code Column', 'linkpilot' ), 'select', 'lp_modules', 'no', array( 'yes' => 'Yes', 'no' => 'No' ),
            __( 'Shows a QR download icon on the link list so you can get a printable QR code for any cloaked URL. Uses api.qrserver.com to generate on demand.', 'linkpilot' )
        );
    }

    public static function link_defaults_intro() {
        echo '<p class="description">' . esc_html__( 'These apply to every new link you create. Every setting can be overridden per link on the link edit screen.', 'linkpilot' ) . '</p>';
    }

    public static function tracking_intro() {
        echo '<p class="description">' . esc_html__( 'How LinkPilot records clicks. Settings here affect what gets stored and what shows up in your dashboard stats.', 'linkpilot' ) . '</p>';
    }

    public static function modules_intro() {
        echo '<p class="description">' . esc_html__( 'Optional features you can turn on or off without affecting the core redirect engine.', 'linkpilot' ) . '</p>';
    }

    private static function add_field( $id, $title, $type, $section, $default = '', $options = array(), $description = '' ) {
        register_setting( 'lp_settings_group', $id, array(
            'sanitize_callback' => $type === 'textarea' ? 'sanitize_textarea_field' : 'sanitize_text_field',
            'default'           => $default,
        ) );

        add_settings_field( $id, $title, function() use ( $id, $type, $default, $options, $description ) {
            $value = get_option( $id, $default );
            switch ( $type ) {
                case 'text':
                    echo '<input type="text" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
                    if ( $id === 'lp_link_prefix' ) {
                        echo '<p class="description">' . sprintf( esc_html__( 'Your links will look like: %s/%s/your-link/', 'linkpilot' ), esc_html( home_url() ), esc_html( $value ) ) . '</p>';
                    }
                    break;
                case 'select':
                    echo '<select name="' . esc_attr( $id ) . '">';
                    foreach ( $options as $opt_val => $opt_label ) {
                        echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
                    }
                    echo '</select>';
                    break;
                case 'textarea':
                    echo '<textarea name="' . esc_attr( $id ) . '" rows="6" class="large-text">' . esc_textarea( $value ) . '</textarea>';
                    break;
            }

            if ( $description ) {
                echo '<p class="description">' . esc_html( $description ) . '</p>';
            }
        }, 'lp-settings', $section );
    }

    public static function render_page() {
        include LP_PLUGIN_DIR . 'views/admin-settings.php';
    }
}
