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
            KEY idx_link_date (link_id, clicked_at),
            KEY idx_is_bot_date (is_bot, clicked_at)
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
        $norm  = self::referrer_normalize_expr();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$norm} AS referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE link_id = %d AND clicked_at >= %s AND is_bot = 0 AND referrer != ''
             GROUP BY {$norm}
             ORDER BY clicks DESC
             LIMIT %d",
            $link_id,
            $since,
            $limit
        ) );
    }

    /**
     * Site-wide clicks per day for a rolling window.
     *
     * @param int $days
     * @return array of objects { click_date, clicks }
     */
    public static function get_site_clicks_by_day( $days = 30 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) as click_date, COUNT(*) as clicks
             FROM {$table}
             WHERE clicked_at >= %s AND is_bot = 0
             GROUP BY DATE(clicked_at)
             ORDER BY click_date ASC",
            $since
        ) );
    }

    /**
     * Top referrers across all links.
     *
     * @param int $days
     * @param int $limit
     * @return array of objects { referrer, clicks }
     */
    public static function get_site_top_referrers( $days = 30, $limit = 50 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $norm  = self::referrer_normalize_expr();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$norm} AS referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE clicked_at >= %s AND is_bot = 0 AND referrer != ''
             GROUP BY {$norm}
             ORDER BY clicks DESC
             LIMIT %d",
            $since,
            $limit
        ) );
    }

    /**
     * SQL expression that collapses referrer variants (query string / fragment /
     * trailing slash) into a single canonical form for GROUP BY.
     */
    private static function referrer_normalize_expr() {
        return "TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '?', 1), '#', 1))";
    }

    /**
     * Stream raw click rows for CSV export. Returns a generator-friendly array
     * batch. Uses OFFSET/LIMIT to avoid loading all at once.
     *
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public static function get_rows_batch( $offset = 0, $limit = 1000 ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, link_id, clicked_at, referrer, country_code, is_bot
             FROM {$table}
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A );
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
