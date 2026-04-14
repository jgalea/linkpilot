<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Migrator_PrettyLinks extends LP_Migrator {

    public static function is_available() {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        return $exists === $table && self::get_source_count() > 0;
    }

    public static function get_source_name() {
        return 'Pretty Links';
    }

    public static function get_source_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return 0;
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE link_status = 'enabled'" );
    }

    public static function get_source_ids( $offset, $limit ) {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return array();
        }
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE link_status = 'enabled' ORDER BY id ASC LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ) ) );
    }

    public function migrate_one( $source_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';

        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $source_id ), ARRAY_A );
        if ( ! $link ) {
            $this->results['skipped']++;
            return false;
        }

        $redirect_type  = in_array( $link['redirect_type'], array( '301', '302', '307' ), true ) ? $link['redirect_type'] : 'default';
        $pass_query_str = ! empty( $link['param_forwarding'] ) ? 'yes' : 'default';

        $new_id = $this->create_link( array(
            'old_id'          => (int) $link['id'],
            'title'           => $link['name'],
            'slug'            => $link['slug'],
            'destination_url' => $link['url'],
            'redirect_type'   => $redirect_type,
            'nofollow'        => $this->normalize_bool( (int) $link['nofollow'] ),
            'sponsored'       => $this->normalize_bool( (int) $link['sponsored'] ),
            'new_window'      => 'default',
            'css_classes'     => '',
            'pass_query_str'  => $pass_query_str,
            'rel_tags'        => '',
        ) );

        if ( ! $new_id ) {
            return false;
        }

        if ( ! empty( $link['link_cpt_id'] ) && taxonomy_exists( 'pretty-link-category' ) ) {
            $terms = wp_get_object_terms( (int) $link['link_cpt_id'], 'pretty-link-category', array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $term_ids = array();
                foreach ( $terms as $term_name ) {
                    $tid = $this->map_category( $term_name );
                    if ( $tid ) {
                        $term_ids[] = $tid;
                    }
                }
                if ( $term_ids ) {
                    wp_set_object_terms( $new_id, $term_ids, 'lp_category' );
                }
            }
        }

        $clicks_table = $wpdb->prefix . 'prli_clicks';
        $clicks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$clicks_table} WHERE link_id = %d",
            (int) $link['id']
        ), ARRAY_A );

        if ( ! empty( $clicks ) ) {
            foreach ( $clicks as $click ) {
                $this->import_click( $new_id, array(
                    'clicked_at'      => isset( $click['created_at'] ) ? $click['created_at'] : current_time( 'mysql', true ),
                    'referrer'        => isset( $click['referer'] ) ? $click['referer'] : '',
                    'country_code'    => '',
                    'user_agent_hash' => '',
                    'is_bot'          => isset( $click['robot'] ) ? (int) $click['robot'] : 0,
                ) );
            }
        }

        return $new_id;
    }
}
