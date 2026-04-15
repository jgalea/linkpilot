<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scanning containers beyond post_content.
 *
 * Each method returns an array of URLs extracted from the given scope.
 * Callers feed the results into LP_Scanner_DB::upsert() and track them
 * as they would post-content URLs.
 *
 * Enabled containers are controlled by the lp_scanner_containers option,
 * a CSV of: content,comments,meta,acf
 */
class LP_Scanner_Containers {

    public static function enabled( $container ) {
        $raw = get_option( 'lp_scanner_containers', 'content' );
        $set = array_map( 'trim', explode( ',', $raw ) );
        return in_array( $container, $set, true );
    }

    /**
     * Scan all approved comments on a post. Returns array<url>.
     */
    public static function extract_from_comments( $post_id ) {
        if ( ! self::enabled( 'comments' ) ) return array();

        $comments = get_comments( array(
            'post_id' => $post_id,
            'status'  => 'approve',
            'type'    => 'comment',
            'fields'  => 'all',
        ) );

        $urls = array();
        foreach ( $comments as $c ) {
            if ( ! empty( $c->comment_author_url ) ) {
                $normalized = LP_Scanner_Extractor::normalize_public( $c->comment_author_url );
                if ( $normalized ) $urls[ $normalized ] = true;
            }
            if ( ! empty( $c->comment_content ) ) {
                foreach ( LP_Scanner_Extractor::extract( $c->comment_content ) as $u ) {
                    $urls[ $u ] = true;
                }
            }
        }
        return array_keys( $urls );
    }

    /**
     * Scan custom fields (post meta) that look like URLs.
     * Only returns values whose string matches a URL pattern — avoids
     * slurping serialized arrays or unrelated meta.
     */
    public static function extract_from_meta( $post_id ) {
        if ( ! self::enabled( 'meta' ) ) return array();

        $meta = get_post_meta( $post_id );
        $urls = array();
        foreach ( $meta as $key => $values ) {
            if ( strpos( $key, '_lp_' ) === 0 || strpos( $key, '_lpp_' ) === 0 || strpos( $key, '_edit_' ) === 0 ) {
                continue; // skip our own meta and WP internals
            }
            foreach ( (array) $values as $value ) {
                if ( ! is_string( $value ) ) continue;
                if ( preg_match( '#^https?://#i', $value ) ) {
                    $normalized = LP_Scanner_Extractor::normalize_public( $value );
                    if ( $normalized ) $urls[ $normalized ] = true;
                }
            }
        }
        return array_keys( $urls );
    }

    /**
     * Scan Advanced Custom Fields URL / link / text fields on a post,
     * if the ACF plugin is active.
     */
    public static function extract_from_acf( $post_id ) {
        if ( ! self::enabled( 'acf' ) ) return array();
        if ( ! function_exists( 'get_field_objects' ) ) return array();

        $fields = get_field_objects( $post_id );
        if ( ! $fields ) return array();

        $urls = array();
        self::walk_acf_fields( $fields, $urls );
        return array_keys( $urls );
    }

    private static function walk_acf_fields( $fields, array &$urls ) {
        foreach ( $fields as $field ) {
            $type  = isset( $field['type'] ) ? $field['type'] : '';
            $value = isset( $field['value'] ) ? $field['value'] : null;

            if ( $type === 'url' && is_string( $value ) && $value !== '' ) {
                $normalized = LP_Scanner_Extractor::normalize_public( $value );
                if ( $normalized ) $urls[ $normalized ] = true;
            }

            if ( $type === 'link' && is_array( $value ) && ! empty( $value['url'] ) ) {
                $normalized = LP_Scanner_Extractor::normalize_public( $value['url'] );
                if ( $normalized ) $urls[ $normalized ] = true;
            }

            if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) && is_string( $value ) ) {
                foreach ( LP_Scanner_Extractor::extract( $value ) as $u ) {
                    $urls[ $u ] = true;
                }
            }

            if ( in_array( $type, array( 'repeater', 'group', 'flexible_content' ), true ) && is_array( $value ) ) {
                foreach ( $value as $sub ) {
                    if ( is_array( $sub ) ) {
                        $wrapped = array();
                        foreach ( $sub as $sub_key => $sub_val ) {
                            $wrapped[] = array( 'type' => self::guess_acf_type( $sub_val ), 'value' => $sub_val );
                        }
                        self::walk_acf_fields( $wrapped, $urls );
                    }
                }
            }
        }
    }

    private static function guess_acf_type( $value ) {
        if ( is_string( $value ) && preg_match( '#^https?://#i', $value ) ) return 'url';
        if ( is_array( $value ) && ! empty( $value['url'] ) )              return 'link';
        if ( is_string( $value ) )                                          return 'text';
        return 'unknown';
    }
}
