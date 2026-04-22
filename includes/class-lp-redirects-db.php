<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database table for site-level redirects (old-URL -> new-URL).
 *
 * Schema:
 *   id          BIGINT auto-increment
 *   source_path VARCHAR(2000)  the path (or pattern) to match, always leading slash
 *   destination VARCHAR(2000)  target URL (can be absolute or path)
 *   type        SMALLINT       301 | 302 | 307
 *   match_type  VARCHAR(16)    'exact' | 'prefix' | 'regex'
 *   hits        BIGINT         count of times the redirect fired
 *   last_hit    DATETIME
 *   created_at  DATETIME
 */
class LP_Redirects_DB {

    const TABLE_SUFFIX = 'lp_redirects';
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
            source_path VARCHAR(2000) NOT NULL,
            destination VARCHAR(2000) NOT NULL,
            type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            match_type VARCHAR(16) NOT NULL DEFAULT 'exact',
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_idx (source_path(191)),
            KEY match_idx (match_type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'lp_redirects_db_version', self::DB_VERSION );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'lp_redirects_db_version' ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    /**
     * Fetch all redirects, sorted by id desc.
     *
     * @return array<int, object>
     */
    public static function all() {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
    }

    /**
     * Find a redirect row that matches the given request path. Returns null if none.
     *
     * @param string $path Request path, leading slash.
     * @return object|null
     */
    public static function find_match( $path ) {
        global $wpdb;
        $table = self::get_table_name();

        // 1. Exact match.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE match_type = %s AND source_path = %s LIMIT 1",
            'exact',
            $path
        ) );
        if ( $row ) {
            return $row;
        }

        // 2. Prefix match — longest first.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $prefixes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE match_type = %s ORDER BY CHAR_LENGTH(source_path) DESC",
            'prefix'
        ) );
        foreach ( $prefixes as $p ) {
            if ( strpos( $path, $p->source_path ) === 0 ) {
                return $p;
            }
        }

        // 3. Regex match.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $regexes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE match_type = %s",
            'regex'
        ) );
        foreach ( $regexes as $r ) {
            // @ delimiter, i flag. Users can't inject other flags.
            $pattern = '@' . str_replace( '@', '\\@', $r->source_path ) . '@i';
            if ( @preg_match( $pattern, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- malformed patterns from admin input shouldn't fatal.
                return $r;
            }
        }

        return null;
    }

    public static function insert( $data ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->update( $table, $data, array( 'id' => (int) $id ) );
    }

    public static function delete( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->delete( $table, array( 'id' => (int) $id ) );
    }

    public static function record_hit( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
            current_time( 'mysql', true ),
            (int) $id
        ) );
    }
}
