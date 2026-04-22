<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress Dashboard widget.
 *
 * Adds a "LinkPilot" panel to wp-admin/ showing 30-day click total,
 * site-wide sparkline, and top 5 links.
 */
class LP_Dashboard_Widget {

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'lp_dashboard_widget',
            __( 'LinkPilot — Last 30 days', 'linkpilot' ),
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        $daily = LP_Clicks_DB::get_site_clicks_by_day( 30 );
        $total = 0;
        foreach ( $daily as $row ) {
            $total += (int) $row->clicks;
        }

        $top_links = self::top_links( 5, 30 );
        ?>
        <p style="margin-top: 0;">
            <strong style="font-size: 22px;"><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
            <span style="color: #646970;"><?php esc_html_e( 'clicks in the last 30 days', 'linkpilot' ); ?></span>
        </p>

        <?php
        if ( class_exists( 'LP_Reports' ) ) {
            echo LP_Reports::render_chart( $daily, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from integers.
        }

        if ( ! empty( $top_links ) ) : ?>
            <h3 style="margin-top: 16px;"><?php esc_html_e( 'Top links', 'linkpilot' ); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Link', 'linkpilot' ); ?></th>
                        <th style="width: 80px; text-align: right;"><?php esc_html_e( 'Clicks', 'linkpilot' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_links as $row ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( (int) $row->link_id ) ); ?>">
                                    <?php echo esc_html( get_the_title( (int) $row->link_id ) ?: '#' . (int) $row->link_id ); ?>
                                </a>
                            </td>
                            <td style="text-align: right;"><?php echo esc_html( number_format_i18n( (int) $row->clicks ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-bottom: 0;">
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lp_link&page=lp-reports' ) ); ?>">
                <?php esc_html_e( 'View full reports', 'linkpilot' ); ?> &rarr;
            </a>
        </p>
        <?php
    }

    /**
     * Top N links by click count over the window.
     *
     * @param int $limit
     * @param int $days
     * @return array<object>
     */
    private static function top_links( $limit = 5, $days = 30 ) {
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
}
