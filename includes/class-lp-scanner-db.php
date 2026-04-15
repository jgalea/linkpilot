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

    const VERSION = '1.1';

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
            final_url VARCHAR(2048) DEFAULT NULL,
            redirect_count SMALLINT UNSIGNED DEFAULT 0,
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

    public static function set_status( $url, $status, $http_code = 0, $error = '', $final_url = null, $redirect_count = 0 ) {
        global $wpdb;
        $table = self::get_table_name();
        $hash  = self::url_hash( $url );

        $old_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE url_hash = %s", $hash
        ) );

        $data = array(
            'status'         => $status,
            'http_code'      => (int) $http_code,
            'error'          => $error,
            'checked_at'     => current_time( 'mysql', true ),
            'final_url'      => $final_url ? substr( $final_url, 0, 2048 ) : null,
            'redirect_count' => (int) $redirect_count,
        );

        $result = $wpdb->update( $table, $data, array( 'url_hash' => $hash ) );

        if ( $old_status !== $status && class_exists( 'LP_Scanner_Notifier' ) ) {
            LP_Scanner_Notifier::maybe_send_transition( $url, $old_status, $status );
        }

        return $result;
    }

    public static function get_status( $url ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from class constant.
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", self::url_hash( $url ) ) );
    }

    public static function get_stale_urls( $limit, $stale_days = 7 ) {
        global $wpdb;
        $table  = self::get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $stale_days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from class constant.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT url FROM {$table} WHERE checked_at IS NULL OR checked_at < %s ORDER BY checked_at ASC LIMIT %d",
            $cutoff,
            (int) $limit
        ) );
    }

    public static function get_summary() {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant.
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status" );
        $summary = array(
            'healthy'      => 0,
            'broken'       => 0,
            'server_error' => 0,
            'error'        => 0,
            'blocked'      => 0,
            'unchecked'    => 0,
            'redirect'     => 0,
            'dismissed'    => 0,
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
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant, no user input.
        return $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('broken', 'error', 'server_error') ORDER BY ref_count DESC, url ASC" );
    }

    public static function refresh_ref_counts() {
        global $wpdb;
        $table = self::get_table_name();

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_lp_scanner_urls'
        ) );

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
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
