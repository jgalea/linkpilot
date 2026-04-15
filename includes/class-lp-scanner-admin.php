<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_Scanner_Admin {

    public static function init() {
        if ( get_option( 'lp_scanner_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_filter( 'manage_posts_columns', array( __CLASS__, 'add_broken_column' ) );
        add_filter( 'manage_pages_columns', array( __CLASS__, 'add_broken_column' ) );
        add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_broken_column' ), 10, 2 );
        add_action( 'manage_pages_custom_column', array( __CLASS__, 'render_broken_column' ), 10, 2 );
    }

    public static function add_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Link Scanner', 'linkpilot' ),
            __( 'Link Scanner', 'linkpilot' ),
            'manage_options',
            'lp-scanner',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        $summary = LP_Scanner_DB::get_summary();
        $broken  = LP_Scanner_DB::get_broken();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Link Scanner', 'linkpilot' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Scans outbound links in your post content and reports any that are broken or unreachable. LinkPilot cloaked URLs are excluded — their destinations are checked separately by the link health monitor.', 'linkpilot' ); ?>
            </p>

            <div class="lp-dashboard-stats">
                <div class="lp-stat-card">
                    <div class="lp-stat-value" style="color:#46b450;"><?php echo esc_html( $summary['healthy'] ); ?></div>
                    <p class="lp-stat-label"><?php esc_html_e( 'Healthy', 'linkpilot' ); ?></p>
                </div>
                <div class="lp-stat-card">
                    <div class="lp-stat-value" style="color:#dc3232;"><?php echo esc_html( $summary['broken'] + $summary['error'] ); ?></div>
                    <p class="lp-stat-label"><?php esc_html_e( 'Broken / Error', 'linkpilot' ); ?></p>
                </div>
                <div class="lp-stat-card">
                    <div class="lp-stat-value" style="color:#ffb900;"><?php echo esc_html( $summary['server_error'] ); ?></div>
                    <p class="lp-stat-label"><?php esc_html_e( 'Server Error', 'linkpilot' ); ?></p>
                </div>
                <div class="lp-stat-card">
                    <div class="lp-stat-value"><?php echo esc_html( $summary['unchecked'] ); ?></div>
                    <p class="lp-stat-label"><?php esc_html_e( 'Unchecked', 'linkpilot' ); ?></p>
                </div>
            </div>

            <p style="margin:20px 0;">
                <button type="button" class="button button-primary" id="lp-scanner-start">
                    <?php esc_html_e( 'Scan all posts now', 'linkpilot' ); ?>
                </button>
                <button type="button" class="button" id="lp-scanner-recheck">
                    <?php esc_html_e( 'Re-check broken links', 'linkpilot' ); ?>
                </button>
                <span class="description" style="margin-left:12px;">
                    <?php esc_html_e( 'Background scan runs hourly, 20 posts and 20 URLs per tick.', 'linkpilot' ); ?>
                </span>
            </p>

            <div id="lp-scanner-scan-progress"></div>
            <div id="lp-scanner-check-progress"></div>

            <h2><?php esc_html_e( 'Broken & unreachable URLs', 'linkpilot' ); ?> <span style="color:#787c82;font-weight:normal;">(<?php echo count( $broken ); ?>)</span></h2>

            <?php if ( empty( $broken ) ) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:900px;">
                    <p><?php esc_html_e( 'No broken links found yet. Run "Scan all posts now" to start.', 'linkpilot' ); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1300px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'URL', 'linkpilot' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( 'Status', 'linkpilot' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Code', 'linkpilot' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Posts', 'linkpilot' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Last checked', 'linkpilot' ); ?></th>
                            <th style="width:240px;"><?php esc_html_e( 'Actions', 'linkpilot' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $broken as $row ) : ?>
                            <tr class="lp-broken-row" data-url="<?php echo esc_attr( $row->url ); ?>">
                                <td>
                                    <a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->url ); ?></a>
                                    <?php if ( $row->error ) : ?>
                                        <div style="color:#d63638;font-size:12px;margin-top:4px;"><?php echo esc_html( $row->error ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="lp-col-status"><?php echo esc_html( $row->status ); ?></td>
                                <td><?php echo esc_html( $row->http_code ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row->ref_count ); ?></td>
                                <td><?php echo esc_html( $row->checked_at ?: '—' ); ?></td>
                                <td>
                                    <button type="button" class="button button-small lp-rewrite"><?php esc_html_e( 'Rewrite', 'linkpilot' ); ?></button>
                                    <button type="button" class="button button-small lp-unlink"><?php esc_html_e( 'Unlink', 'linkpilot' ); ?></button>
                                    <button type="button" class="button button-small button-link-delete lp-dismiss"><?php esc_html_e( 'Dismiss', 'linkpilot' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    <strong><?php esc_html_e( 'Rewrite', 'linkpilot' ); ?>:</strong> <?php esc_html_e( 'Replace the URL across every post that uses it. Post revisions are kept.', 'linkpilot' ); ?>
                    <?php esc_html_e( ' · ', 'linkpilot' ); ?>
                    <strong><?php esc_html_e( 'Unlink', 'linkpilot' ); ?>:</strong> <?php esc_html_e( 'Remove the link wrapper, keep the visible text. Post revisions are kept.', 'linkpilot' ); ?>
                    <?php esc_html_e( ' · ', 'linkpilot' ); ?>
                    <strong><?php esc_html_e( 'Dismiss', 'linkpilot' ); ?>:</strong> <?php esc_html_e( 'Hide from the broken list; URL stays in your content untouched.', 'linkpilot' ); ?>
                </p>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            // Row actions
            document.querySelectorAll('.lp-broken-row').forEach(function (row) {
                var url = row.getAttribute('data-url');
                row.querySelector('.lp-rewrite').addEventListener('click', function () {
                    var newUrl = window.prompt('<?php echo esc_js( __( 'Replace this URL with:', 'linkpilot' ) ); ?>\n\n' + url, url);
                    if (!newUrl || newUrl === url) return;
                    this.disabled = true;
                    this.textContent = '<?php echo esc_js( __( '…', 'linkpilot' ) ); ?>';
                    LPJobRunner.postJSON('lp_scanner_rewrite', { old_url: url, new_url: newUrl }).then(function (r) {
                        if (r && r.success) {
                            row.style.opacity = '0.5';
                            row.querySelector('.lp-col-status').textContent = '<?php echo esc_js( __( 'rewritten', 'linkpilot' ) ); ?>';
                            alert('<?php echo esc_js( __( 'Updated', 'linkpilot' ) ); ?> ' + r.data.updated + ' <?php echo esc_js( __( 'post(s).', 'linkpilot' ) ); ?>');
                        } else {
                            alert('Error: ' + ((r && r.data && r.data.message) || 'unknown'));
                        }
                    });
                });
                row.querySelector('.lp-unlink').addEventListener('click', function () {
                    if (!window.confirm('<?php echo esc_js( __( 'Remove the link wrapper from this URL in every post? Link text stays; post revisions are kept.', 'linkpilot' ) ); ?>')) return;
                    this.disabled = true;
                    this.textContent = '<?php echo esc_js( __( '…', 'linkpilot' ) ); ?>';
                    LPJobRunner.postJSON('lp_scanner_unlink', { url: url }).then(function (r) {
                        if (r && r.success) {
                            row.style.opacity = '0.5';
                            row.querySelector('.lp-col-status').textContent = '<?php echo esc_js( __( 'unlinked', 'linkpilot' ) ); ?>';
                            alert('<?php echo esc_js( __( 'Unlinked in', 'linkpilot' ) ); ?> ' + r.data.updated + ' <?php echo esc_js( __( 'post(s).', 'linkpilot' ) ); ?>');
                        } else {
                            alert('Error: ' + ((r && r.data && r.data.message) || 'unknown'));
                        }
                    });
                });
                row.querySelector('.lp-dismiss').addEventListener('click', function () {
                    this.disabled = true;
                    LPJobRunner.postJSON('lp_scanner_dismiss', { url: url }).then(function (r) {
                        if (r && r.success) {
                            row.style.display = 'none';
                        }
                    });
                });
            });

            document.getElementById('lp-scanner-start').addEventListener('click', function () {
                this.disabled = true;
                this.textContent = '<?php echo esc_js( __( 'Scanning…', 'linkpilot' ) ); ?>';
                LPJobRunner.start({
                    action: 'lp_job_scanner_extract',
                    containerId: 'lp-scanner-scan-progress',
                    label: '<?php echo esc_js( __( 'Extracting URLs from posts', 'linkpilot' ) ); ?>',
                    onDone: function () {
                        var btn = document.getElementById('lp-scanner-start');
                        btn.textContent = '<?php echo esc_js( __( 'Done (reload for results)', 'linkpilot' ) ); ?>';
                        btn.disabled = false;
                        btn.addEventListener('click', function () { location.reload(); }, { once: true });
                    }
                });
            });
            document.getElementById('lp-scanner-recheck').addEventListener('click', function () {
                this.disabled = true;
                this.textContent = '<?php echo esc_js( __( 'Checking…', 'linkpilot' ) ); ?>';
                LPJobRunner.start({
                    action: 'lp_job_scanner_check',
                    containerId: 'lp-scanner-check-progress',
                    label: '<?php echo esc_js( __( 'Checking URL statuses', 'linkpilot' ) ); ?>',
                    onDone: function () {
                        var btn = document.getElementById('lp-scanner-recheck');
                        btn.textContent = '<?php echo esc_js( __( 'Done (reload for results)', 'linkpilot' ) ); ?>';
                        btn.disabled = false;
                        btn.addEventListener('click', function () { location.reload(); }, { once: true });
                    }
                });
            });
        })();
        </script>
        <?php
    }

    public static function add_broken_column( $columns ) {
        $columns['lp_broken'] = __( 'Broken', 'linkpilot' );
        return $columns;
    }

    public static function render_broken_column( $column, $post_id ) {
        if ( $column !== 'lp_broken' ) return;

        $count = LP_Scanner::count_broken_in_post( $post_id );
        if ( $count === 0 ) {
            $urls = get_post_meta( $post_id, LP_Scanner::META_POST_URLS, true );
            if ( ! is_array( $urls ) ) {
                echo '<span style="color:#999;" title="' . esc_attr__( 'Not scanned yet', 'linkpilot' ) . '">—</span>';
            } else {
                echo '<span style="color:#46b450;">✓</span>';
            }
            return;
        }

        echo '<strong style="color:#dc3232;">' . esc_html( $count ) . '</strong>';
    }
}
