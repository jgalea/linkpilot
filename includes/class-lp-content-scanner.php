<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Content_Scanner {

    private $id_map;
    private $source;

    public function __construct( array $id_map, $source ) {
        $this->id_map = $id_map;
        $this->source = $source;
    }

    public function scan_and_replace() {
        $results = array(
            'posts_scanned' => 0,
            'replacements'  => 0,
        );

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );

        foreach ( $posts as $post ) {
            $results['posts_scanned']++;

            $new_content = $this->replace_in_content( $post->post_content, $results );

            if ( $new_content !== $post->post_content ) {
                wp_update_post( array(
                    'ID'           => $post->ID,
                    'post_content' => $new_content,
                ) );
            }
        }

        return $results;
    }

    private function replace_in_content( $content, &$results ) {
        switch ( $this->source ) {
            case 'ThirstyAffiliates':
                return $this->replace_thirstyaffiliates( $content, $results );
            case 'Pretty Links':
                return $this->replace_prettylinks( $content, $results );
            case 'LinkCentral':
                return $this->replace_linkcentral( $content, $results );
            case 'Easy Affiliate Links':
                return $this->replace_easyaffiliatelinks( $content, $results );
        }

        return $content;
    }

    private function replace_thirstyaffiliates( $content, &$results ) {
        // Replace [thirstylink ids="123"] and [thirstylink linkid="123"] shortcodes.
        $content = preg_replace_callback(
            '/\[thirstylink\s+(?:ids|linkid)=[\'"]?(\d+)[\'"]?\](.*?)\[\/thirstylink\]/is',
            function ( $matches ) use ( &$results ) {
                $replacement = $this->build_link_html( (int) $matches[1], $matches[2] );
                if ( $replacement !== $matches[2] ) {
                    $results['replacements']++;
                }
                return $replacement;
            },
            $content
        );

        // Replace href attributes pointing to old TA cloaked URLs (e.g. site.com/go/slug).
        $ta_prefix = get_option( 'ta_link_prefix', 'go' );
        $home_url  = home_url();

        $content = preg_replace_callback(
            '/href=[\'"](' . preg_quote( $home_url, '/' ) . '\/' . preg_quote( $ta_prefix, '/' ) . '\/([^\'"\s]+))[\'"]/',
            function ( $matches ) use ( &$results ) {
                $slug = sanitize_title( rtrim( $matches[2], '/' ) );
                $link = LP_Link::find_by_slug( $slug );
                if ( ! $link ) {
                    return $matches[0];
                }
                $results['replacements']++;
                return 'href="' . esc_url( $link->get_cloaked_url() ) . '"';
            },
            $content
        );

        return $content;
    }

    private function replace_prettylinks( $content, &$results ) {
        $home_url = home_url();

        foreach ( $this->id_map as $old_id => $new_lp_id ) {
            $link = new LP_Link( $new_lp_id );
            $slug = $link->get_slug();
            if ( ! $slug ) {
                continue;
            }

            $old_url    = $home_url . '/' . $slug;
            $new_url    = $link->get_cloaked_url();
            $escaped    = preg_quote( $old_url, '/' );
            $new_content = preg_replace( '/href=[\'"]' . $escaped . '\/?[\'"]/', 'href="' . esc_url( $new_url ) . '"', $content );

            if ( $new_content !== $content ) {
                $results['replacements']++;
                $content = $new_content;
            }
        }

        return $content;
    }

    private function replace_linkcentral( $content, &$results ) {
        return preg_replace_callback(
            '/\[linkcentral\s+id=[\'"]?(\d+)[\'"]?\](.*?)\[\/linkcentral\]/is',
            function ( $matches ) use ( &$results ) {
                $replacement = $this->build_link_html( (int) $matches[1], $matches[2] );
                if ( $replacement !== $matches[2] ) {
                    $results['replacements']++;
                }
                return $replacement;
            },
            $content
        );
    }

    private function replace_easyaffiliatelinks( $content, &$results ) {
        // Replace [eafl id="123"] shortcodes.
        $content = preg_replace_callback(
            '/\[eafl\s+id=[\'"]?(\d+)[\'"]?\](.*?)\[\/eafl\]/is',
            function ( $matches ) use ( &$results ) {
                $replacement = $this->build_link_html( (int) $matches[1], $matches[2] );
                if ( $replacement !== $matches[2] ) {
                    $results['replacements']++;
                }
                return $replacement;
            },
            $content
        );

        // Replace <a> tags with data-eafl-id="123" attribute.
        $content = preg_replace_callback(
            '/<a\s[^>]*data-eafl-id=[\'"]?(\d+)[\'"]?[^>]*>(.*?)<\/a>/is',
            function ( $matches ) use ( &$results ) {
                $replacement = $this->build_link_html( (int) $matches[1], $matches[2] );
                if ( $replacement !== $matches[2] ) {
                    $results['replacements']++;
                }
                return $replacement;
            },
            $content
        );

        return $content;
    }

    private function build_link_html( $old_id, $text ) {
        if ( ! isset( $this->id_map[ $old_id ] ) ) {
            return $text;
        }

        $link   = new LP_Link( $this->id_map[ $old_id ] );
        $href   = esc_url( $link->get_cloaked_url() );
        $rel    = $link->get_rel_tags();
        $target = $link->opens_new_window() ? ' target="_blank"' : '';
        $rel    = $rel ? ' rel="' . esc_attr( $rel ) . '"' : '';

        return '<a href="' . $href . '"' . $rel . $target . '>' . $text . '</a>';
    }
}
