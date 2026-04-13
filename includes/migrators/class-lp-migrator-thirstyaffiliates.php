<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Migrator_ThirstyAffiliates extends LP_Migrator {

    public static function is_available() {
        $count = wp_count_posts( 'thirstylink' );
        if ( ! $count ) {
            return false;
        }
        return ( $count->publish + $count->draft + $count->private ) > 0;
    }

    public static function get_source_name() {
        return 'ThirstyAffiliates';
    }

    public static function get_source_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'thirstylink'
             AND post_status IN ('publish', 'draft', 'private')"
        );
    }

    public function run() {
        global $wpdb;

        $posts = get_posts( array(
            'post_type'      => 'thirstylink',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );

        foreach ( $posts as $post ) {
            $meta = get_post_meta( $post->ID );

            $destination_url  = isset( $meta['_ta_destination_url'][0] ) ? $meta['_ta_destination_url'][0] : '';
            $redirect_type    = isset( $meta['_ta_redirect_type'][0] ) ? $meta['_ta_redirect_type'][0] : 'default';
            $nofollow         = isset( $meta['_ta_no_follow'][0] ) ? $this->normalize_bool( $meta['_ta_no_follow'][0] ) : 'default';
            $new_window       = isset( $meta['_ta_new_window'][0] ) ? $this->normalize_bool( $meta['_ta_new_window'][0] ) : 'default';
            $pass_query_str   = isset( $meta['_ta_pass_query_str'][0] ) ? $this->normalize_bool( $meta['_ta_pass_query_str'][0] ) : 'default';
            $css_classes      = isset( $meta['_ta_css_classes'][0] ) ? $meta['_ta_css_classes'][0] : '';
            $rel_tags         = isset( $meta['_ta_rel_tags'][0] ) ? $meta['_ta_rel_tags'][0] : '';

            $link_id = $this->create_link( array(
                'old_id'          => $post->ID,
                'title'           => $post->post_title,
                'slug'            => $post->post_name,
                'destination_url' => $destination_url,
                'redirect_type'   => $redirect_type,
                'nofollow'        => $nofollow,
                'sponsored'       => 'default',
                'new_window'      => $new_window,
                'css_classes'     => $css_classes,
                'pass_query_str'  => $pass_query_str,
                'rel_tags'        => $rel_tags,
            ) );

            if ( ! $link_id ) {
                continue;
            }

            $this->migrate_categories( $post->ID, $link_id );
            $this->migrate_clicks( $post->ID, $link_id );
        }

        return $this->results;
    }

    private function migrate_categories( $old_post_id, $link_id ) {
        $terms = wp_get_object_terms( $old_post_id, 'thirstylink-category', array( 'orderby' => 'parent' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_id_map = array();
        $new_term_ids = array();

        foreach ( $terms as $term ) {
            $parent_id = 0;
            if ( $term->parent && isset( $term_id_map[ $term->parent ] ) ) {
                $parent_id = $term_id_map[ $term->parent ];
            }

            $new_term_id = $this->map_category( $term->name, $parent_id );
            $term_id_map[ $term->term_id ] = $new_term_id;

            if ( $new_term_id ) {
                $new_term_ids[] = $new_term_id;
            }
        }

        if ( $new_term_ids ) {
            wp_set_object_terms( $link_id, $new_term_ids, 'lp_category' );
        }
    }

    private function migrate_clicks( $old_post_id, $link_id ) {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'ta_link_clicks';
        $meta_table   = $wpdb->prefix . 'ta_link_clicks_meta';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$clicks_table}'" ) !== $clicks_table ) {
            return;
        }

        $has_meta = $wpdb->get_var( "SHOW TABLES LIKE '{$meta_table}'" ) === $meta_table;

        if ( $has_meta ) {
            $clicks = $wpdb->get_results( $wpdb->prepare(
                "SELECT c.id, c.date_clicked,
                        MAX( CASE WHEN m.meta_key = 'http_referer' THEN m.meta_value END ) AS referrer,
                        MAX( CASE WHEN m.meta_key = 'browser_device' THEN m.meta_value END ) AS user_agent
                 FROM {$clicks_table} c
                 LEFT JOIN {$meta_table} m ON m.click_id = c.id
                 WHERE c.link_id = %d
                 GROUP BY c.id, c.date_clicked",
                $old_post_id
            ) );
        } else {
            $clicks = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, date_clicked FROM {$clicks_table} WHERE link_id = %d",
                $old_post_id
            ) );
        }

        if ( empty( $clicks ) ) {
            return;
        }

        foreach ( $clicks as $click ) {
            $referrer   = isset( $click->referrer ) ? (string) $click->referrer : '';
            $user_agent = isset( $click->user_agent ) ? (string) $click->user_agent : '';

            $this->import_click( $link_id, array(
                'clicked_at'      => $click->date_clicked,
                'referrer'        => $referrer,
                'country_code'    => '',
                'user_agent_hash' => $user_agent ? md5( $user_agent ) : '',
                'is_bot'          => 0,
            ) );
        }
    }
}
