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

        // CSV output is raw binary data; individual fields are escaped in csv_row().
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::csv_row( array( 'URL', 'Status', 'HTTP Code', 'Ref Count', 'Error', 'Last Checked', 'Final URL', 'Redirects' ) );
        foreach ( $rows as $r ) {
            echo self::csv_row( array(
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
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /** RFC 4180 CSV row encoder (no fopen needed). */
    private static function csv_row( array $fields ) {
        $out = array();
        foreach ( $fields as $f ) {
            $s = (string) $f;
            if ( strpbrk( $s, ",\"\r\n" ) !== false ) {
                $s = '"' . str_replace( '"', '""', $s ) . '"';
            }
            $out[] = $s;
        }
        return implode( ',', $out ) . "\r\n";
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

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql is built from hard-coded fragments and $table from class constant; all user input is bound via prepare placeholders below.
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params ) );

        $rows_params = array_merge( $params, array( $per_page, $offset ) );
        $rows        = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY ref_count DESC, url ASC LIMIT %d OFFSET %d", ...$rows_params ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Resolve Redirects', 'linkpilot' ),
            __( 'Resolve Redirects', 'linkpilot' ),
            'manage_options',
            'lp-scanner-redirects',
            array( __CLASS__, 'render_redirects_page' )
        );
    }

    /**
     * URL patterns that indicate the "final" destination is not actually the
     * canonical resource (login walls, cookie consent pages, signin flows).
     * Rewriting to these would replace a working link with a broken experience.
     *
     * @param string $url
     * @return bool True if the URL looks like a soft-block redirect we must NOT resolve.
     */
    private static function is_softblock_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }
        $patterns = array(
            '#/login/?\?next=#i',                            // facebook.com/login/?next=
            '#consent\.(?:youtube|google|facebook)\.com/#i', // cookie consent interstitials
            '#/v3/signin/#i',                                // Google signin flow
            '#accounts\.google\.com/ServiceLogin#i',         // older Google signin
            '#/signin\?(?:.*&)?continue=#i',                 // generic signin?continue=
            '#/auth/(?:login|signin)#i',                     // /auth/login, /auth/signin
            '#//www\.linkedin\.com/authwall#i',              // LinkedIn authwall
        );
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $url ) ) {
                return true;
            }
        }
        // Universal affiliate/tracking hosts — never rewrite these.
        $defaults = array(
            'amzn.to',
            'shareasale.com/r.cfm',
            'awin1.com',
            'imp.pxf.io',
            'kraken.pxf.io',
            'namecheap.pxf.io',
            'click.convertkit-mail.com',
            'click.convertkit-mail2.com',
            'el2.convertkit-mail.com',
            'el2.convertkit-mail2.com',
            'email.republic.co/wf/click',
            'track.youhodler.com/click',
            'i.refs.cc',
            'bit.ly',
            'tinyurl.com',
            't.co/',
        );

        // User-configurable extras from the setting textarea (one host/path per line).
        $user_extras = array();
        $raw = get_option( 'lp_resolve_excluded_hosts', '' );
        if ( ! empty( $raw ) ) {
            foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
                $line = trim( $line );
                if ( '' !== $line ) {
                    $user_extras[] = $line;
                }
            }
        }

        $cloaked_hosts = apply_filters( 'lp_scanner_cloaked_hosts', array_merge( $defaults, $user_extras ) );
        foreach ( $cloaked_hosts as $needle ) {
            if ( stripos( $url, $needle ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect a redirect that strips affiliate tracking parameters from the
     * original URL. Rewriting to the cleaner destination would lose attribution.
     *
     * We match on well-known affiliate/partner/referral query parameter names.
     * If the original had any of them and the final dropped them, block.
     *
     * @param string $original
     * @param string $final
     * @return bool
     */
    private static function is_affiliate_param_loss( $original, $final ) {
        if ( empty( $original ) || empty( $final ) ) {
            return false;
        }
        $o = wp_parse_url( $original );
        $f = wp_parse_url( $final );
        if ( ! is_array( $o ) || empty( $o['query'] ) ) {
            return false;
        }
        $oq = array();
        wp_parse_str( $o['query'], $oq );
        $fq = array();
        if ( is_array( $f ) && ! empty( $f['query'] ) ) {
            wp_parse_str( $f['query'], $fq );
        }
        // Known affiliate / partner / tracking param names across networks.
        $keys = apply_filters( 'lp_scanner_affiliate_param_keys', array(
            'aid',          // Booking.com, many
            'tag',          // Amazon Associates
            'affid', 'aff_id', 'affiliate_id', 'affiliate',
            'ref', 'refid', 'referrer', 'referral',
            'linkid', 'linkcode',
            'partner', 'partnerid', 'partner_id',
            'awc',          // Awin
            'clickid', 'irclickid', 'irgwc', 'ir_id', // Impact
            'mbsy',         // Ambassador
            'srsltid',      // Google Shopping affiliate
            'camp', 'campaign',
            'mpid',         // Mindbody partner
            'asgtbndr',     // Etsy
            'smile_ref',    // Smile.io referrals
            'smile_referral_code',
            'pub', 'pubid',
            'promocode', 'voucher',
            'subid', 'subid1', 'subid2',
            'sub1', 'sub2', 'sub3',
            'offer_id', 'offerid',
            'affsub', 'affsubid',
            'utm_ref',
        ) );
        foreach ( $keys as $k ) {
            if ( isset( $oq[ $k ] ) && '' !== $oq[ $k ] && ! isset( $fq[ $k ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect a redirect whose "final" URL differs from the original only by
     * an added locale segment or locale query parameter. These are geo-based
     * — the scanner runs from the server's country, so baking the result
     * into content sends every visitor to that one locale regardless of theirs.
     *
     * @param string $original
     * @param string $final
     * @return bool
     */
    private static function is_locale_redirect( $original, $final ) {
        if ( empty( $original ) || empty( $final ) ) {
            return false;
        }
        $o = wp_parse_url( $original );
        $f = wp_parse_url( $final );
        if ( ! is_array( $o ) || ! is_array( $f ) ) {
            return false;
        }
        // Must be the same host (after normalizing optional www).
        $oh = isset( $o['host'] ) ? preg_replace( '/^www\./i', '', strtolower( $o['host'] ) ) : '';
        $fh = isset( $f['host'] ) ? preg_replace( '/^www\./i', '', strtolower( $f['host'] ) ) : '';
        if ( $oh === '' || $fh === '' || $oh !== $fh ) {
            return false;
        }

        // Path: does final add a leading locale segment the original lacked?
        $op = isset( $o['path'] ) ? trim( $o['path'], '/' ) : '';
        $fp = isset( $f['path'] ) ? trim( $f['path'], '/' ) : '';
        $locale_rx = '@^[a-z]{2,3}(-[a-z]{2,4})?$@i';
        $first_f   = $fp !== '' ? explode( '/', $fp )[0] : '';
        $first_o   = $op !== '' ? explode( '/', $op )[0] : '';
        if ( $first_f !== '' && preg_match( $locale_rx, $first_f ) ) {
            if ( $first_o === '' || ! preg_match( $locale_rx, $first_o ) ) {
                return true;
            }
        }

        // Query: did final add gl=, locale=, country=, lang=, region=?
        if ( ! empty( $f['query'] ) ) {
            $fq = array();
            wp_parse_str( $f['query'], $fq );
            $oq = array();
            if ( ! empty( $o['query'] ) ) {
                wp_parse_str( $o['query'], $oq );
            }
            foreach ( array( 'gl', 'locale', 'country', 'lang', 'region', 'cc' ) as $k ) {
                if ( isset( $fq[ $k ] ) && ! isset( $oq[ $k ] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Human-readable reason a redirect is excluded from resolve.
     *
     * @param string $original
     * @param string $final
     * @return string
     */
    private static function blocked_reason( $original, $final ) {
        if ( self::is_affiliate_param_loss( $original, $final ) ) {
            return __( 'Affiliate params dropped', 'linkpilot' );
        }
        if ( self::is_locale_redirect( $original, $final ) ) {
            return __( 'Locale redirect (geo-based)', 'linkpilot' );
        }
        $url = $final ?: $original;
        if ( stripos( $url, '/login/' ) !== false && stripos( $url, 'next=' ) !== false ) {
            return __( 'Login wall', 'linkpilot' );
        }
        if ( stripos( $url, 'consent.' ) !== false ) {
            return __( 'Cookie consent', 'linkpilot' );
        }
        if ( stripos( $url, '/v3/signin/' ) !== false || stripos( $url, 'ServiceLogin' ) !== false ) {
            return __( 'Signin flow', 'linkpilot' );
        }
        if ( stripos( $url, 'authwall' ) !== false ) {
            return __( 'Authwall', 'linkpilot' );
        }
        foreach ( array( 'wpmayor.com/link/', 'amzn.to', 'pxf.io', 'shareasale', 'convertkit-mail' ) as $needle ) {
            if ( stripos( $original, $needle ) !== false || stripos( $url, $needle ) !== false ) {
                return __( 'Cloaked / affiliate link', 'linkpilot' );
            }
        }
        return __( 'Excluded', 'linkpilot' );
    }

    public static function render_redirects_page() {
        $all_redirects = LP_Scanner_DB::get_redirects();

        // Partition: resolvable vs soft-block/cloaked/locale (display only, cannot rewrite).
        $resolvable = array();
        $blocked    = array();
        foreach ( $all_redirects as $r ) {
            if (
                self::is_softblock_url( $r->final_url )
                || self::is_softblock_url( $r->url )
                || self::is_locale_redirect( $r->url, $r->final_url )
                || self::is_affiliate_param_loss( $r->url, $r->final_url )
            ) {
                $blocked[] = $r;
            } else {
                $resolvable[] = $r;
            }
        }

        // Pagination for the resolvable list.
        $per_page = 100;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $pages    = max( 1, (int) ceil( count( $resolvable ) / $per_page ) );
        $visible  = array_slice( $resolvable, ( $page - 1 ) * $per_page, $per_page );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Resolve Redirects', 'linkpilot' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'URLs in your posts that currently redirect to a different final destination. Click "Resolve" to rewrite the original URL to the final URL across every post that uses it — post revisions are kept automatically. This is useful for HTTPS migration leftovers, www/non-www canonical fixes, and platform URL changes.', 'linkpilot' ); ?>
            </p>
            <p class="description" style="color:#a00;">
                <strong><?php esc_html_e( 'Note:', 'linkpilot' ); ?></strong>
                <?php esc_html_e( 'This is not SEO "canonicalization" in the rel=canonical sense — it just resolves redirect chains in your content. Login walls, cookie-consent redirects, and your own cloaked affiliate-link domains are automatically excluded (see below) to avoid replacing working URLs with sign-in pages.', 'linkpilot' ); ?>
            </p>

            <?php if ( empty( $all_redirects ) ) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:900px;">
                    <p><?php esc_html_e( 'No redirects detected yet. Run the Link Scanner first from the Link Scanner page.', 'linkpilot' ); ?></p>
                </div>
                <?php return; endif; ?>

            <?php if ( ! empty( $resolvable ) ) : ?>
                <p style="margin:16px 0;">
                    <button type="button" class="button button-primary" id="lp-canonicalize-all">
                        <?php echo esc_html( sprintf(
                            /* translators: %d: number of redirects */
                            __( 'Resolve all %d redirects on this page', 'linkpilot' ),
                            count( $visible )
                        ) ); ?>
                    </button>
                    <span class="description" style="margin-left:12px;">
                        <?php esc_html_e( 'Processes one URL per second. Leave the page open while it runs.', 'linkpilot' ); ?>
                    </span>
                </p>

                <div id="lp-canonicalize-progress"></div>

                <table class="widefat striped" style="max-width:1300px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Original URL', 'linkpilot' ); ?></th>
                            <th style="width:30px;">→</th>
                            <th><?php esc_html_e( 'Final URL', 'linkpilot' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Hops', 'linkpilot' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Posts', 'linkpilot' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Action', 'linkpilot' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $visible as $r ) : ?>
                            <tr class="lp-redirect-row" data-original="<?php echo esc_attr( $r->url ); ?>" data-final="<?php echo esc_attr( $r->final_url ); ?>">
                                <td style="word-break:break-all;"><?php echo esc_html( $r->url ); ?></td>
                                <td style="text-align:center;color:#787c82;">→</td>
                                <td style="word-break:break-all;"><?php echo esc_html( $r->final_url ); ?></td>
                                <td><?php echo esc_html( $r->redirect_count ); ?></td>
                                <td><?php echo esc_html( $r->ref_count ); ?></td>
                                <td>
                                    <button type="button" class="button button-small lp-canonicalize-one"><?php esc_html_e( 'Resolve', 'linkpilot' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                    <div class="tablenav" style="margin-top:12px;">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post( paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ) ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $blocked ) ) : ?>
                <h2 style="margin-top:32px;"><?php esc_html_e( 'Not resolvable (excluded)', 'linkpilot' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These redirect to login walls, cookie-consent pages, or your own cloaked affiliate links. Rewriting them would break the user experience or your affiliate tracking, so they are listed for visibility but cannot be resolved automatically.', 'linkpilot' ); ?>
                </p>
                <table class="widefat striped" style="max-width:1300px; opacity:0.85;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Original URL', 'linkpilot' ); ?></th>
                            <th style="width:30px;">→</th>
                            <th><?php esc_html_e( 'Final URL', 'linkpilot' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Posts', 'linkpilot' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Why blocked', 'linkpilot' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_slice( $blocked, 0, 200 ) as $r ) : ?>
                            <tr>
                                <td style="word-break:break-all;"><?php echo esc_html( $r->url ); ?></td>
                                <td style="text-align:center;color:#787c82;">→</td>
                                <td style="word-break:break-all;"><?php echo esc_html( $r->final_url ); ?></td>
                                <td><?php echo esc_html( $r->ref_count ); ?></td>
                                <td><?php echo esc_html( self::blocked_reason( $r->url, $r->final_url ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            document.querySelectorAll('.lp-canonicalize-one').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = this.closest('.lp-redirect-row');
                    var original = row.getAttribute('data-original');
                    var final = row.getAttribute('data-final');
                    if (!window.confirm('<?php echo esc_js( __( 'Rewrite this URL across every post that uses it?', 'linkpilot' ) ); ?>\n\n' + original + '\n→\n' + final)) return;
                    this.disabled = true;
                    this.textContent = '…';
                    LPJobRunner.postJSON('lp_scanner_rewrite', { old_url: original, new_url: final }).then(function (r) {
                        if (r && r.success) {
                            row.style.opacity = '0.5';
                            btn.textContent = '<?php echo esc_js( __( 'Updated', 'linkpilot' ) ); ?> (' + r.data.updated + ')';
                        } else {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Retry', 'linkpilot' ) ); ?>';
                            alert('Error: ' + ((r && r.data && r.data.message) || 'unknown'));
                        }
                    });
                });
            });

            document.getElementById('lp-canonicalize-all').addEventListener('click', function () {
                if (!window.confirm('<?php echo esc_js( __( 'Rewrite ALL redirected URLs to their final destinations across every post on this site? Post revisions are kept. This may modify many posts.', 'linkpilot' ) ); ?>')) return;
                this.disabled = true;
                this.textContent = '<?php echo esc_js( __( 'Canonicalizing…', 'linkpilot' ) ); ?>';
                LPJobRunner.start({
                    action: 'lp_job_scanner_canonicalize',
                    containerId: 'lp-canonicalize-progress',
                    label: '<?php echo esc_js( __( 'Rewriting redirected URLs', 'linkpilot' ) ); ?>',
                    onDone: function () {
                        document.getElementById('lp-canonicalize-all').textContent = '<?php echo esc_js( __( 'Done (reload to see remaining)', 'linkpilot' ) ); ?>';
                        document.getElementById('lp-canonicalize-all').disabled = false;
                        document.getElementById('lp-canonicalize-all').addEventListener('click', function () { location.reload(); }, { once: true });
                    }
                });
            });
        })();
        </script>
        <?php
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
                    <div class="lp-stat-value" style="color:#787c82;"><?php echo esc_html( $summary['blocked'] ); ?></div>
                    <p class="lp-stat-label" title="<?php esc_attr_e( 'Sites that refused automated checks (e.g. Cloudflare 403). Visible to humans, not necessarily broken.', 'linkpilot' ); ?>"><?php esc_html_e( 'Blocked (refused)', 'linkpilot' ); ?></p>
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
                    <option value="blocked"    <?php selected( $filter_status, 'blocked' ); ?>><?php esc_html_e( 'Blocked (refused automated check)', 'linkpilot' ); ?></option>
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
