<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Migrator_LinkCentral extends LP_Migrator {

    public static function is_available() {
        return self::get_source_count() > 0;
    }

    public static function get_source_name() {
        return 'LinkCentral';
    }

    public static function get_source_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'linkcentral_link'
             AND post_status IN ('publish', 'draft', 'private')"
        );
    }

    public static function get_source_ids( $offset, $limit ) {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'linkcentral_link'
             AND post_status IN ('publish', 'draft', 'private')
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ) ) );
    }

    public function migrate_one( $source_id ) {
        $post = get_post( $source_id );
        if ( ! $post || $post->post_type !== 'linkcentral_link' ) {
            $this->results['skipped']++;
            return false;
        }

        $redirect_type = get_post_meta( $post->ID, '_linkcentral_redirection_type', true );
        $nofollow      = get_post_meta( $post->ID, '_linkcentral_nofollow', true );
        $new_tab       = get_post_meta( $post->ID, '_linkcentral_new_tab', true );
        $sponsored     = get_post_meta( $post->ID, '_linkcentral_sponsored', true );
        $pass_query    = get_post_meta( $post->ID, '_linkcentral_parameter_forwarding', true );
        $css_classes   = get_post_meta( $post->ID, '_linkcentral_custom_css_classes', true );
        $dest_url      = get_post_meta( $post->ID, '_linkcentral_destination_url', true );

        $link_id = $this->create_link( array(
            'old_id'          => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'destination_url' => $dest_url,
            'redirect_type'   => $redirect_type ? $redirect_type : 'default',
            'nofollow'        => $this->normalize_bool( $nofollow ),
            'sponsored'       => $this->normalize_bool( $sponsored ),
            'new_window'      => $this->normalize_bool( $new_tab ),
            'css_classes'     => $css_classes ? $css_classes : '',
            'pass_query_str'  => $this->normalize_bool( $pass_query ),
        ) );

        if ( ! $link_id ) {
            return false;
        }

        $this->migrate_categories( $post->ID, $link_id );
        $this->migrate_clicks( $post->ID, $link_id );

        return $link_id;
    }

    private function migrate_categories( $old_post_id, $link_id ) {
        $terms = wp_get_object_terms( $old_post_id, 'linkcentral_category', array( 'orderby' => 'parent' ) );
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
        $table = $wpdb->prefix . 'linkcentral_stats';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT click_date, referring_url, user_agent, country FROM {$table} WHERE link_id = %d",
            $old_post_id
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $this->import_click( $link_id, array(
                'clicked_at'      => $row->click_date,
                'referrer'        => isset( $row->referring_url ) ? $row->referring_url : '',
                'country_code'    => isset( $row->country ) ? $row->country : '',
                'user_agent_hash' => isset( $row->user_agent ) ? md5( $row->user_agent ) : '',
                'is_bot'          => 0,
            ) );
        }
    }
}
