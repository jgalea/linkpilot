<?php
// includes/class-lp-link.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Link {

    private $id;
    private $post;

    const META_KEYS = array(
        'destination_url'    => '_lp_destination_url',
        'redirect_type'      => '_lp_redirect_type',
        'nofollow'           => '_lp_nofollow',
        'sponsored'          => '_lp_sponsored',
        'new_window'         => '_lp_new_window',
        'css_classes'        => '_lp_css_classes',
        'pass_query_str'     => '_lp_pass_query_str',
        'rel_tags'           => '_lp_rel_tags',
    );

    const META_SLUG_HISTORY = '_lp_slug_history';

    public static function init() {
        add_action( 'post_updated', array( __CLASS__, 'track_slug_change' ), 10, 3 );
    }

    public static function track_slug_change( $post_id, $post_after, $post_before ) {
        if ( $post_after->post_type !== 'lp_link' ) {
            return;
        }
        if ( $post_after->post_name === $post_before->post_name ) {
            return;
        }
        if ( ! $post_before->post_name ) {
            return;
        }

        $history = get_post_meta( $post_id, self::META_SLUG_HISTORY, true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $history[] = $post_before->post_name;
        $history   = array_values( array_unique( $history ) );

        update_post_meta( $post_id, self::META_SLUG_HISTORY, $history );
    }

    public function __construct( $post_id ) {
        $this->id   = (int) $post_id;
        $this->post = get_post( $this->id );
    }

    public function get_id() {
        return $this->id;
    }

    public function get_title() {
        return $this->post ? $this->post->post_title : '';
    }

    public function get_slug() {
        return $this->post ? $this->post->post_name : '';
    }

    public function get_destination_url() {
        return get_post_meta( $this->id, self::META_KEYS['destination_url'], true );
    }

    public function get_redirect_type() {
        $type = get_post_meta( $this->id, self::META_KEYS['redirect_type'], true );
        if ( ! in_array( $type, array( '301', '302', '307', 301, 302, 307 ), true ) ) {
            $type = get_option( 'lp_redirect_type', '307' );
        }
        if ( ! in_array( (string) $type, array( '301', '302', '307' ), true ) ) {
            $type = '307';
        }
        return (int) $type;
    }

    public function is_nofollow() {
        $val = get_post_meta( $this->id, self::META_KEYS['nofollow'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'lp_nofollow', 'yes' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function is_sponsored() {
        $val = get_post_meta( $this->id, self::META_KEYS['sponsored'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'lp_sponsored', 'no' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function opens_new_window() {
        $val = get_post_meta( $this->id, self::META_KEYS['new_window'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'lp_new_window', 'yes' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function get_css_classes() {
        return get_post_meta( $this->id, self::META_KEYS['css_classes'], true );
    }

    public function passes_query_string() {
        $val = get_post_meta( $this->id, self::META_KEYS['pass_query_str'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'lp_pass_query_str', 'no' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function get_rel_tags() {
        $tags = array();
        if ( $this->is_nofollow() ) {
            $tags[] = 'nofollow';
        }
        if ( $this->is_sponsored() ) {
            $tags[] = 'sponsored';
        }
        $custom = get_post_meta( $this->id, self::META_KEYS['rel_tags'], true );
        if ( $custom ) {
            $tags = array_merge( $tags, array_map( 'trim', explode( ' ', $custom ) ) );
        }
        return implode( ' ', array_unique( $tags ) );
    }

    public function get_cloaked_url() {
        return LP_Post_Type::get_link_url( $this->id );
    }

    public function get_final_destination_url( $request_query_string = '' ) {
        $url = $this->get_destination_url();
        if ( $this->passes_query_string() && $request_query_string ) {
            $separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
            $url .= $separator . $request_query_string;
        }
        return $url;
    }

    public function save_meta( array $data ) {
        $keys = apply_filters( 'lp_link_meta_keys', self::META_KEYS );
        foreach ( $keys as $key => $meta_key ) {
            if ( isset( $data[ $key ] ) ) {
                update_post_meta( $this->id, $meta_key, sanitize_text_field( $data[ $key ] ) );
            }
        }
    }

    public static function find_by_slug( $slug ) {
        $slug  = sanitize_title( $slug );
        $posts = get_posts( array(
            'post_type'      => 'lp_link',
            'name'           => $slug,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ) );
        if ( ! empty( $posts ) ) {
            return new self( $posts[0]->ID );
        }

        return self::find_by_old_slug( $slug );
    }

    public static function find_by_old_slug( $slug ) {
        $slug  = sanitize_title( $slug );
        $posts = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => self::META_SLUG_HISTORY,
                    'value'   => serialize( $slug ),
                    'compare' => 'LIKE',
                ),
            ),
        ) );
        if ( empty( $posts ) ) {
            return null;
        }
        return new self( $posts[0]->ID );
    }
}
