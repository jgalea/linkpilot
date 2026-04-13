<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Clicks_DB {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lp_clicks';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT UNSIGNED NOT NULL,
            clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            referrer VARCHAR(2048) DEFAULT '',
            country_code CHAR(2) DEFAULT '',
            user_agent_hash CHAR(32) DEFAULT '',
            is_bot TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_link_id (link_id),
            KEY idx_clicked_at (clicked_at),
            KEY idx_link_date (link_id, clicked_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ) {
        global $wpdb;
        return $wpdb->insert( self::get_table_name(), $data, array(
            '%d',   // link_id
            '%s',   // clicked_at
            '%s',   // referrer
            '%s',   // country_code
            '%s',   // user_agent_hash
            '%d',   // is_bot
        ) );
    }

    public static function get_clicks_for_link( $link_id, $days = 30 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d AND clicked_at >= %s AND is_bot = 0",
            $link_id,
            $since
        ) );
    }

    public static function get_total_clicks( $link_id ) {
        global $wpdb;
        $table = self::get_table_name();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d AND is_bot = 0",
            $link_id
        ) );
    }

    public static function get_clicks_by_day( $link_id, $days = 30 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) as click_date, COUNT(*) as clicks
             FROM {$table}
             WHERE link_id = %d AND clicked_at >= %s AND is_bot = 0
             GROUP BY DATE(clicked_at)
             ORDER BY click_date ASC",
            $link_id,
            $since
        ) );
    }

    public static function get_top_referrers( $link_id, $days = 30, $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE link_id = %d AND clicked_at >= %s AND is_bot = 0 AND referrer != ''
             GROUP BY referrer
             ORDER BY clicks DESC
             LIMIT %d",
            $link_id,
            $since,
            $limit
        ) );
    }

    public static function cleanup_old_data( $days = 365 ) {
        global $wpdb;
        $table  = self::get_table_name();
        $before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE clicked_at < %s",
            $before
        ) );
    }
}
