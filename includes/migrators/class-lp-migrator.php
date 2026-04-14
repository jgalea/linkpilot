<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class LP_Migrator {

    protected $results = array(
        'links'      => 0,
        'categories' => 0,
        'clicks'     => 0,
        'skipped'    => 0,
        'errors'     => 0,
    );

    protected $id_map = array();

    abstract public static function is_available();

    abstract public static function get_source_name();

    abstract public static function get_source_count();

    abstract public static function get_source_ids( $offset, $limit );

    abstract public function migrate_one( $source_id );

    public function run() {
        $offset = 0;
        $limit  = 50;
        while ( true ) {
            $ids = static::get_source_ids( $offset, $limit );
            if ( empty( $ids ) ) {
                break;
            }
            foreach ( $ids as $source_id ) {
                $this->migrate_one( $source_id );
            }
            if ( count( $ids ) < $limit ) {
                break;
            }
            $offset += $limit;
        }
        return $this->results;
    }

    public function set_id_map( array $map ) {
        $this->id_map = $map;
    }

    public function set_results( array $results ) {
        $this->results = array_merge( $this->results, $results );
    }

    protected function create_link( array $data ) {
        $slug = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';

        if ( $slug ) {
            $existing = LP_Link::find_by_slug( $slug );
            if ( $existing ) {
                if ( isset( $data['old_id'] ) ) {
                    $this->id_map[ $data['old_id'] ] = $existing->get_id();
                }
                $this->results['skipped']++;
                return $existing->get_id();
            }
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'lp_link',
            'post_status' => 'publish',
            'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
            'post_name'   => $slug,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            $this->results['errors']++;
            return false;
        }

        $link = new LP_Link( $post_id );
        $link->save_meta( array(
            'destination_url' => isset( $data['destination_url'] ) ? $data['destination_url'] : '',
            'redirect_type'   => isset( $data['redirect_type'] ) ? $data['redirect_type'] : 'default',
            'nofollow'        => isset( $data['nofollow'] ) ? $data['nofollow'] : 'default',
            'sponsored'       => isset( $data['sponsored'] ) ? $data['sponsored'] : 'default',
            'new_window'      => isset( $data['new_window'] ) ? $data['new_window'] : 'default',
            'css_classes'     => isset( $data['css_classes'] ) ? $data['css_classes'] : '',
            'pass_query_str'  => isset( $data['pass_query_str'] ) ? $data['pass_query_str'] : 'default',
            'rel_tags'        => isset( $data['rel_tags'] ) ? $data['rel_tags'] : '',
        ) );

        if ( isset( $data['old_id'] ) ) {
            $this->id_map[ $data['old_id'] ] = $post_id;
        }

        $this->results['links']++;
        return $post_id;
    }

    protected function map_category( $name, $parent_id = 0 ) {
        $existing = get_term_by( 'name', $name, 'lp_category' );
        if ( $existing && (int) $existing->parent === (int) $parent_id ) {
            return $existing->term_id;
        }

        $result = wp_insert_term( $name, 'lp_category', array(
            'parent' => (int) $parent_id,
        ) );

        if ( is_wp_error( $result ) ) {
            if ( isset( $result->error_data['term_exists'] ) ) {
                return (int) $result->error_data['term_exists'];
            }
            $this->results['errors']++;
            return 0;
        }

        $this->results['categories']++;
        return (int) $result['term_id'];
    }

    protected function import_click( $link_id, array $click_data ) {
        $result = LP_Clicks_DB::insert( array(
            'link_id'        => (int) $link_id,
            'clicked_at'     => isset( $click_data['clicked_at'] ) ? $click_data['clicked_at'] : current_time( 'mysql', true ),
            'referrer'       => isset( $click_data['referrer'] ) ? $click_data['referrer'] : '',
            'country_code'   => isset( $click_data['country_code'] ) ? $click_data['country_code'] : '',
            'user_agent_hash' => isset( $click_data['user_agent_hash'] ) ? $click_data['user_agent_hash'] : '',
            'is_bot'         => isset( $click_data['is_bot'] ) ? (int) $click_data['is_bot'] : 0,
        ) );

        if ( false === $result ) {
            $this->results['errors']++;
            return false;
        }

        $this->results['clicks']++;
        return true;
    }

    protected function normalize_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }
        $normalized = strtolower( trim( (string) $value ) );
        if ( in_array( $normalized, array( 'yes', '1', 'true', 'on' ), true ) ) {
            return 'yes';
        }
        if ( in_array( $normalized, array( 'no', '0', 'false', 'off' ), true ) ) {
            return 'no';
        }
        return 'default';
    }

    public function get_results() {
        return $this->results;
    }

    public function get_id_map() {
        return $this->id_map;
    }
}
