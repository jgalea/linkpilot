<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Content_Gaps {

    const CRON_HOOK  = 'lpp_content_gaps_scan';
    const OPTION_KEY = 'lpp_content_gaps_results';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_scan' ) );
        self::schedule();
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    public static function run_scan() {
        $api_key = get_option( 'lpp_ai_api_key', '' );
        if ( ! $api_key ) {
            return;
        }

        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array(
                array( 'after' => '30 days ago' ),
            ),
            'no_found_rows' => true,
        ) );

        if ( empty( $posts ) ) {
            return;
        }

        $links = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ) );

        $link_titles = array();
        foreach ( $links as $link_id ) {
            $link_titles[] = get_the_title( $link_id );
        }

        $summaries = array();
        foreach ( $posts as $post ) {
            $summaries[] = $post->post_title . ': ' . wp_trim_words( wp_strip_all_tags( $post->post_content ), 100 );
        }

        $content_block  = implode( "\n---\n", $summaries );
        $existing_links = implode( ', ', $link_titles );

        $system = 'You analyze blog content to find topics, products, or services that are mentioned frequently but don\'t have a managed link. Given summaries of recent posts and a list of existing managed link names, identify gaps. Return a JSON array of objects: { "topic": string, "mentions": number, "posts": [string] } where topic is the product/service name, mentions is approximate count across all posts, and posts lists the post titles mentioning it. Only include topics mentioned in 2+ posts. Max 10 results. Return valid JSON only.';

        $user_msg = "Recent posts:\n{$content_block}\n\nExisting managed links: {$existing_links}";

        $result = LPP_AI_Client::query( $system, $user_msg );

        if ( is_wp_error( $result ) ) {
            return;
        }

        $result = trim( $result );
        $result = preg_replace( '/^```(?:json)?\s*/i', '', $result );
        $result = preg_replace( '/\s*```$/', '', $result );

        $gaps = json_decode( $result, true );
        if ( is_array( $gaps ) ) {
            update_option( self::OPTION_KEY, array(
                'gaps'       => $gaps,
                'scanned_at' => current_time( 'mysql' ),
            ) );
        }
    }

    public static function get_results() {
        return get_option( self::OPTION_KEY, array() );
    }
}
