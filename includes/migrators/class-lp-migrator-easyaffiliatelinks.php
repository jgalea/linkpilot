<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Migrator_EasyAffiliateLinks extends LP_Migrator {

    public static function is_available() {
        return self::get_source_count() > 0;
    }

    public static function get_source_name() {
        return 'Easy Affiliate Links';
    }

    public static function get_source_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'easy_affiliate_link'
             AND post_status IN ('publish', 'draft', 'private')"
        );
    }

    public static function get_source_ids( $offset, $limit ) {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'easy_affiliate_link'
             AND post_status IN ('publish', 'draft', 'private')
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ) ) );
    }

    public function migrate_one( $source_id ) {
        $post = get_post( $source_id );
        if ( ! $post || $post->post_type !== 'easy_affiliate_link' ) {
            $this->results['skipped']++;
            return false;
        }

        $meta = get_post_meta( $post->ID );

        $nofollow_val = ( isset( $meta['eafl_nofollow'][0] ) && 'nofollow' === $meta['eafl_nofollow'][0] ) ? 'yes' : 'default';

        $link_id = $this->create_link( array(
            'old_id'          => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'destination_url' => isset( $meta['eafl_url'][0] ) ? $meta['eafl_url'][0] : '',
            'redirect_type'   => isset( $meta['eafl_redirect_type'][0] ) ? $meta['eafl_redirect_type'][0] : 'default',
            'nofollow'        => $nofollow_val,
            'sponsored'       => ( isset( $meta['eafl_sponsored'][0] ) && $meta['eafl_sponsored'][0] ) ? 'yes' : 'default',
            'new_window'      => ( isset( $meta['eafl_target'][0] ) && '_blank' === $meta['eafl_target'][0] ) ? 'yes' : 'default',
            'css_classes'     => isset( $meta['eafl_classes'][0] ) ? $meta['eafl_classes'][0] : '',
            'pass_query_str'  => 'default',
            'rel_tags'        => '',
        ) );

        if ( ! $link_id ) {
            return false;
        }

        $this->migrate_categories( $post->ID, $link_id );
        $this->migrate_clicks( $post->ID, $link_id );

        return $link_id;
    }

    private function migrate_categories( $old_post_id, $link_id ) {
        $terms = wp_get_object_terms( $old_post_id, 'eafl_category', array( 'orderby' => 'parent' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_id_map  = array();
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
        $clicks_table = $wpdb->prefix . 'eafl_clicks';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$clicks_table}'" ) !== $clicks_table ) {
            return;
        }

        $clicks = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date, referer, agent FROM {$clicks_table} WHERE link_id = %d",
            $old_post_id
        ) );

        if ( empty( $clicks ) ) {
            return;
        }

        foreach ( $clicks as $click ) {
            $user_agent = isset( $click->agent ) ? (string) $click->agent : '';

            $this->import_click( $link_id, array(
                'clicked_at'      => $click->date,
                'referrer'        => isset( $click->referer ) ? (string) $click->referer : '',
                'country_code'    => '',
                'user_agent_hash' => $user_agent ? md5( $user_agent ) : '',
                'is_bot'          => 0,
            ) );
        }
    }
}
