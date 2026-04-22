<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Weekly click digest email.
 *
 * Opt-in. When enabled, sends a summary email every Monday:
 *   - Total clicks last 7 days (vs previous 7 days)
 *   - Top 10 links by clicks
 *   - Top 10 referrers
 *   - New 404s logged
 *
 * Settings:
 *   lp_digest_enabled (yes/no, default no)
 *   lp_digest_recipient (email; default = admin_email)
 */
class LP_Digest {

    const CRON_HOOK = 'lp_digest_send';

    public static function init() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( self::next_monday(), 'weekly', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    public static function add_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'linkpilot' ),
            );
        }
        return $schedules;
    }

    private static function next_monday() {
        $ts = strtotime( 'next monday 07:00 UTC' );
        return $ts ?: ( time() + WEEK_IN_SECONDS );
    }

    public static function maybe_send() {
        if ( get_option( 'lp_digest_enabled', 'no' ) !== 'yes' ) {
            return;
        }
        $recipient = get_option( 'lp_digest_recipient', '' );
        if ( empty( $recipient ) ) {
            $recipient = get_option( 'admin_email' );
        }
        if ( empty( $recipient ) || ! is_email( $recipient ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: site name */
            __( '[LinkPilot] Weekly click digest — %s', 'linkpilot' ),
            get_bloginfo( 'name' )
        );

        $body = self::build_body();
        wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    private static function build_body() {
        $days = 7;
        $daily_now   = LP_Clicks_DB::get_site_clicks_by_day( $days );
        $daily_prev  = self::prev_period_totals( $days );
        $top_referrers = LP_Clicks_DB::get_site_top_referrers( $days, 10 );
        $top_links     = self::top_links( $days, 10 );
        $new_404s      = self::recent_404s( $days );

        $total_now  = 0;
        foreach ( $daily_now as $d ) {
            $total_now += (int) $d->clicks;
        }
        $delta = $total_now - $daily_prev;
        $delta_pct = $daily_prev > 0 ? round( ( $delta / $daily_prev ) * 100 ) : null;

        ob_start();
        ?>
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 640px; margin: 0 auto; color: #1d2327;">
            <h1 style="margin-top:0;"><?php esc_html_e( 'LinkPilot weekly digest', 'linkpilot' ); ?></h1>
            <p><?php
                /* translators: 1: week start date, 2: week end date */
                echo esc_html( sprintf( __( 'Summary for %1$s — %2$s.', 'linkpilot' ),
                    gmdate( 'M j', strtotime( '-7 days' ) ),
                    gmdate( 'M j', time() )
                ) );
            ?></p>

            <h2><?php esc_html_e( 'Clicks', 'linkpilot' ); ?></h2>
            <p style="font-size: 28px; font-weight: 600; margin: 0;"><?php echo esc_html( number_format_i18n( $total_now ) ); ?></p>
            <p style="color: #646970; margin-top: 4px;">
                <?php
                if ( null !== $delta_pct ) {
                    $arrow = $delta >= 0 ? '▲' : '▼';
                    /* translators: 1: arrow, 2: percent delta, 3: previous count */
                    echo esc_html( sprintf( __( '%1$s %2$d%% vs the previous 7 days (%3$s)', 'linkpilot' ),
                        $arrow,
                        abs( $delta_pct ),
                        number_format_i18n( $daily_prev )
                    ) );
                } else {
                    esc_html_e( 'No comparison — no clicks last period.', 'linkpilot' );
                }
                ?>
            </p>

            <?php if ( ! empty( $top_links ) ) : ?>
                <h2><?php esc_html_e( 'Top links', 'linkpilot' ); ?></h2>
                <table style="width:100%; border-collapse: collapse;">
                    <?php foreach ( $top_links as $row ) :
                        $title = get_the_title( (int) $row->link_id ) ?: '#' . (int) $row->link_id;
                        ?>
                        <tr>
                            <td style="padding: 4px 0; border-bottom: 1px solid #eee;"><?php echo esc_html( $title ); ?></td>
                            <td style="padding: 4px 0; text-align: right; border-bottom: 1px solid #eee; color: #646970;"><?php echo esc_html( number_format_i18n( (int) $row->clicks ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $top_referrers ) ) : ?>
                <h2><?php esc_html_e( 'Top referrers', 'linkpilot' ); ?></h2>
                <table style="width:100%; border-collapse: collapse;">
                    <?php foreach ( $top_referrers as $row ) : ?>
                        <tr>
                            <td style="padding: 4px 0; border-bottom: 1px solid #eee; word-break: break-all;"><?php echo esc_html( $row->referrer ); ?></td>
                            <td style="padding: 4px 0; text-align: right; border-bottom: 1px solid #eee; color: #646970;"><?php echo esc_html( number_format_i18n( (int) $row->clicks ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if ( $new_404s > 0 ) : ?>
                <h2><?php esc_html_e( '404s', 'linkpilot' ); ?></h2>
                <p>
                    <?php
                    /* translators: %d: count of new 404 paths */
                    echo esc_html( sprintf( _n( '%d new 404 path logged.', '%d new 404 paths logged.', $new_404s, 'linkpilot' ), $new_404s ) );
                    ?>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) ); ?>"><?php esc_html_e( 'Review', 'linkpilot' ); ?></a>
                </p>
            <?php endif; ?>

            <hr style="border: none; border-top: 1px solid #eee; margin: 24px 0;" />
            <p style="color: #999; font-size: 12px;">
                <?php
                /* translators: %s: settings URL */
                $message = __( 'Turn this digest off in <a href="%s">LinkPilot settings</a>.', 'linkpilot' );
                echo wp_kses(
                    sprintf( $message, esc_url( admin_url( 'edit.php?post_type=lp_link&page=lp-settings' ) ) ),
                    array( 'a' => array( 'href' => array() ) )
                );
                ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function prev_period_totals( $days ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lp_clicks';
        $since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 2 * $days ) . ' days' ) );
        $until = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s AND clicked_at < %s AND is_bot = 0",
            $since,
            $until
        ) );
    }

    private static function top_links( $days, $limit ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lp_clicks';
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT link_id, COUNT(*) AS clicks
             FROM {$table}
             WHERE clicked_at >= %s AND is_bot = 0
             GROUP BY link_id
             ORDER BY clicks DESC
             LIMIT %d",
            $since,
            (int) $limit
        ) );
    }

    private static function recent_404s( $days ) {
        if ( ! class_exists( 'LP_404_Log_DB' ) ) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lp_404_log';
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE first_seen >= %s",
            $since
        ) );
    }
}
