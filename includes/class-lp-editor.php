<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Editor {

    public static function init() {
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic_editor' ) );
        add_action( 'wp_ajax_lp_search_links', array( __CLASS__, 'ajax_search_links' ) );
        add_filter( 'mce_buttons', array( __CLASS__, 'register_tinymce_button' ) );
    }

    public static function register_tinymce_button( $buttons ) {
        if ( ! in_array( 'linkpilot', $buttons, true ) ) {
            $buttons[] = 'linkpilot';
        }
        return $buttons;
    }

    public static function enqueue_block_editor() {
        wp_enqueue_script(
            'lp-link-block',
            LP_PLUGIN_URL . 'assets/js/blocks/link-block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-compose' ),
            LP_VERSION,
            true
        );

        wp_localize_script( 'lp-link-block', 'lpEditor', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'lp_editor' ),
        ) );
    }

    public static function enqueue_classic_editor( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        wp_enqueue_script(
            'lp-link-picker',
            LP_PLUGIN_URL . 'assets/js/link-picker.js',
            array( 'jquery' ),
            LP_VERSION,
            true
        );

        wp_localize_script( 'lp-link-picker', 'lpEditor', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'lp_editor' ),
        ) );
    }

    public static function ajax_search_links() {
        check_ajax_referer( 'lp_editor', 'nonce' );

        $search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';

        $args = array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'no_found_rows'  => true,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        if ( $search ) {
            $args['s'] = $search;
        }

        $posts   = get_posts( $args );
        $results = array();

        foreach ( $posts as $post ) {
            $link      = new LP_Link( $post->ID );
            $results[] = array(
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => $link->get_cloaked_url(),
                'dest'  => $link->get_destination_url(),
            );
        }

        wp_send_json_success( $results );
    }
}
