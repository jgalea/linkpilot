<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bulk URL rewriting and unlinking across post content.
 *
 * Given a URL, find every post that references it (via the scanner's
 * post-meta index), then either:
 *   - replace the URL with a new one in post_content, or
 *   - unlink: remove the <a href="URL"> wrapper, keeping the link text.
 *
 * Both operations create WP revisions so the change is reversible per-post.
 */
class LP_Scanner_Rewriter {

    /**
     * Find all published post IDs that reference $url.
     * Uses the scanner's post meta index for O(1) lookup per post.
     */
    public static function find_posts_with_url( $url ) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $url ) . '%';
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type IN ('post', 'page')
             AND p.post_status = 'publish'
             AND pm.meta_key = %s
             AND pm.meta_value LIKE %s",
            LP_Scanner::META_POST_URLS,
            $like
        ) ) );
    }

    /**
     * Replace $old_url with $new_url across all post content that references it.
     * Returns count of posts updated.
     */
    public static function rewrite( $old_url, $new_url ) {
        $new_url = esc_url_raw( $new_url );
        if ( ! $new_url || $new_url === $old_url ) {
            return 0;
        }

        $post_ids = self::find_posts_with_url( $old_url );
        $updated  = 0;

        foreach ( $post_ids as $pid ) {
            $post = get_post( $pid );
            if ( ! $post ) continue;

            $new_content = self::replace_url_in_content( $post->post_content, $old_url, $new_url );
            if ( $new_content !== $post->post_content ) {
                wp_update_post( array(
                    'ID'           => $pid,
                    'post_content' => $new_content,
                ) );
                // Re-scan the post so the URL index is in sync.
                LP_Scanner::scan_post( $pid );
                $updated++;
            }
        }

        // Refresh ref counts so the old URL drops out and the new one is tracked.
        LP_Scanner_DB::refresh_ref_counts();

        return $updated;
    }

    /**
     * Remove the <a href="URL">...</a> wrapper for every occurrence of $url,
     * leaving the inner text intact.
     */
    public static function unlink( $url ) {
        $post_ids = self::find_posts_with_url( $url );
        $updated  = 0;

        foreach ( $post_ids as $pid ) {
            $post = get_post( $pid );
            if ( ! $post ) continue;

            $new_content = self::strip_anchor_for_url( $post->post_content, $url );
            if ( $new_content !== $post->post_content ) {
                wp_update_post( array(
                    'ID'           => $pid,
                    'post_content' => $new_content,
                ) );
                LP_Scanner::scan_post( $pid );
                $updated++;
            }
        }

        LP_Scanner_DB::refresh_ref_counts();
        return $updated;
    }

    /**
     * Replace the exact URL string anywhere it appears, whether inside an href,
     * an img src, a srcset, an iframe src, or as plain text.
     */
    private static function replace_url_in_content( $content, $old, $new ) {
        return str_replace( $old, $new, $content );
    }

    /**
     * Strip <a href="$url">INNER</a> → INNER for a specific URL.
     * Handles single/double quotes and additional attributes on the tag.
     */
    private static function strip_anchor_for_url( $content, $url ) {
        $quoted = preg_quote( $url, '/' );
        $pattern = '/<a\s[^>]*href=(["\'])' . $quoted . '\1[^>]*>(.*?)<\/a>/is';
        return preg_replace( $pattern, '$2', $content );
    }
}
