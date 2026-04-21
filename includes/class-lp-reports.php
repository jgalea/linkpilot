<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LinkPilot reports.
 *
 * Site-wide analytics built on the existing lp_clicks table:
 *   - Referrer report (where clicks are coming from)
 *   - CSV export (stream raw click rows)
 *   - Dashboard 30-day click chart (rendered as SVG sparkline)
 */
class LP_Reports {

    public static function init() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
            add_action( 'admin_post_lp_clicks_export', array( __CLASS__, 'handle_export' ) );
        }
    }

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Reports', 'linkpilot' ),
            __( 'Reports', 'linkpilot' ),
            'manage_options',
            'lp-reports',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        list( $from_date, $to_date ) = self::parse_range_from_request();
        $from_sql                    = $from_date . ' 00:00:00';
        $to_sql                      = gmdate( 'Y-m-d', strtotime( $to_date . ' UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';

        $referrers = LP_Clicks_DB::get_site_top_referrers_range( $from_sql, $to_sql, 100 );
        $daily     = LP_Clicks_DB::get_site_clicks_by_range( $from_sql, $to_sql );

        $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=lp_clicks_export' ), 'lp_clicks_export' );
        $presets    = self::preset_ranges();
        $base_url   = admin_url( 'edit.php?post_type=lp_link&page=lp-reports' );
        $min_date   = '2024-01-01'; // earliest sensible bound
        $max_date   = gmdate( 'Y-m-d' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'LinkPilot Reports', 'linkpilot' ); ?></h1>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export all clicks as CSV', 'linkpilot' ); ?></a>
            <hr class="wp-header-end" />

            <form method="get" class="lp-reports-range" style="margin: 16px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                <input type="hidden" name="post_type" value="lp_link" />
                <input type="hidden" name="page" value="lp-reports" />
                <label for="lp_from"><?php esc_html_e( 'From:', 'linkpilot' ); ?></label>
                <input type="date" id="lp_from" name="from" value="<?php echo esc_attr( $from_date ); ?>" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" required />
                <label for="lp_to"><?php esc_html_e( 'To:', 'linkpilot' ); ?></label>
                <input type="date" id="lp_to" name="to" value="<?php echo esc_attr( $to_date ); ?>" min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" required />
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'linkpilot' ); ?></button>
            </form>

            <div class="lp-reports-presets" style="margin: 0 0 20px; display: flex; flex-wrap: wrap; gap: 6px;">
                <?php foreach ( $presets as $preset ) : ?>
                    <?php
                    $url    = add_query_arg(
                        array(
                            'from' => $preset['from'],
                            'to'   => $preset['to'],
                        ),
                        $base_url
                    );
                    $active = ( $preset['from'] === $from_date && $preset['to'] === $to_date );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="button <?php echo $active ? 'button-primary' : 'button-secondary'; ?>">
                        <?php echo esc_html( $preset['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="description" style="margin: 0 0 16px;">
                <?php
                echo esc_html( sprintf(
                    /* translators: 1: start date, 2: end date */
                    __( 'Showing %1$s to %2$s (bots excluded, dates in UTC).', 'linkpilot' ),
                    $from_date,
                    $to_date
                ) );
                ?>
            </p>

            <div class="lp-reports-grid" style="display: grid; grid-template-columns: 1fr; gap: 24px; max-width: 1000px;">
                <div class="lp-reports-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Clicks per day', 'linkpilot' ); ?></h2>
                    <?php echo self::render_chart_range( $daily, $from_date, $to_date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from integer data. ?>
                </div>

                <div class="lp-reports-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px;">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Top referrers', 'linkpilot' ); ?></h2>
                    <?php if ( empty( $referrers ) ) : ?>
                        <p style="color: #757575;"><?php esc_html_e( 'No referrer data yet for this window.', 'linkpilot' ); ?></p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Referrer', 'linkpilot' ); ?></th>
                                    <th style="width: 120px;"><?php esc_html_e( 'Clicks', 'linkpilot' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $referrers as $r ) : ?>
                                    <tr>
                                        <td>
                                            <?php if ( preg_match( '#^https?://#i', $r->referrer ) ) : ?>
                                                <a href="<?php echo esc_url( $r->referrer ); ?>" target="_blank" rel="noopener noreferrer nofollow">
                                                    <?php echo esc_html( $r->referrer ); ?>
                                                </a>
                                            <?php else : ?>
                                                <code><?php echo esc_html( $r->referrer ); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( number_format_i18n( (int) $r->clicks ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Parse from/to (or legacy days=) from the current request, with defaults.
     *
     * @return array [ $from_date, $to_date ] — 'Y-m-d' UTC.
     */
    private static function parse_range_from_request() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only GET params.
        $from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
        $to_raw   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

        $is_date = static function ( $s ) {
            return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s );
        };

        if ( $is_date( $from_raw ) && $is_date( $to_raw ) ) {
            $from_date = $from_raw;
            $to_date   = $to_raw;
            if ( $from_date > $to_date ) {
                $tmp       = $from_date;
                $from_date = $to_date;
                $to_date   = $tmp;
            }
        } else {
            // Legacy days= param (rolling window) or default 30.
            $days      = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
            $days      = max( 1, min( 365, $days ) );
            $from_date = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
            $to_date   = gmdate( 'Y-m-d' );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $today = gmdate( 'Y-m-d' );
        if ( $to_date > $today ) {
            $to_date = $today;
        }
        return array( $from_date, $to_date );
    }

    /**
     * Canonical quick-range presets shown as buttons.
     *
     * @return array<array{label:string, from:string, to:string}>
     */
    private static function preset_ranges() {
        $today = gmdate( 'Y-m-d' );
        $ago   = static function ( $days ) {
            return gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
        };
        $first_of_month = gmdate( 'Y-m-01' );
        $last_month_end = gmdate( 'Y-m-d', strtotime( $first_of_month . ' -1 day UTC' ) );
        $last_month_start = gmdate( 'Y-m-01', strtotime( $last_month_end . ' UTC' ) );
        $year_start     = gmdate( 'Y-01-01' );

        return array(
            array(
                'label' => __( 'Last 7 days', 'linkpilot' ),
                'from'  => $ago( 7 ),
                'to'    => $today,
            ),
            array(
                'label' => __( 'Last 30 days', 'linkpilot' ),
                'from'  => $ago( 30 ),
                'to'    => $today,
            ),
            array(
                'label' => __( 'Last 90 days', 'linkpilot' ),
                'from'  => $ago( 90 ),
                'to'    => $today,
            ),
            array(
                'label' => __( 'Last 365 days', 'linkpilot' ),
                'from'  => $ago( 365 ),
                'to'    => $today,
            ),
            array(
                'label' => __( 'This month', 'linkpilot' ),
                'from'  => $first_of_month,
                'to'    => $today,
            ),
            array(
                'label' => __( 'Last month', 'linkpilot' ),
                'from'  => $last_month_start,
                'to'    => $last_month_end,
            ),
            array(
                'label' => __( 'Year to date', 'linkpilot' ),
                'from'  => $year_start,
                'to'    => $today,
            ),
        );
    }

    /**
     * Render a lightweight SVG sparkline for daily clicks (rolling window).
     *
     * @param array $daily Array of {click_date, clicks} rows, ascending.
     * @param int   $days  Window size.
     * @return string SVG HTML.
     */
    public static function render_chart( $daily, $days ) {
        $days      = max( 1, (int) $days );
        $from_date = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
        $to_date   = gmdate( 'Y-m-d' );
        return self::render_chart_range( $daily, $from_date, $to_date );
    }

    /**
     * Render an SVG sparkline for an arbitrary date range.
     *
     * @param array  $daily     Array of {click_date, clicks} rows, ascending.
     * @param string $from_date 'Y-m-d' inclusive.
     * @param string $to_date   'Y-m-d' inclusive.
     * @return string SVG HTML.
     */
    public static function render_chart_range( $daily, $from_date, $to_date ) {
        $map = array();
        foreach ( $daily as $row ) {
            $map[ $row->click_date ] = (int) $row->clicks;
        }
        $cursor = strtotime( $from_date . ' 00:00:00 UTC' );
        $end    = strtotime( $to_date . ' 00:00:00 UTC' );
        if ( ! $cursor || ! $end || $cursor > $end ) {
            return '<p style="color:#757575;">' . esc_html__( 'Invalid date range.', 'linkpilot' ) . '</p>';
        }

        $series = array();
        while ( $cursor <= $end ) {
            $date     = gmdate( 'Y-m-d', $cursor );
            $series[] = array(
                'date'   => $date,
                'clicks' => isset( $map[ $date ] ) ? $map[ $date ] : 0,
            );
            $cursor += DAY_IN_SECONDS;
        }

        $w        = 960;
        $h        = 180;
        $pad_x    = 40;
        $pad_y    = 20;
        $inner_w  = $w - 2 * $pad_x;
        $inner_h  = $h - 2 * $pad_y;
        $max      = 0;
        foreach ( $series as $p ) {
            if ( $p['clicks'] > $max ) {
                $max = $p['clicks'];
            }
        }
        $max = max( 1, $max );
        $n   = max( 1, count( $series ) - 1 );

        $points = array();
        $bars   = '';
        foreach ( $series as $i => $p ) {
            $x = $pad_x + ( $inner_w * $i / $n );
            $y = $pad_y + $inner_h - ( $inner_h * $p['clicks'] / $max );
            $points[] = sprintf( '%.2f,%.2f', $x, $y );
            $bar_w = max( 2, $inner_w / $n - 1 );
            $bar_x = $x - $bar_w / 2;
            $bar_h = max( 0, $inner_h * $p['clicks'] / $max );
            $bar_y = $pad_y + $inner_h - $bar_h;
            $bars .= sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="#2271b1" opacity="0.15"><title>%s: %s</title></rect>',
                $bar_x, $bar_y, $bar_w, $bar_h,
                esc_attr( $p['date'] ),
                esc_attr( number_format_i18n( $p['clicks'] ) )
            );
        }

        $polyline = '<polyline fill="none" stroke="#2271b1" stroke-width="2" points="' . esc_attr( implode( ' ', $points ) ) . '" />';
        $label_max = '<text x="' . (int) ( $pad_x - 8 ) . '" y="' . (int) ( $pad_y + 4 ) . '" font-size="11" fill="#50575e" text-anchor="end">' . esc_html( number_format_i18n( $max ) ) . '</text>';
        $label_0   = '<text x="' . (int) ( $pad_x - 8 ) . '" y="' . (int) ( $pad_y + $inner_h + 4 ) . '" font-size="11" fill="#50575e" text-anchor="end">0</text>';
        $first_lbl = '<text x="' . (int) $pad_x . '" y="' . (int) ( $h - 4 ) . '" font-size="11" fill="#50575e">' . esc_html( $series[0]['date'] ) . '</text>';
        $last_lbl  = '<text x="' . (int) ( $w - $pad_x ) . '" y="' . (int) ( $h - 4 ) . '" font-size="11" fill="#50575e" text-anchor="end">' . esc_html( end( $series )['date'] ) . '</text>';

        return sprintf(
            '<svg viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" width="100%%" style="max-width:100%%;height:auto;" role="img" aria-label="%s">%s%s%s%s%s%s</svg>',
            $w, $h,
            esc_attr__( 'Daily clicks chart', 'linkpilot' ),
            $bars, $polyline, $label_max, $label_0, $first_lbl, $last_lbl
        );
    }

    /**
     * Export entire clicks table as CSV. Streams to avoid memory pressure.
     */
    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_clicks_export' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=linkpilot-clicks-' . gmdate( 'Y-m-d' ) . '.csv' );

        $header = self::csv_row( array( 'id', 'link_id', 'link_slug', 'clicked_at', 'referrer', 'country_code', 'is_bot' ) );
        echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-escaped by csv_row.

        $offset = 0;
        $batch  = 1000;
        // Cache slug lookups so we don't hammer get_post() per row.
        $slug_cache = array();

        while ( true ) {
            $rows = LP_Clicks_DB::get_rows_batch( $offset, $batch );
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $row ) {
                $lid = (int) $row['link_id'];
                if ( ! isset( $slug_cache[ $lid ] ) ) {
                    $post                  = get_post( $lid );
                    $slug_cache[ $lid ]    = $post ? $post->post_name : '';
                }
                $line = self::csv_row( array(
                    $row['id'],
                    $row['link_id'],
                    $slug_cache[ $lid ],
                    $row['clicked_at'],
                    $row['referrer'],
                    $row['country_code'],
                    $row['is_bot'],
                ) );
                echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-escaped by csv_row.
            }
            if ( count( $rows ) < $batch ) {
                break;
            }
            $offset += $batch;
        }
        exit;
    }

    private static function csv_row( $fields ) {
        $out = array();
        foreach ( $fields as $field ) {
            $str = (string) $field;
            if ( preg_match( '/[",\r\n]/', $str ) ) {
                $str = '"' . str_replace( '"', '""', $str ) . '"';
            }
            $out[] = $str;
        }
        return implode( ',', $out ) . "\n";
    }
}
