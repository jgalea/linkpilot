<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Auto_Linker {

    const CACHE_KEY  = '_lpp_autolink_cache';
    const CACHE_HASH = '_lpp_autolink_hash';

    public static function init() {
        if ( get_option( 'lpp_enable_auto_linking', 'no' ) !== 'yes' ) {
            return;
        }
        add_filter( 'the_content', array( __CLASS__, 'auto_link_content' ), 20 );
    }

    public static function auto_link_content( $content ) {
        if ( is_admin() || is_feed() || ! is_singular() ) {
            return $content;
        }

        $post_id      = get_the_ID();
        $content_hash = md5( $content );
        $cached_hash  = get_post_meta( $post_id, self::CACHE_HASH, true );

        if ( $cached_hash === $content_hash ) {
            $cached = get_post_meta( $post_id, self::CACHE_KEY, true );
            if ( is_array( $cached ) ) {
                return self::apply_links( $content, $cached );
            }
        }

        $candidates = self::get_candidates();
        if ( empty( $candidates ) ) {
            return $content;
        }

        $decisions = self::evaluate_links( $content, $candidates );

        update_post_meta( $post_id, self::CACHE_KEY, $decisions );
        update_post_meta( $post_id, self::CACHE_HASH, $content_hash );

        return self::apply_links( $content, $decisions );
    }

    private static function get_candidates() {
        $links = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
        ) );

        $candidates = array();
        foreach ( $links as $post ) {
            $candidates[] = array(
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'keyword' => strtolower( $post->post_title ),
            );
        }
        return $candidates;
    }

    private static function evaluate_links( $content, $candidates ) {
        $plain       = wp_strip_all_tags( $content );
        $plain_lower = strtolower( $plain );

        $matches = array();
        foreach ( $candidates as $c ) {
            if ( stripos( $plain_lower, $c['keyword'] ) !== false ) {
                $matches[] = $c;
            }
        }

        if ( empty( $matches ) ) {
            return array();
        }

        $api_key = get_option( 'lpp_ai_api_key', '' );
        if ( ! $api_key ) {
            $decisions = array();
            foreach ( $matches as $m ) {
                $decisions[] = array(
                    'id'      => $m['id'],
                    'keyword' => $m['keyword'],
                    'link'    => true,
                );
            }
            return $decisions;
        }

        $match_json = wp_json_encode( array_map( function( $m ) {
            return array( 'id' => $m['id'], 'keyword' => $m['keyword'], 'title' => $m['title'] );
        }, $matches ) );

        $excerpt = wp_trim_words( $plain, 500 );

        $system = 'You evaluate whether keyword appearances in content are contextually appropriate for linking. Given a post and a list of potential links (with keywords), return a JSON array of objects: { "id": number, "keyword": string, "link": boolean }. Set "link" to true only if the keyword is used in a context where the managed link would be genuinely relevant. For example: "investing" in a finance article = true; "investing" in "investing time in hobbies" = false. Return valid JSON only.';

        $result = LPP_AI_Client::query( $system, "Content:\n{$excerpt}\n\nPotential links:\n{$match_json}" );

        if ( is_wp_error( $result ) ) {
            $decisions = array();
            foreach ( $matches as $m ) {
                $decisions[] = array( 'id' => $m['id'], 'keyword' => $m['keyword'], 'link' => true );
            }
            return $decisions;
        }

        $result = trim( $result );
        $result = preg_replace( '/^```(?:json)?\s*/i', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $decisions = json_decode( $result, true );
        return is_array( $decisions ) ? $decisions : array();
    }

    private static function apply_links( $content, $decisions ) {
        foreach ( $decisions as $d ) {
            if ( empty( $d['link'] ) || empty( $d['keyword'] ) ) {
                continue;
            }

            $link = new LP_Link( $d['id'] );
            $url  = $link->get_cloaked_url();
            $rel  = $link->get_rel_tags();

            $rel_attr    = $rel ? ' rel="' . esc_attr( $rel ) . '"' : '';
            $target_attr = $link->opens_new_window() ? ' target="_blank"' : '';
            $replacement = '<a href="' . esc_url( $url ) . '"' . $rel_attr . $target_attr . '>$0</a>';

            $pattern = '/(?<![<\/a-zA-Z])(' . preg_quote( $d['keyword'], '/' ) . ')(?![^<]*<\/a>)(?![^<]*>)/i';
            $content = preg_replace( $pattern, $replacement, $content, 1 );
        }

        return $content;
    }
}
