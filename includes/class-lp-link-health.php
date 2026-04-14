<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Link_Health {

    const META_STATUS     = '_lp_health_status';
    const META_HTTP_CODE  = '_lp_health_http_code';
    const META_CHECKED_AT = '_lp_health_checked_at';
    const META_ERROR      = '_lp_health_error';
    const CRON_HOOK       = 'lp_health_check_cron';
    const BATCH_SIZE      = 5;
    const STALE_DAYS      = 7;

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_tick' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    public static function schedule() {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next ) {
            $event = wp_get_scheduled_event( self::CRON_HOOK );
            if ( $event && $event->schedule !== 'hourly' ) {
                wp_unschedule_event( $next, self::CRON_HOOK );
                $next = false;
            }
        }
        if ( ! $next ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public static function run_cron_tick() {
        $ids = self::get_stale_ids( self::BATCH_SIZE );
        foreach ( $ids as $i => $post_id ) {
            self::check_link( (int) $post_id );
            if ( $i < count( $ids ) - 1 ) {
                usleep( 500000 ); // 0.5s pacing
            }
        }
    }

    public static function get_stale_ids( $limit ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::STALE_DAYS * DAY_IN_SECONDS ) );

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = 'lp_link'
              AND p.post_status = 'publish'
              AND ( pm.meta_value IS NULL OR pm.meta_value < %s )
            ORDER BY pm.meta_value ASC
            LIMIT %d",
            self::META_CHECKED_AT,
            $cutoff,
            (int) $limit
        ) );
    }

    public static function get_all_ids() {
        return get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
    }

    public static function check_link( $post_id ) {
        $link = new LP_Link( $post_id );
        $url  = $link->get_destination_url();

        if ( ! $url ) {
            update_post_meta( $post_id, self::META_STATUS, 'no_url' );
            update_post_meta( $post_id, self::META_HTTP_CODE, '' );
            update_post_meta( $post_id, self::META_CHECKED_AT, gmdate( 'Y-m-d H:i:s' ) );
            update_post_meta( $post_id, self::META_ERROR, '' );
            return array( 'status' => 'no_url', 'code' => '' );
        }

        $response = wp_remote_head( $url, array(
            'timeout'    => 15,
            'sslverify'  => false,
            'user-agent' => 'LinkPilot Health Checker (WordPress)',
        ) );

        if ( is_wp_error( $response ) ) {
            update_post_meta( $post_id, self::META_STATUS, 'error' );
            update_post_meta( $post_id, self::META_HTTP_CODE, '' );
            update_post_meta( $post_id, self::META_CHECKED_AT, gmdate( 'Y-m-d H:i:s' ) );
            update_post_meta( $post_id, self::META_ERROR, $response->get_error_message() );
            return array( 'status' => 'error', 'code' => '', 'error' => $response->get_error_message() );
        }

        $code   = (int) wp_remote_retrieve_response_code( $response );
        $status = self::code_to_status( $code );

        update_post_meta( $post_id, self::META_STATUS, $status );
        update_post_meta( $post_id, self::META_HTTP_CODE, $code );
        update_post_meta( $post_id, self::META_CHECKED_AT, gmdate( 'Y-m-d H:i:s' ) );
        update_post_meta( $post_id, self::META_ERROR, '' );

        return array( 'status' => $status, 'code' => $code );
    }

    private static function code_to_status( $code ) {
        if ( $code >= 200 && $code < 400 ) {
            return 'healthy';
        }
        if ( $code >= 400 && $code < 500 ) {
            return 'broken';
        }
        if ( $code >= 500 ) {
            return 'server_error';
        }
        return 'unknown';
    }

    public static function get_summary() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS status, COUNT(*) AS cnt
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = 'lp_link'
              AND p.post_status = 'publish'
            GROUP BY pm.meta_value",
            self::META_STATUS
        ) );

        $summary = array(
            'healthy'      => 0,
            'broken'       => 0,
            'server_error' => 0,
            'error'        => 0,
            'no_url'       => 0,
            'unknown'      => 0,
            'unchecked'    => 0,
        );

        foreach ( $rows as $row ) {
            if ( array_key_exists( $row->status, $summary ) ) {
                $summary[ $row->status ] = (int) $row->cnt;
            }
        }

        $total_published = (int) wp_count_posts( 'lp_link' )->publish;
        $checked         = array_sum( $summary ) - $summary['unchecked'];
        $summary['unchecked'] = max( 0, $total_published - $checked );

        return $summary;
    }

    public static function get_status_label( $status ) {
        $labels = array(
            'healthy'      => __( 'Healthy', 'linkpilot' ),
            'broken'       => __( 'Broken', 'linkpilot' ),
            'server_error' => __( 'Server Error', 'linkpilot' ),
            'error'        => __( 'Error', 'linkpilot' ),
            'no_url'       => __( 'No URL', 'linkpilot' ),
            'unknown'      => __( 'Unknown', 'linkpilot' ),
            'unchecked'    => __( 'Unchecked', 'linkpilot' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    public static function get_status_color( $status ) {
        $colors = array(
            'healthy'      => '#46b450',
            'broken'       => '#dc3232',
            'server_error' => '#ffb900',
            'error'        => '#dc3232',
            'no_url'       => '#999999',
            'unknown'      => '#999999',
            'unchecked'    => '#999999',
        );
        return isset( $colors[ $status ] ) ? $colors[ $status ] : '#999999';
    }
}
