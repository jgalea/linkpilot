<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Activator {

    public static function activate() {
        LP_Clicks_DB::create_table();
        self::set_default_options();
        LP_Link_Health::schedule();
        flush_rewrite_rules();
    }

    private static function set_default_options() {
        $defaults = array(
            'lp_link_prefix'           => 'go',
            'lp_redirect_type'         => '307',
            'lp_nofollow'              => 'yes',
            'lp_sponsored'             => 'no',
            'lp_new_window'            => 'yes',
            'lp_pass_query_str'        => 'no',
            'lp_enable_click_tracking' => 'yes',
            'lp_disable_ip_collection' => 'yes',
            'lp_enable_link_fixer'     => 'yes',
            'lp_excluded_bots'         => "googlebot\nbingbot\nslurp\nduckduckbot\nbaiduspider\nyandexbot\nsogou\nexabot\nia_archiver\nfacebot\nahrefs\nsemrush\nmj12bot\ndotbot\npetalbot",
        );
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
        add_option( 'lp_db_version', '1.0' );
        add_option( 'lp_installed_version', LP_VERSION );
    }
}
