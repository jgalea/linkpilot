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
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( 'LP_Post_Type', 'register' ), 0 );
        add_action( 'init', array( 'LP_Redirect', 'maybe_redirect_prefixed' ), 1 );
        add_action( 'template_redirect', array( 'LP_Redirect', 'maybe_redirect_fallback' ) );

        LP_Link_Health::init();

        if ( is_admin() ) {
            LP_Admin::init();
            LP_Settings::init();
            LP_CSV::init();
            LP_Setup_Wizard::init();
            LP_QR::init();
        }

        LP_Link_Fixer::init();
        LP_Editor::init();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'linkpilot', false, dirname( LP_PLUGIN_BASENAME ) . '/languages' );
    }
}
