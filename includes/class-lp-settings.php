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
        add_settings_section( 'lp_link_defaults', __( 'Link Defaults', 'linkpilot' ), null, 'lp-settings' );

        self::add_field( 'lp_link_prefix', __( 'URL Prefix', 'linkpilot' ), 'text', 'lp_link_defaults', 'go' );
        self::add_field( 'lp_redirect_type', __( 'Default Redirect Type', 'linkpilot' ), 'select', 'lp_link_defaults', '307', array(
            '301' => '301 (Permanent)',
            '302' => '302 (Temporary)',
            '307' => '307 (Temporary Strict)',
        ) );
        self::add_field( 'lp_nofollow', __( 'Default Nofollow', 'linkpilot' ), 'select', 'lp_link_defaults', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'lp_sponsored', __( 'Default Sponsored', 'linkpilot' ), 'select', 'lp_link_defaults', 'no', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'lp_new_window', __( 'Default Open in New Window', 'linkpilot' ), 'select', 'lp_link_defaults', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'lp_pass_query_str', __( 'Default Pass Query Strings', 'linkpilot' ), 'select', 'lp_link_defaults', 'no', array( 'yes' => 'Yes', 'no' => 'No' ) );

        add_settings_section( 'lp_tracking', __( 'Click Tracking', 'linkpilot' ), null, 'lp-settings' );

        self::add_field( 'lp_enable_click_tracking', __( 'Enable Click Tracking', 'linkpilot' ), 'select', 'lp_tracking', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'lp_disable_ip_collection', __( 'Disable IP Collection (GDPR)', 'linkpilot' ), 'select', 'lp_tracking', 'yes', array( 'yes' => 'Yes (recommended)', 'no' => 'No' ) );
        self::add_field( 'lp_excluded_bots', __( 'Excluded Bots (one per line)', 'linkpilot' ), 'textarea', 'lp_tracking' );

        add_settings_section( 'lp_modules', __( 'Modules', 'linkpilot' ), null, 'lp-settings' );

        self::add_field( 'lp_enable_link_fixer', __( 'Enable Link Fixer', 'linkpilot' ), 'select', 'lp_modules', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
    }

    private static function add_field( $id, $title, $type, $section, $default = '', $options = array() ) {
        register_setting( 'lp_settings_group', $id, array(
            'sanitize_callback' => $type === 'textarea' ? 'sanitize_textarea_field' : 'sanitize_text_field',
            'default'           => $default,
        ) );

        add_settings_field( $id, $title, function() use ( $id, $type, $default, $options ) {
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
        }, 'lp-settings', $section );
    }

    public static function render_page() {
        include LP_PLUGIN_DIR . 'views/admin-settings.php';
    }
}
