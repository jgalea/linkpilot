<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete all lp_link posts and their meta
$posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'lp_link'" );
if ( $posts ) {
    foreach ( $posts as $post_id ) {
        wp_delete_post( (int) $post_id, true );
    }
}

// Delete custom taxonomy terms
$terms = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('lp_category', 'lp_tag')" );
if ( $terms ) {
    foreach ( $terms as $term_id ) {
        wp_delete_term( (int) $term_id, 'lp_category' );
        wp_delete_term( (int) $term_id, 'lp_tag' );
    }
}

// Unschedule all cron hooks
wp_clear_scheduled_hook( 'lp_health_check_cron' );
wp_clear_scheduled_hook( 'lp_scanner_cron_tick' );
wp_clear_scheduled_hook( 'lp_scanner_digest_cron' );

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lp_clicks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lp_scanner_urls" );

// Delete scanner post meta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_lp_scanner_urls', '_lp_scanner_last_scan')" );

// Delete options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lp\_%'" );

// Flush rewrite rules
flush_rewrite_rules();
