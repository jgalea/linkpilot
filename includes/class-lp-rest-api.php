<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API for LinkPilot links.
 *
 * Endpoints under /linkpilot/v1/
 *   GET    /links            List links. Supports ?search, ?per_page, ?page.
 *   POST   /links            Create a link.
 *   GET    /links/{id}       Retrieve a link.
 *   PUT    /links/{id}       Update a link.
 *   DELETE /links/{id}       Trash a link (or ?force=true to hard-delete).
 *   GET    /links/{id}/stats Per-link click stats.
 *
 * All endpoints require edit_posts capability.
 */
class LP_REST_API {

    const NAMESPACE_NAME = 'linkpilot/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_NAME, '/links', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'list_links' ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'args'                => array(
                    'search'   => array( 'type' => 'string' ),
                    'per_page' => array( 'type' => 'integer', 'default' => 20 ),
                    'page'     => array( 'type' => 'integer', 'default' => 1 ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_link' ),
                'permission_callback' => array( __CLASS__, 'can_edit' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_NAME, '/links/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_link' ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
            ),
            array(
                'methods'             => 'PUT, PATCH',
                'callback'            => array( __CLASS__, 'update_link' ),
                'permission_callback' => array( __CLASS__, 'can_edit' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_link' ),
                'permission_callback' => array( __CLASS__, 'can_edit' ),
                'args'                => array(
                    'force' => array( 'type' => 'boolean', 'default' => false ),
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_NAME, '/links/(?P<id>\d+)/stats', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'link_stats' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'args'                => array(
                'days' => array(
                    'type'        => 'integer',
                    'default'     => 30,
                    'description' => 'Rolling window in days. Ignored if from/to are supplied.',
                ),
                'from' => array(
                    'type'        => 'string',
                    'description' => 'Start date (YYYY-MM-DD) in site timezone. Requires "to".',
                ),
                'to'   => array(
                    'type'        => 'string',
                    'description' => 'End date (YYYY-MM-DD, inclusive) in site timezone. Requires "from".',
                ),
            ),
        ) );
    }

    public static function can_read() {
        return current_user_can( 'edit_posts' );
    }

    public static function can_edit() {
        return current_user_can( 'edit_posts' );
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    public static function list_links( WP_REST_Request $req ) {
        $search   = (string) $req->get_param( 'search' );
        $per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ) );
        $page     = max( 1, (int) $req->get_param( 'page' ) );

        $args = array(
            'post_type'      => 'lp_link',
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        if ( '' !== $search ) {
            $args['s'] = $search;
        }

        $query  = new WP_Query( $args );
        $items  = array();
        foreach ( $query->posts as $p ) {
            $items[] = self::serialize_link( $p );
        }

        $res = rest_ensure_response( $items );
        $res->header( 'X-WP-Total', (string) $query->found_posts );
        $res->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
        return $res;
    }

    public static function create_link( WP_REST_Request $req ) {
        $body  = $req->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = (array) $req->get_body_params();
        }

        $title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
        $url   = isset( $body['destination_url'] ) ? esc_url_raw( $body['destination_url'] ) : '';
        $slug  = isset( $body['slug'] ) ? sanitize_title( $body['slug'] ) : ( $title ? sanitize_title( $title ) : '' );
        if ( '' === $title || '' === $url ) {
            return new WP_Error( 'lp_missing_fields', 'title and destination_url are required.', array( 'status' => 400 ) );
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'lp_link',
            'post_status' => isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : 'publish',
            'post_title'  => $title,
            'post_name'   => $slug,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_lp_destination_url', $url );
        self::apply_writable_meta( $post_id, $body );

        $post = get_post( $post_id );
        return rest_ensure_response( self::serialize_link( $post ) );
    }

    public static function get_link( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || 'lp_link' !== $post->post_type ) {
            return new WP_Error( 'lp_not_found', 'Link not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( self::serialize_link( $post ) );
    }

    public static function update_link( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || 'lp_link' !== $post->post_type ) {
            return new WP_Error( 'lp_not_found', 'Link not found.', array( 'status' => 404 ) );
        }

        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = (array) $req->get_body_params();
        }

        $update = array( 'ID' => $id );
        if ( isset( $body['title'] ) ) {
            $update['post_title'] = sanitize_text_field( $body['title'] );
        }
        if ( isset( $body['slug'] ) ) {
            $update['post_name'] = sanitize_title( $body['slug'] );
        }
        if ( isset( $body['status'] ) ) {
            $update['post_status'] = sanitize_text_field( $body['status'] );
        }
        if ( count( $update ) > 1 ) {
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        if ( isset( $body['destination_url'] ) ) {
            update_post_meta( $id, '_lp_destination_url', esc_url_raw( $body['destination_url'] ) );
        }
        self::apply_writable_meta( $id, $body );

        return rest_ensure_response( self::serialize_link( get_post( $id ) ) );
    }

    public static function delete_link( WP_REST_Request $req ) {
        $id    = (int) $req['id'];
        $force = (bool) $req->get_param( 'force' );
        $post  = get_post( $id );
        if ( ! $post || 'lp_link' !== $post->post_type ) {
            return new WP_Error( 'lp_not_found', 'Link not found.', array( 'status' => 404 ) );
        }
        $result = wp_delete_post( $id, $force );
        if ( ! $result ) {
            return new WP_Error( 'lp_delete_failed', 'Could not delete link.', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'deleted' => true, 'id' => $id, 'force' => $force ) );
    }

    public static function link_stats( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || 'lp_link' !== $post->post_type ) {
            return new WP_Error( 'lp_not_found', 'Link not found.', array( 'status' => 404 ) );
        }

        $from_raw = (string) $req->get_param( 'from' );
        $to_raw   = (string) $req->get_param( 'to' );
        $is_date  = static function ( $s ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
        };

        if ( $is_date( $from_raw ) && $is_date( $to_raw ) ) {
            $from_date = $from_raw <= $to_raw ? $from_raw : $to_raw;
            $to_date   = $from_raw <= $to_raw ? $to_raw : $from_raw;
            $days      = null;
        } else {
            $days      = max( 1, min( 365, (int) $req->get_param( 'days' ) ) );
            $today     = self::today_local();
            $from_date = self::local_date_minus_days( $today, $days - 1 );
            $to_date   = $today;
        }

        list( $from_sql, $to_sql ) = self::range_to_utc_bounds( $from_date, $to_date );

        $total     = LP_Clicks_DB::get_total_clicks( $id );
        $by_day    = LP_Clicks_DB::get_clicks_by_day_range( $id, $from_sql, $to_sql );
        $referrers = LP_Clicks_DB::get_top_referrers_range( $id, $from_sql, $to_sql, 10 );
        $window    = 0;
        foreach ( $by_day as $r ) {
            $window += (int) $r->clicks;
        }

        $response = array(
            'id'            => $id,
            'total_clicks'  => (int) $total,
            'from'          => $from_date,
            'to'            => $to_date,
            'timezone'      => wp_timezone_string(),
            'window_clicks' => $window,
            'by_day'        => $by_day,
            'top_referrers' => $referrers,
        );
        if ( null !== $days ) {
            $response['window_days'] = $days;
        }
        return rest_ensure_response( $response );
    }

    // ------------------------------------------------------------------
    // Range helpers (duplicated across files for isolation; kept small).
    // ------------------------------------------------------------------

    private static function today_local() {
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        return ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
    }

    private static function local_date_minus_days( $date, $days ) {
        try {
            $dt = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return $date;
        }
        return $dt->modify( '-' . (int) $days . ' days' )->format( 'Y-m-d' );
    }

    private static function range_to_utc_bounds( $from_date, $to_date ) {
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        try {
            $from = new DateTimeImmutable( $from_date . ' 00:00:00', $tz );
            $to   = ( new DateTimeImmutable( $to_date . ' 00:00:00', $tz ) )->modify( '+1 day' );
        } catch ( Exception $e ) {
            return array( gmdate( 'Y-m-d H:i:s', 0 ), gmdate( 'Y-m-d H:i:s' ) );
        }
        $utc = new DateTimeZone( 'UTC' );
        return array(
            $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
            $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function serialize_link( $post ) {
        $link = new LP_Link( $post->ID );
        $meta_keys = array( '_lp_destination_url', '_lp_redirect_type', '_lp_nofollow', '_lp_sponsored', '_lp_new_window', '_lp_pass_query_str', '_lp_rel_tags', '_lp_js_redirect' );
        $meta      = array();
        foreach ( $meta_keys as $k ) {
            $meta[ ltrim( $k, '_lp_' ) ] = get_post_meta( $post->ID, $k, true );
        }
        return array(
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => $post->post_status,
            'destination_url' => $link->get_destination_url(),
            'cloaked_url'     => $link->get_cloaked_url(),
            'meta'            => $meta,
            'total_clicks'    => LP_Clicks_DB::get_total_clicks( $post->ID ),
            'date_created'    => mysql_to_rfc3339( $post->post_date_gmt ),
            'date_modified'   => mysql_to_rfc3339( $post->post_modified_gmt ),
        );
    }

    private static function apply_writable_meta( $post_id, $body ) {
        $map = array(
            'redirect_type'  => '_lp_redirect_type',
            'nofollow'       => '_lp_nofollow',
            'sponsored'      => '_lp_sponsored',
            'new_window'     => '_lp_new_window',
            'pass_query_str' => '_lp_pass_query_str',
            'rel_tags'       => '_lp_rel_tags',
            'js_redirect'    => '_lp_js_redirect',
        );
        foreach ( $map as $key => $meta_key ) {
            if ( isset( $body[ $key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $body[ $key ] ) );
            }
        }
    }
}
