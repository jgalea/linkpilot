<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database table for 404 logs.
 *
 * Schema:
 *   id         BIGINT auto-increment
 *   path       VARCHAR(2000)     the requested path (canonical form)
 *   referer    VARCHAR(2000)     referring URL
 *   hits       BIGINT            count of 404s to this path
 *   last_seen  DATETIME          last time we saw this 404
 *   first_seen DATETIME          first time we saw this 404
 *   redirected TINYINT(1)        1 if a redirect has been created via one-click
 */
class LP_404_Log_DB {

    const TABLE_SUFFIX = 'lp_404_log';
    const DB_VERSION   = '1.0';

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            path VARCHAR(2000) NOT NULL,
            referer VARCHAR(2000) DEFAULT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL,
            first_seen DATETIME NOT NULL,
            redirected TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY path_idx (path(191)),
            KEY last_seen_idx (last_seen)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'lp_404_log_db_version', self::DB_VERSION );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'lp_404_log_db_version' ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Log a 404 occurrence for the given path. Increments hits if the path
     * is already in the table.
     *
     * @param string $path    Request path (leading slash).
     * @param string $referer Referrer URL (or empty).
     */
    public static function record( $path, $referer = '' ) {
        global $wpdb;
        $table = self::get_table_name();
        $now   = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE path = %s LIMIT 1",
            $path
        ) );

        if ( $existing_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET hits = hits + 1, last_seen = %s, referer = %s WHERE id = %d",
                $now,
                $referer,
                (int) $existing_id
            ) );
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert( $table, array(
            'path'       => $path,
            'referer'    => $referer,
            'hits'       => 1,
            'last_seen'  => $now,
            'first_seen' => $now,
        ) );
    }

    /**
     * Mark an entry as redirected (or unmark it).
     *
     * @param int  $id
     * @param bool $flag
     */
    public static function mark_redirected( $id, $flag = true ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( $table, array( 'redirected' => $flag ? 1 : 0 ), array( 'id' => (int) $id ) );
    }

    public static function delete( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->delete( $table, array( 'id' => (int) $id ) );
    }

    /**
     * Delete all entries.
     *
     * @return int Rows deleted.
     */
    public static function truncate() {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query( "DELETE FROM {$table}" );
    }

    /**
     * Purge entries older than $days.
     *
     * @param int $days Retention window in days. Entries whose last_seen is older are removed.
     * @return int Rows deleted.
     */
    public static function purge_older_than( $days = 90 ) {
        global $wpdb;
        $table  = self::get_table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE last_seen < %s AND redirected = 0",
            $cutoff
        ) );
    }

    public static function get_one( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
    }

    /**
     * Paginated list.
     *
     * @param int $per_page
     * @param int $page     1-based.
     * @return array
     */
    public static function paginate( $per_page = 50, $page = 1 ) {
        global $wpdb;
        $table  = self::get_table_name();
        $offset = max( 0, ( $page - 1 ) * $per_page );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        return array(
            'rows'  => $rows,
            'total' => $total,
        );
    }
}
