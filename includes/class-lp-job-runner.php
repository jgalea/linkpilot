<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Job_Runner {

    const NONCE_ACTION = 'lp_job_runner';

    const MIGRATE_BATCH = 10;
    const SCAN_BATCH    = 25;
    const HEALTH_BATCH  = 5;

    const MIGRATORS = array(
        'thirstyaffiliates'  => 'LP_Migrator_ThirstyAffiliates',
        'prettylinks'        => 'LP_Migrator_PrettyLinks',
        'linkcentral'        => 'LP_Migrator_LinkCentral',
        'easyaffiliatelinks' => 'LP_Migrator_EasyAffiliateLinks',
    );

    public static function init() {
        add_action( 'wp_ajax_lp_job_migrate', array( __CLASS__, 'handle_migrate' ) );
        add_action( 'wp_ajax_lp_job_scan', array( __CLASS__, 'handle_scan' ) );
        add_action( 'wp_ajax_lp_job_health', array( __CLASS__, 'handle_health' ) );
        add_action( 'wp_ajax_lp_job_health_one', array( __CLASS__, 'handle_health_one' ) );
    }

    public static function get_nonce() {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    private static function verify() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    private static function state_key( $job_id ) {
        return 'lp_job_' . sanitize_key( $job_id );
    }

    public static function handle_migrate() {
        self::verify();

        $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : '';

        if ( ! isset( self::MIGRATORS[ $source ] ) ) {
            wp_send_json_error( array( 'message' => 'Unknown source' ), 400 );
        }

        $class = self::MIGRATORS[ $source ];
        if ( ! class_exists( $class ) ) {
            wp_send_json_error( array( 'message' => 'Migrator class not loaded' ), 500 );
        }

        $state = get_transient( self::state_key( $job_id ) );
        if ( ! is_array( $state ) ) {
            $state = array(
                'offset'  => 0,
                'total'   => $class::get_source_count(),
                'id_map'  => array(),
                'results' => array(
                    'links'      => 0,
                    'categories' => 0,
                    'clicks'     => 0,
                    'skipped'    => 0,
                    'errors'     => 0,
                ),
            );
        }

        $ids = $class::get_source_ids( $state['offset'], self::MIGRATE_BATCH );

        if ( empty( $ids ) ) {
            delete_transient( self::state_key( $job_id ) );
            wp_send_json_success( array(
                'done'      => true,
                'processed' => $state['offset'],
                'total'     => $state['total'],
                'results'   => $state['results'],
                'id_map'    => $state['id_map'],
            ) );
        }

        $migrator = new $class();
        $migrator->set_id_map( $state['id_map'] );
        $migrator->set_results( $state['results'] );

        foreach ( $ids as $source_id ) {
            $migrator->migrate_one( $source_id );
        }

        $state['id_map']  = $migrator->get_id_map();
        $state['results'] = $migrator->get_results();
        $state['offset'] += count( $ids );

        $done = $state['offset'] >= $state['total'];

        if ( $done ) {
            delete_transient( self::state_key( $job_id ) );
        } else {
            set_transient( self::state_key( $job_id ), $state, HOUR_IN_SECONDS );
        }

        wp_send_json_success( array(
            'done'      => $done,
            'processed' => $state['offset'],
            'total'     => $state['total'],
            'results'   => $state['results'],
            'id_map'    => $done ? $state['id_map'] : null,
        ) );
    }

    public static function handle_scan() {
        self::verify();

        $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : '';
        $id_map = isset( $_POST['id_map'] ) ? json_decode( wp_unslash( $_POST['id_map'] ), true ) : array();

        if ( ! isset( self::MIGRATORS[ $source ] ) ) {
            wp_send_json_error( array( 'message' => 'Unknown source' ), 400 );
        }

        $source_name = self::MIGRATORS[ $source ]::get_source_name();

        $state = get_transient( self::state_key( 'scan_' . $job_id ) );
        if ( ! is_array( $state ) ) {
            $state = array(
                'offset'       => 0,
                'total'        => LP_Content_Scanner::get_total_posts(),
                'replacements' => 0,
                'updated'      => 0,
                'id_map'       => is_array( $id_map ) ? $id_map : array(),
            );
        }

        $ids = LP_Content_Scanner::get_post_ids( $state['offset'], self::SCAN_BATCH );

        if ( empty( $ids ) ) {
            delete_transient( self::state_key( 'scan_' . $job_id ) );
            wp_send_json_success( array(
                'done'         => true,
                'processed'    => $state['offset'],
                'total'        => $state['total'],
                'replacements' => $state['replacements'],
                'updated'      => $state['updated'],
            ) );
        }

        $scanner = new LP_Content_Scanner( $state['id_map'], $source_name );

        foreach ( $ids as $post_id ) {
            $reps = $scanner->scan_one_post( $post_id );
            if ( $reps > 0 ) {
                $state['updated']++;
                $state['replacements'] += $reps;
            }
        }

        $state['offset'] += count( $ids );
        $done = $state['offset'] >= $state['total'];

        if ( $done ) {
            delete_transient( self::state_key( 'scan_' . $job_id ) );
        } else {
            set_transient( self::state_key( 'scan_' . $job_id ), $state, HOUR_IN_SECONDS );
        }

        wp_send_json_success( array(
            'done'         => $done,
            'processed'    => $state['offset'],
            'total'        => $state['total'],
            'replacements' => $state['replacements'],
            'updated'      => $state['updated'],
        ) );
    }

    public static function handle_health() {
        self::verify();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( $_POST['job_id'] ) : '';

        $state = get_transient( self::state_key( 'health_' . $job_id ) );
        if ( ! is_array( $state ) ) {
            $ids = LP_Link_Health::get_all_ids();
            $state = array(
                'queue'     => array_values( $ids ),
                'total'     => count( $ids ),
                'processed' => 0,
                'results'   => array(
                    'healthy'      => 0,
                    'broken'       => 0,
                    'server_error' => 0,
                    'error'        => 0,
                    'no_url'       => 0,
                    'unknown'      => 0,
                ),
            );
        }

        $batch = array_splice( $state['queue'], 0, LP_Link_Health::BATCH_SIZE );

        foreach ( $batch as $i => $post_id ) {
            $r = LP_Link_Health::check_link( (int) $post_id );
            $status = isset( $r['status'] ) ? $r['status'] : 'unknown';
            if ( isset( $state['results'][ $status ] ) ) {
                $state['results'][ $status ]++;
            }
            $state['processed']++;
            if ( $i < count( $batch ) - 1 ) {
                usleep( 500000 );
            }
        }

        $done = empty( $state['queue'] );

        if ( $done ) {
            delete_transient( self::state_key( 'health_' . $job_id ) );
        } else {
            set_transient( self::state_key( 'health_' . $job_id ), $state, HOUR_IN_SECONDS );
        }

        wp_send_json_success( array(
            'done'      => $done,
            'processed' => $state['processed'],
            'total'     => $state['total'],
            'results'   => $state['results'],
        ) );
    }

    public static function handle_health_one() {
        self::verify();

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Missing post_id' ), 400 );
        }

        $r = LP_Link_Health::check_link( $post_id );

        wp_send_json_success( array(
            'status' => $r['status'],
            'code'   => isset( $r['code'] ) ? $r['code'] : '',
            'label'  => LP_Link_Health::get_status_label( $r['status'] ),
            'color'  => LP_Link_Health::get_status_color( $r['status'] ),
        ) );
    }
}
