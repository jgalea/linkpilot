<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LinkPilot Scanner — broken link detection across post content.
 *
 * Modular replacement for the Broken Link Checker plugin. Integrates with
 * LinkPilot's job runner and respects cloaked-URL exclusion.
 */
class LP_Scanner {

    const META_POST_URLS    = '_lp_scanner_urls';
    const META_POST_CHECKED = '_lp_scanner_last_scan';
    const CRON_HOOK         = 'lp_scanner_cron_tick';
    const SCAN_BATCH        = 20;   // posts per cron tick
    const CHECK_BATCH       = 20;   // URLs per HTTP check batch
    const POST_STALE_DAYS   = 30;   // re-extract posts after N days
    const URL_STALE_DAYS    = 7;    // re-check URLs after N days

    public static function init() {
        if ( get_option( 'lp_scanner_enabled', 'no' ) !== 'yes' ) {
            return;
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_tick' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Cron tick: rescan a few stale posts + re-check a few stale URLs.
     * Paced so it never hammers the host.
     */
    public static function run_cron_tick() {
        // Step 1: extract URLs from some stale/unscanned posts.
        $post_ids = self::get_stale_post_ids( self::SCAN_BATCH );
        foreach ( $post_ids as $pid ) {
            self::scan_post( (int) $pid );
        }

        // Step 2: HTTP-check a batch of stale URLs.
        $stale = LP_Scanner_DB::get_stale_urls( self::CHECK_BATCH, self::URL_STALE_DAYS );
        if ( $stale ) {
            $urls    = wp_list_pluck( $stale, 'url' );
            $results = LP_Scanner_Checker::check_batch( $urls );
            foreach ( $results as $url => $r ) {
                LP_Scanner_DB::set_status( $url, $r['status'], $r['code'], $r['error'] );
            }
        }
    }

    public static function get_stale_post_ids( $limit ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::POST_STALE_DAYS * DAY_IN_SECONDS ) );

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type IN ('post', 'page')
             AND p.post_status = 'publish'
             AND ( pm.meta_value IS NULL
                   OR pm.meta_value < %s
                   OR pm.meta_value < p.post_modified_gmt )
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d",
            self::META_POST_CHECKED,
            $cutoff,
            (int) $limit
        ) );
    }

    /**
     * Extract URLs from one post, update DB, store on post meta.
     */
    public static function scan_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return 0;
        }

        $urls = LP_Scanner_Extractor::extract( $post->post_content );

        // Remove any previously recorded URLs for this post that no longer exist (decrements ref counts implicitly via refresh).
        update_post_meta( $post_id, self::META_POST_URLS, $urls );
        update_post_meta( $post_id, self::META_POST_CHECKED, current_time( 'mysql', true ) );

        // Ensure each URL has a row in the status table.
        foreach ( $urls as $url ) {
            LP_Scanner_DB::upsert( $url );
        }

        return count( $urls );
    }

    public static function get_post_url_statuses( $post_id ) {
        $urls = get_post_meta( $post_id, self::META_POST_URLS, true );
        if ( ! is_array( $urls ) || empty( $urls ) ) {
            return array();
        }

        global $wpdb;
        $table  = LP_Scanner_DB::get_table_name();
        $hashes = array_map( array( 'LP_Scanner_DB', 'url_hash' ), $urls );

        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT url, status, http_code, error FROM {$table} WHERE url_hash IN ({$placeholders})",
            ...$hashes
        ) );

        return $rows;
    }

    public static function count_broken_in_post( $post_id ) {
        $statuses = self::get_post_url_statuses( $post_id );
        $broken = 0;
        foreach ( $statuses as $s ) {
            if ( in_array( $s->status, array( 'broken', 'error', 'server_error' ), true ) ) {
                $broken++;
            }
        }
        return $broken;
    }
}
