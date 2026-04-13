<?php
// includes/class-lp-post-type.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Post_Type {

    public static function register() {
        self::register_post_type();
        self::register_taxonomies();
        self::register_rewrite_rules();
    }

    private static function register_post_type() {
        $labels = array(
            'name'               => __( 'Links', 'linkpilot' ),
            'singular_name'      => __( 'Link', 'linkpilot' ),
            'add_new'            => __( 'Add New Link', 'linkpilot' ),
            'add_new_item'       => __( 'Add New Link', 'linkpilot' ),
            'edit_item'          => __( 'Edit Link', 'linkpilot' ),
            'new_item'           => __( 'New Link', 'linkpilot' ),
            'view_item'          => __( 'View Link', 'linkpilot' ),
            'search_items'       => __( 'Search Links', 'linkpilot' ),
            'not_found'          => __( 'No links found', 'linkpilot' ),
            'not_found_in_trash' => __( 'No links found in trash', 'linkpilot' ),
            'all_items'          => __( 'All Links', 'linkpilot' ),
            'menu_name'          => __( 'LinkPilot', 'linkpilot' ),
        );

        register_post_type( 'lp_link', array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'rest_base'           => 'lp-links',
            'menu_icon'           => 'dashicons-admin-links',
            'menu_position'       => 26,
            'supports'            => array( 'title' ),
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'hierarchical'        => false,
            'capability_type'     => 'post',
        ) );
    }

    private static function register_taxonomies() {
        register_taxonomy( 'lp_category', 'lp_link', array(
            'labels' => array(
                'name'          => __( 'Link Categories', 'linkpilot' ),
                'singular_name' => __( 'Link Category', 'linkpilot' ),
                'add_new_item'  => __( 'Add New Category', 'linkpilot' ),
                'edit_item'     => __( 'Edit Category', 'linkpilot' ),
                'search_items'  => __( 'Search Categories', 'linkpilot' ),
                'all_items'     => __( 'All Categories', 'linkpilot' ),
            ),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );

        register_taxonomy( 'lp_tag', 'lp_link', array(
            'labels' => array(
                'name'          => __( 'Link Tags', 'linkpilot' ),
                'singular_name' => __( 'Link Tag', 'linkpilot' ),
                'add_new_item'  => __( 'Add New Tag', 'linkpilot' ),
                'edit_item'     => __( 'Edit Tag', 'linkpilot' ),
                'search_items'  => __( 'Search Tags', 'linkpilot' ),
                'all_items'     => __( 'All Tags', 'linkpilot' ),
            ),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );
    }

    private static function register_rewrite_rules() {
        $prefix = get_option( 'lp_link_prefix', 'go' );

        // Match: /go/any-slug
        add_rewrite_rule(
            '^' . preg_quote( $prefix, '/' ) . '/([^/]+)/?$',
            'index.php?lp_link_slug=$matches[1]',
            'top'
        );

        add_rewrite_tag( '%lp_link_slug%', '([^/]+)' );
    }

    /**
     * Get the full cloaked URL for a link.
     */
    public static function get_link_url( $post_id ) {
        $prefix = get_option( 'lp_link_prefix', 'go' );
        $slug   = get_post_field( 'post_name', $post_id );
        return home_url( $prefix . '/' . $slug . '/' );
    }
}
