<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_REST_API {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'linkpilot-pro/v1', '/suggestions/(?P<post_id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'get_suggestions' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'type'     => 'integer',
                ),
            ),
        ) );

        register_rest_route( 'linkpilot-pro/v1', '/suggestions/(?P<post_id>\d+)/refresh', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'refresh_suggestions' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'type'     => 'integer',
                ),
            ),
        ) );
    }

    public static function get_suggestions( $request ) {
        $post_id = (int) $request['post_id'];
        $suggestions = LPP_Suggestions::get_suggestions( $post_id );
        return rest_ensure_response( $suggestions );
    }

    public static function refresh_suggestions( $request ) {
        $post_id = (int) $request['post_id'];
        delete_post_meta( $post_id, LPP_Suggestions::CACHE_HASH_KEY );
        $suggestions = LPP_Suggestions::get_suggestions( $post_id );
        return rest_ensure_response( $suggestions );
    }
}
