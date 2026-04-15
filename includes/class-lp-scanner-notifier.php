<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email notifications for the Link Scanner.
 *
 * Sends a weekly digest listing broken / unreachable URLs detected in the
 * last 7 days. Disabled by default; opt-in via lp_scanner_notify_enabled.
 *
 * Additionally fires a real-time transition notification when a URL goes
 * from healthy to broken (requires lp_scanner_notify_on_break=yes).
 */
class LP_Scanner_Notifier {

    const CRON_HOOK = 'lp_scanner_digest_cron';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'send_digest' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    public static function schedule() {
        if ( get_option( 'lp_scanner_notify_enabled', 'no' ) !== 'yes' ) {
            self::unschedule();
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    public static function send_digest() {
        if ( get_option( 'lp_scanner_notify_enabled', 'no' ) !== 'yes' ) return;

        global $wpdb;
        $table = LP_Scanner_DB::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT url, status, http_code, checked_at, ref_count, error
             FROM {$table}
             WHERE status IN ('broken', 'error', 'server_error')
             AND checked_at >= %s
             ORDER BY ref_count DESC, url ASC",
            $since
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        $recipient = self::get_recipient();
        if ( ! $recipient ) return;

        $site  = wp_parse_url( home_url(), PHP_URL_HOST );
        $subj  = sprintf( '[%s] LinkPilot: %d broken link%s this week', $site, count( $rows ), count( $rows ) === 1 ? '' : 's' );
        $admin = admin_url( 'admin.php?page=lp-scanner' );

        $lines   = array();
        $lines[] = "LinkPilot Scanner weekly digest";
        $lines[] = "";
        $lines[] = sprintf( "Found %d broken or unreachable URLs on %s.", count( $rows ), $site );
        $lines[] = "";
        foreach ( $rows as $r ) {
            $lines[] = sprintf(
                "• [%s %s] %s — in %d post%s",
                $r->status,
                $r->http_code ?: '—',
                $r->url,
                $r->ref_count,
                $r->ref_count === 1 ? '' : 's'
            );
            if ( $r->error ) {
                $lines[] = "    " . $r->error;
            }
        }
        $lines[] = "";
        $lines[] = "Fix these from your dashboard: " . $admin;

        $body = implode( "\n", $lines );
        wp_mail( $recipient, $subj, $body );
    }

    public static function maybe_send_transition( $url, $old_status, $new_status ) {
        if ( get_option( 'lp_scanner_notify_on_break', 'no' ) !== 'yes' ) return;

        $healthy_to_broken = ( in_array( $old_status, array( 'healthy', 'redirect', 'unchecked' ), true )
                            && in_array( $new_status, array( 'broken', 'error', 'server_error' ), true ) );
        if ( ! $healthy_to_broken ) return;

        $recipient = self::get_recipient();
        if ( ! $recipient ) return;

        $site = wp_parse_url( home_url(), PHP_URL_HOST );
        $subj = sprintf( '[%s] LinkPilot: link broken — %s', $site, $url );
        $body = "A previously healthy URL just started returning errors.\n\n"
              . "URL: {$url}\n"
              . "New status: {$new_status}\n\n"
              . "Manage: " . admin_url( 'admin.php?page=lp-scanner' );
        wp_mail( $recipient, $subj, $body );
    }

    private static function get_recipient() {
        $custom = get_option( 'lp_scanner_notify_recipient', '' );
        if ( $custom && is_email( $custom ) ) return $custom;
        return get_option( 'admin_email' );
    }
}
