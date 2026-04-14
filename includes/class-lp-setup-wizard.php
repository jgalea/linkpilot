<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Setup_Wizard {

    public static function init() {
        // Wizard page is always available (re-runnable). Notice and redirect only run
        // until first completion.
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_post_lp_wizard_save', array( __CLASS__, 'handle_save' ) );

        if ( ! get_option( 'lp_setup_complete' ) ) {
            add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
            add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
        }
    }

    public static function add_page() {
        add_submenu_page(
            null,
            __( 'LinkPilot Setup', 'linkpilot' ),
            __( 'Setup', 'linkpilot' ),
            'manage_options',
            'lp-setup',
            array( __CLASS__, 'render' )
        );
    }

    public static function maybe_redirect() {
        if ( ! get_transient( 'lp_activation_redirect' ) ) {
            return;
        }
        delete_transient( 'lp_activation_redirect' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- isset-only check to skip during multi-plugin activation flow.
        if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=lp-setup' ) );
        exit;
    }

    public static function setup_notice() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id === 'admin_page_lp-setup' ) {
            return;
        }
        $allowed = array( 'a' => array( 'href' => array() ) );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: URL to the setup wizard */
                        __( 'Welcome to LinkPilot! <a href="%s">Run the setup wizard</a> to get started.', 'linkpilot' ),
                        esc_url( admin_url( 'admin.php?page=lp-setup' ) )
                    ),
                    $allowed
                );
                ?>
            </p>
        </div>
        <?php
    }

    public static function detect_competitors() {
        $detected = array();

        $migrators = array(
            'thirstyaffiliates'  => 'LP_Migrator_ThirstyAffiliates',
            'prettylinks'        => 'LP_Migrator_PrettyLinks',
            'linkcentral'        => 'LP_Migrator_LinkCentral',
            'easyaffiliatelinks' => 'LP_Migrator_EasyAffiliateLinks',
        );

        foreach ( $migrators as $key => $class ) {
            if ( $class::is_available() ) {
                $detected[ $key ] = array(
                    'name'  => $class::get_source_name(),
                    'count' => $class::get_source_count(),
                );
            }
        }

        return $detected;
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'lp_wizard_save' );

        if ( isset( $_POST['lp_link_prefix'] ) ) {
            update_option( 'lp_link_prefix', sanitize_text_field( wp_unslash( $_POST['lp_link_prefix'] ) ) );
        }
        if ( isset( $_POST['lp_redirect_type'] ) ) {
            update_option( 'lp_redirect_type', sanitize_text_field( wp_unslash( $_POST['lp_redirect_type'] ) ) );
        }
        if ( isset( $_POST['lp_nofollow'] ) ) {
            update_option( 'lp_nofollow', sanitize_text_field( wp_unslash( $_POST['lp_nofollow'] ) ) );
        }
        if ( isset( $_POST['lp_new_window'] ) ) {
            update_option( 'lp_new_window', sanitize_text_field( wp_unslash( $_POST['lp_new_window'] ) ) );
        }

        // If migration was requested AND not already handled by AJAX, run it synchronously.
        // The wizard view sends lp_skip_server_migration=1 because it runs migration via AJAX
        // before submitting the form. This branch is the fallback for browsers without JS.
        if ( ! empty( $_POST['lp_migrate_source'] ) && empty( $_POST['lp_skip_server_migration'] ) ) {
            $migrators = array(
                'thirstyaffiliates'  => 'LP_Migrator_ThirstyAffiliates',
                'prettylinks'        => 'LP_Migrator_PrettyLinks',
                'linkcentral'        => 'LP_Migrator_LinkCentral',
                'easyaffiliatelinks' => 'LP_Migrator_EasyAffiliateLinks',
            );

            $source = sanitize_key( wp_unslash( $_POST['lp_migrate_source'] ) );
            if ( isset( $migrators[ $source ] ) ) {
                $class    = $migrators[ $source ];
                $migrator = new $class();
                $migrator->run();

                if ( ! empty( $_POST['lp_scan_content'] ) ) {
                    $scanner = new LP_Content_Scanner( $migrator->get_id_map(), $class::get_source_name() );
                    $scanner->scan_and_replace();
                }
            }
        }

        update_option( 'lp_setup_complete', true );
        flush_rewrite_rules();

        wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-dashboard&lp_setup_done=1' ) );
        exit;
    }

    public static function render() {
        include LP_PLUGIN_DIR . 'views/admin-wizard.php';
    }
}
