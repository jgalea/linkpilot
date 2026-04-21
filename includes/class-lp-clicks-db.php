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
        list( $from, $to ) = self::days_to_range( $days );
        return self::get_clicks_by_day_range( $link_id, $from, $to );
    }

    public static function get_clicks_by_day_range( $link_id, $from, $to ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) as click_date, COUNT(*) as clicks
             FROM {$table}
             WHERE link_id = %d AND clicked_at >= %s AND clicked_at < %s AND is_bot = 0
             GROUP BY DATE(clicked_at)
             ORDER BY click_date ASC",
            $link_id,
            $from,
            $to
        ) );
    }

    public static function get_top_referrers( $link_id, $days = 30, $limit = 10 ) {
        list( $from, $to ) = self::days_to_range( $days );
        return self::get_top_referrers_range( $link_id, $from, $to, $limit );
    }

    public static function get_top_referrers_range( $link_id, $from, $to, $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name();
        $norm  = self::referrer_normalize_expr();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$norm} AS referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE link_id = %d AND clicked_at >= %s AND clicked_at < %s AND is_bot = 0 AND referrer != ''
             GROUP BY {$norm}
             ORDER BY clicks DESC
             LIMIT %d",
            $link_id,
            $from,
            $to,
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
        list( $from, $to ) = self::days_to_range( $days );
        return self::get_site_clicks_by_range( $from, $to );
    }

    /**
     * Site-wide clicks per day for an explicit UTC range.
     *
     * @param string      $from      'Y-m-d H:i:s' UTC, inclusive.
     * @param string      $to        'Y-m-d H:i:s' UTC, exclusive upper bound
     *                               (use the start of the day AFTER the last
     *                               day you want).
     * @param string|null $tz_offset Optional tz offset like '+02:00' that day
     *                               buckets should be computed in. Null = UTC.
     * @return array of objects { click_date, clicks }
     */
    public static function get_site_clicks_by_range( $from, $to, $tz_offset = null ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $tz_offset ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(CONVERT_TZ(clicked_at, '+00:00', %s)) as click_date, COUNT(*) as clicks
                 FROM {$table}
                 WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0
                 GROUP BY DATE(CONVERT_TZ(clicked_at, '+00:00', %s))
                 ORDER BY click_date ASC",
                $tz_offset,
                $from,
                $to,
                $tz_offset
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) as click_date, COUNT(*) as clicks
             FROM {$table}
             WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0
             GROUP BY DATE(clicked_at)
             ORDER BY click_date ASC",
            $from,
            $to
        ) );
    }

    /**
     * Top countries across all links within a UTC range.
     *
     * @param string $from  'Y-m-d H:i:s' UTC, inclusive.
     * @param string $to    'Y-m-d H:i:s' UTC, exclusive.
     * @param int    $limit
     * @return array of objects { country_code, clicks }
     */
    public static function get_site_top_countries_range( $from, $to, $limit = 20 ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT country_code, COUNT(*) as clicks
             FROM {$table}
             WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0 AND country_code != ''
             GROUP BY country_code
             ORDER BY clicks DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        ) );
    }

    /**
     * Top referrers across all links (rolling window).
     *
     * @param int $days
     * @param int $limit
     * @return array of objects { referrer, clicks }
     */
    public static function get_site_top_referrers( $days = 30, $limit = 50 ) {
        list( $from, $to ) = self::days_to_range( $days );
        return self::get_site_top_referrers_range( $from, $to, $limit );
    }

    /**
     * Top referrers across all links within an explicit UTC range.
     *
     * @param string $from  'Y-m-d H:i:s' UTC, inclusive.
     * @param string $to    'Y-m-d H:i:s' UTC, exclusive.
     * @param int    $limit
     * @return array of objects { referrer, clicks }
     */
    public static function get_site_top_referrers_range( $from, $to, $limit = 50 ) {
        global $wpdb;
        $table = self::get_table_name();
        $norm  = self::referrer_host_expr();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT {$norm} AS referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0 AND referrer != ''
             GROUP BY {$norm}
             ORDER BY clicks DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        ) );
    }

    /**
     * Convert a rolling "last N days" into an explicit [from, to) UTC range.
     *
     * @param int $days
     * @return array [ $from, $to ] as 'Y-m-d H:i:s' UTC strings.
     */
    private static function days_to_range( $days ) {
        $days = max( 1, (int) $days );
        $from = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $to   = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
        return array( $from, $to );
    }

    /**
     * SQL expression that collapses referrer variants (query string / fragment /
     * trailing slash) to a canonical path-level form. Used for per-link reports
     * where the originating page matters.
     */
    private static function referrer_normalize_expr() {
        return "TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '?', 1), '#', 1))";
    }

    /**
     * SQL expression that collapses a referrer URL to scheme+host only, so all
     * paths/queries from the same source aggregate to one row. Used for the
     * site-wide top-referrers report where the sending domain is the useful
     * signal.
     */
    private static function referrer_host_expr() {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '?', 1), '/', 3)";
    }

    /**
     * UTC offset string for the current WordPress site timezone, in the form
     * '+HH:MM' or '-HH:MM'. Usable as the target timezone in MySQL
     * CONVERT_TZ() without requiring the timezone tables to be loaded.
     *
     * @return string e.g. '+02:00'
     */
    public static function site_tz_offset() {
        if ( function_exists( 'wp_timezone' ) ) {
            $tz = wp_timezone();
        } else {
            $tz = new DateTimeZone( 'UTC' );
        }
        $offset = $tz->getOffset( new DateTime( 'now', $tz ) );
        $sign   = $offset >= 0 ? '+' : '-';
        $abs    = abs( $offset );
        return sprintf( '%s%02d:%02d', $sign, intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) );
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

    /**
     * Range-filtered rows batch for CSV export. Humans-only; bots excluded.
     *
     * @param string $from 'Y-m-d H:i:s' UTC, inclusive.
     * @param string $to   'Y-m-d H:i:s' UTC, exclusive.
     * @param int    $offset
     * @param int    $limit
     * @return array
     */
    public static function get_rows_batch_range( $from, $to, $offset = 0, $limit = 1000 ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, link_id, clicked_at, referrer, country_code, is_bot
             FROM {$table}
             WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $from,
            $to,
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
