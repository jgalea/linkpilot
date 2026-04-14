<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$table = LP_Clicks_DB::get_table_name();

$total_links  = wp_count_posts( 'lp_link' )->publish;
$total_clicks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0" );
$clicks_30d   = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0 AND clicked_at >= %s",
    gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
) );
$clicks_7d    = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0 AND clicked_at >= %s",
    gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
) );

$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
$top_links = $wpdb->get_results( $wpdb->prepare(
    "SELECT c.link_id, p.post_title, COUNT(*) as clicks
     FROM {$table} c
     JOIN {$wpdb->posts} p ON c.link_id = p.ID
     WHERE c.is_bot = 0 AND c.clicked_at >= %s
     GROUP BY c.link_id
     ORDER BY clicks DESC
     LIMIT 10",
    $since
) );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'LinkPilot Dashboard', 'linkpilot' ); ?></h1>

    <div class="lp-dashboard-stats">
        <div class="lp-stat-card">
            <div class="lp-stat-icon"><span class="dashicons dashicons-admin-links"></span></div>
            <div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $total_links ) ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Total Links', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
            <div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $total_clicks ) ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Total Clicks', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            <div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $clicks_30d ) ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Clicks (30 days)', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-icon"><span class="dashicons dashicons-clock"></span></div>
            <div class="lp-stat-value"><?php echo esc_html( number_format_i18n( $clicks_7d ) ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Clicks (7 days)', 'linkpilot' ); ?></p>
        </div>
    </div>

    <?php
    $health = LP_Link_Health::get_summary();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only query param, not processed.
    $health_checked = isset( $_GET['lp_health_checked'] ) ? absint( wp_unslash( $_GET['lp_health_checked'] ) ) : 0;
    ?>

    <?php if ( $health_checked ) : ?>
        <div class="notice notice-success"><p>
            <?php
            echo esc_html( sprintf(
                /* translators: %d: number of links checked */
                _n( 'Health check complete: %d link checked.', 'Health check complete: %d links checked.', $health_checked, 'linkpilot' ),
                absint( $health_checked )
            ) );
            ?>
        </p></div>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Link Health', 'linkpilot' ); ?></h2>
    <div class="lp-dashboard-stats">
        <div class="lp-stat-card">
            <div class="lp-stat-value" style="color: <?php echo esc_attr( LP_Link_Health::get_status_color( 'healthy' ) ); ?>;"><?php echo esc_html( $health['healthy'] ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Healthy', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-value" style="color: <?php echo esc_attr( LP_Link_Health::get_status_color( 'broken' ) ); ?>;"><?php echo esc_html( $health['broken'] + $health['error'] ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Broken / Error', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-value" style="color: <?php echo esc_attr( LP_Link_Health::get_status_color( 'server_error' ) ); ?>;"><?php echo esc_html( $health['server_error'] ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Server Error', 'linkpilot' ); ?></p>
        </div>
        <div class="lp-stat-card">
            <div class="lp-stat-value"><?php echo esc_html( $health['unchecked'] ); ?></div>
            <p class="lp-stat-label"><?php esc_html_e( 'Unchecked', 'linkpilot' ); ?></p>
        </div>
    </div>
    <p style="margin-bottom: 20px;">
        <button type="button" class="button button-secondary" id="lp-check-health-now">
            <?php esc_html_e( 'Check All Links Now', 'linkpilot' ); ?>
        </button>
        <span class="description" style="margin-left: 10px;"><?php esc_html_e( 'Background checks run hourly and stagger across the week.', 'linkpilot' ); ?></span>
    </p>
    <div id="lp-health-progress"></div>

    <script>
    (function () {
        var btn = document.getElementById('lp-check-health-now');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = '<?php echo esc_js( __( 'Checking…', 'linkpilot' ) ); ?>';
            LPJobRunner.start({
                action: 'lp_job_health',
                containerId: 'lp-health-progress',
                label: '<?php echo esc_js( __( 'Checking link health', 'linkpilot' ) ); ?>',
                onDone: function () {
                    btn.textContent = '<?php echo esc_js( __( 'Reload to see updated counts', 'linkpilot' ) ); ?>';
                    btn.disabled = false;
                    btn.addEventListener('click', function () { location.reload(); }, { once: true });
                }
            });
        });
    })();
    </script>

    <?php if ( $top_links ) : ?>
    <h2><?php esc_html_e( 'Top Links (Last 30 Days)', 'linkpilot' ); ?></h2>
    <table class="widefat striped lp-top-links">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Link', 'linkpilot' ); ?></th>
                <th class="lp-col-clicks"><?php esc_html_e( 'Clicks', 'linkpilot' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $top_links as $row ) : ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $row->link_id ) ); ?>">
                        <?php echo esc_html( $row->post_title ); ?>
                    </a>
                </td>
                <td class="lp-col-clicks"><span class="lp-click-count"><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <div class="lp-empty-state">
        <span class="dashicons dashicons-chart-line"></span>
        <p><?php esc_html_e( 'No click data yet. Clicks will appear here once your links start getting traffic.', 'linkpilot' ); ?></p>
    </div>
    <?php endif; ?>
</div>
