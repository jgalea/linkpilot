<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LinkPilot {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'init', array( 'LP_Post_Type', 'register' ), 0 );
        add_action( 'init', array( 'LP_Redirect', 'maybe_redirect_prefixed' ), 1 );
        add_action( 'template_redirect', array( 'LP_Redirect', 'maybe_redirect_fallback' ) );

        LP_Link::init();
        LP_Link_Health::init();
        LP_Scanner::init();
        LP_Scanner_Notifier::init();

        if ( is_admin() ) {
            LP_Admin::init();
            LP_Settings::init();
            LP_CSV::init();
            LP_Setup_Wizard::init();
            LP_QR::init();
            LP_Job_Runner::init();
            LP_Scanner_Admin::init();
            LP_Bulk_Edit::init();
            LP_Reports::init();
            LP_Notes::init();
            LP_Admin_Preview::init();
            LP_Dashboard_Widget::init();
            LP_Backup::init();
        }

        LP_Webhook::init();
        LP_Destinations::init();
        LP_Conditional::init();
        LP_REST_API::init();
        LP_Digest::init();
        LP_Campaigns::init();
        LP_Disclosure::init();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once LP_PLUGIN_DIR . 'includes/class-lp-cli.php';
        }

        LP_Link_Fixer::init();
        LP_Editor::init();
        LP_External_Links::init();
        LP_UTM::init();
        LP_Expiration::init();
        LP_Keyword_Links::init();
        LP_Redirects::init();
        LP_404_Log::init();
        LP_Previews::init();
        LP_Link_Safety::init();
    }
}
