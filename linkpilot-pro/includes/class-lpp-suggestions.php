<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Suggestions {

    const CACHE_META_KEY = '_lpp_suggestions_cache';
    const CACHE_HASH_KEY = '_lpp_suggestions_content_hash';

    public static function init() {
    }

    public static function get_suggestions( $post_id ) {
        if ( get_option( 'lpp_enable_suggestions', 'yes' ) !== 'yes' ) {
            return array();
        }

        $post = get_post( $post_id );
        if ( ! $post || ! $post->post_content ) {
            return array();
        }

        $content_hash = md5( $post->post_content );
        $cached_hash  = get_post_meta( $post_id, self::CACHE_HASH_KEY, true );
        if ( $cached_hash === $content_hash ) {
            $cached = get_post_meta( $post_id, self::CACHE_META_KEY, true );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $links = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
        ) );

        if ( empty( $links ) ) {
            return array();
        }

        $link_list = array();
        foreach ( $links as $link_post ) {
            $link = new LP_Link( $link_post->ID );
            $link_list[] = array(
                'id'    => $link_post->ID,
                'title' => $link_post->post_title,
                'url'   => $link->get_destination_url(),
                'slug'  => $link_post->post_name,
            );
        }

        $content_excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 500 );
        $link_json = wp_json_encode( $link_list );

        $system = 'You are a link suggestion assistant for a WordPress site. Given a post\'s content and a list of managed links, identify which links are relevant to the content. Return a JSON array of objects with: id (the link ID), reason (one sentence explaining why this link is relevant). Only include genuinely relevant links. Return at most 5 suggestions. Return valid JSON only, no markdown.';

        $user_msg = "Post title: {$post->post_title}\n\nContent:\n{$content_excerpt}\n\nAvailable links:\n{$link_json}";

        $result = LPP_AI_Client::query( $system, $user_msg );

        if ( is_wp_error( $result ) ) {
            return array();
        }

        // Strip markdown code fences if present
        $result = trim( $result );
        $result = preg_replace( '/^```(?:json)?\s*/i', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $suggestions = json_decode( $result, true );
        if ( ! is_array( $suggestions ) ) {
            return array();
        }

        $enriched = array();
        foreach ( $suggestions as $s ) {
            if ( ! isset( $s['id'] ) ) {
                continue;
            }
            $link_post = get_post( $s['id'] );
            if ( ! $link_post || $link_post->post_type !== 'lp_link' ) {
                continue;
            }
            $link = new LP_Link( $s['id'] );
            $enriched[] = array(
                'id'     => $s['id'],
                'title'  => $link_post->post_title,
                'url'    => $link->get_cloaked_url(),
                'reason' => isset( $s['reason'] ) ? sanitize_text_field( $s['reason'] ) : '',
            );
        }

        update_post_meta( $post_id, self::CACHE_META_KEY, $enriched );
        update_post_meta( $post_id, self::CACHE_HASH_KEY, $content_hash );

        return $enriched;
    }
}
