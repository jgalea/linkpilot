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
        add_action( 'admin_post_lp_scanner_export_csv', array( __CLASS__, 'handle_csv_export' ) );
    }

    public static function handle_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'lp_scanner_export_csv' );

        $rows = LP_Scanner_DB::get_broken();

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="linkpilot-broken-links-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'URL', 'Status', 'HTTP Code', 'Ref Count', 'Error', 'Last Checked', 'Final URL', 'Redirects' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                $r->url,
                $r->status,
                $r->http_code,
                $r->ref_count,
                $r->error,
                $r->checked_at,
                $r->final_url ?? '',
                $r->redirect_count ?? 0,
            ) );
        }
        fclose( $out );
        exit;
    }

    public static function filtered_rows( $filter_status = '', $filter_host = '', $search = '', $page = 1, $per_page = 50 ) {
        global $wpdb;
        $table  = LP_Scanner_DB::get_table_name();

        $where  = array();
        $params = array();

        if ( $filter_status === '' || $filter_status === 'broken_all' ) {
            $where[]  = 'status IN (%s, %s, %s)';
            $params[] = 'broken';
            $params[] = 'error';
            $params[] = 'server_error';
        } else {
            $where[]  = 'status = %s';
            $params[] = $filter_status;
        }
        if ( $filter_host !== '' ) {
            $where[]  = 'url LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filter_host ) . '%';
        }
        if ( $search !== '' ) {
            $where[]  = '(url LIKE %s OR error LIKE %s)';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $offset    = max( 0, ( $page - 1 ) * $per_page );

        $total_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total     = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) );

        $rows_sql    = "SELECT * FROM {$table} {$where_sql} ORDER BY ref_count DESC, url ASC LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, array( $per_page, $offset ) );
        $rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_params ) );

        return array( 'rows' => $rows, 'total' => $total );
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

        $filter_status = isset( $_GET['status'] )    ? sanitize_text_field( wp_unslash( $_GET['status'] ) )    : '';
        $filter_host   = isset( $_GET['host'] )      ? sanitize_text_field( wp_unslash( $_GET['host'] ) )      : '';
        $search        = isset( $_GET['s'] )         ? sanitize_text_field( wp_unslash( $_GET['s'] ) )         : '';
        $page          = isset( $_GET['paged'] )     ? max( 1, (int) $_GET['paged'] )                          : 1;
        $per_page      = 50;

        $filtered = self::filtered_rows( $filter_status, $filter_host, $search, $page, $per_page );
        $broken   = $filtered['rows'];
        $total    = $filtered['total'];
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
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

            <h2>
                <?php esc_html_e( 'Broken & unreachable URLs', 'linkpilot' ); ?>
                <span style="color:#787c82;font-weight:normal;">(<?php echo esc_html( number_format_i18n( $total ) ); ?>)</span>
            </h2>

            <form method="get" style="margin:12px 0;padding:10px;background:#fff;border:1px solid #ccd0d4;display:flex;gap:10px;flex-wrap:wrap;align-items:center;max-width:1300px;">
                <input type="hidden" name="post_type" value="lp_link" />
                <input type="hidden" name="page" value="lp-scanner" />
                <select name="status">
                    <option value=""           <?php selected( $filter_status, '' ); ?>><?php esc_html_e( 'All broken/error', 'linkpilot' ); ?></option>
                    <option value="broken"     <?php selected( $filter_status, 'broken' ); ?>><?php esc_html_e( 'Broken (4xx)', 'linkpilot' ); ?></option>
                    <option value="server_error" <?php selected( $filter_status, 'server_error' ); ?>><?php esc_html_e( 'Server error (5xx)', 'linkpilot' ); ?></option>
                    <option value="error"      <?php selected( $filter_status, 'error' ); ?>><?php esc_html_e( 'Unreachable', 'linkpilot' ); ?></option>
                    <option value="redirect"   <?php selected( $filter_status, 'redirect' ); ?>><?php esc_html_e( 'Redirects', 'linkpilot' ); ?></option>
                    <option value="healthy"    <?php selected( $filter_status, 'healthy' ); ?>><?php esc_html_e( 'Healthy', 'linkpilot' ); ?></option>
                    <option value="dismissed"  <?php selected( $filter_status, 'dismissed' ); ?>><?php esc_html_e( 'Dismissed', 'linkpilot' ); ?></option>
                </select>
                <input type="text" name="host" value="<?php echo esc_attr( $filter_host ); ?>" placeholder="<?php esc_attr_e( 'Filter by host (e.g. example.com)', 'linkpilot' ); ?>" style="width:240px;" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search URL or error', 'linkpilot' ); ?>" style="flex:1;min-width:200px;" />
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'linkpilot' ); ?></button>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lp_scanner_export_csv' ), 'lp_scanner_export_csv' ) ); ?>" class="button"><?php esc_html_e( 'Download CSV', 'linkpilot' ); ?></a>
            </form>

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

                <?php if ( $pages > 1 ) :
                    $base = add_query_arg( array( 'status' => $filter_status, 'host' => $filter_host, 's' => $search ) ); ?>
                    <div class="tablenav" style="margin-top:12px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                /* translators: %s: number of broken URLs */
                                echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'linkpilot' ), number_format_i18n( $total ) ) );
                                ?>
                            </span>
                            <?php
                            echo wp_kses_post( paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%', $base ),
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $pages,
                                'prev_text' => '‹',
                                'next_text' => '›',
                                'type'      => 'list',
                            ) ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
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
