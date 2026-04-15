<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scanner URL status storage.
 *
 * One row per unique URL found across all posts. Tracks HTTP status,
 * last check time, error message, and how many posts reference the URL.
 */
class LP_Scanner_DB {

    const VERSION = '1.0';

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lp_scanner_urls';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            url_hash CHAR(40) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unchecked',
            http_code SMALLINT UNSIGNED DEFAULT 0,
            checked_at DATETIME DEFAULT NULL,
            error TEXT,
            ref_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (url_hash),
            KEY idx_status (status),
            KEY idx_checked (checked_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'lp_scanner_db_version', self::VERSION );
    }

    public static function url_hash( $url ) {
        return sha1( $url );
    }

    public static function upsert( $url ) {
        global $wpdb;
        $table = self::get_table_name();
        $hash  = self::url_hash( $url );
        $now   = current_time( 'mysql', true );

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT url_hash, ref_count FROM {$table} WHERE url_hash = %s",
            $hash
        ) );

        if ( $existing ) {
            $wpdb->update( $table,
                array( 'last_seen_at' => $now ),
                array( 'url_hash' => $hash )
            );
            return $hash;
        }

        $wpdb->insert( $table, array(
            'url_hash'     => $hash,
            'url'          => substr( $url, 0, 2048 ),
            'status'       => 'unchecked',
            'last_seen_at' => $now,
        ) );

        return $hash;
    }

    public static function set_status( $url, $status, $http_code = 0, $error = '' ) {
        global $wpdb;
        return $wpdb->update(
            self::get_table_name(),
            array(
                'status'     => $status,
                'http_code'  => (int) $http_code,
                'error'      => $error,
                'checked_at' => current_time( 'mysql', true ),
            ),
            array( 'url_hash' => self::url_hash( $url ) )
        );
    }

    public static function get_status( $url ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::get_table_name() . " WHERE url_hash = %s",
            self::url_hash( $url )
        ) );
    }

    public static function get_stale_urls( $limit, $stale_days = 7 ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $stale_days * DAY_IN_SECONDS ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT url FROM " . self::get_table_name() . "
             WHERE checked_at IS NULL OR checked_at < %s
             ORDER BY checked_at ASC
             LIMIT %d",
            $cutoff,
            (int) $limit
        ) );
    }

    public static function get_summary() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM " . self::get_table_name() . " GROUP BY status"
        );
        $summary = array(
            'healthy'      => 0,
            'broken'       => 0,
            'server_error' => 0,
            'error'        => 0,
            'unchecked'    => 0,
            'redirect'     => 0,
        );
        foreach ( $rows as $r ) {
            if ( array_key_exists( $r->status, $summary ) ) {
                $summary[ $r->status ] = (int) $r->cnt;
            }
        }
        return $summary;
    }

    public static function get_broken() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::get_table_name() . "
             WHERE status IN ('broken', 'error', 'server_error')
             ORDER BY ref_count DESC, url ASC"
        );
    }

    public static function refresh_ref_counts() {
        global $wpdb;
        $table = self::get_table_name();

        $post_meta_query = "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lp_scanner_urls'";
        $rows = $wpdb->get_col( $post_meta_query );

        $counts = array();
        foreach ( $rows as $serialized ) {
            $urls = maybe_unserialize( $serialized );
            if ( ! is_array( $urls ) ) continue;
            foreach ( $urls as $url ) {
                $hash = self::url_hash( $url );
                $counts[ $hash ] = ( $counts[ $hash ] ?? 0 ) + 1;
            }
        }

        $wpdb->query( "UPDATE {$table} SET ref_count = 0" );
        foreach ( $counts as $hash => $cnt ) {
            $wpdb->update( $table, array( 'ref_count' => $cnt ), array( 'url_hash' => $hash ) );
        }

        $wpdb->query( "DELETE FROM {$table} WHERE ref_count = 0 AND last_seen_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
    }

    public static function drop_table() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::get_table_name() );
    }
}
