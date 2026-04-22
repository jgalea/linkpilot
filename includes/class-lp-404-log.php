<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 404 logger.
 *
 * When a frontend request hits 404, logs the path + referrer to lp_404_log.
 * Admin UI lists recent 404s with a one-click "Create redirect" button that
 * inserts a row into the lp_redirects table pointing the broken path at a
 * user-specified destination.
 */
class LP_404_Log {

    const CRON_HOOK      = 'lp_404_log_purge';
    const RETENTION_DAYS = 90;

    public static function init() {
        LP_404_Log_DB::maybe_upgrade();

        add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 20 );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_purge' ) );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
            add_action( 'admin_post_lp_404_redirect', array( __CLASS__, 'handle_create_redirect' ) );
            add_action( 'admin_post_lp_404_delete', array( __CLASS__, 'handle_delete' ) );
            add_action( 'admin_post_lp_404_clear', array( __CLASS__, 'handle_clear' ) );
        }
    }

    /**
     * Called on plugin deactivation to unschedule the cron job.
     */
    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Daily cron: purge non-redirected entries older than the retention window.
     */
    public static function cron_purge() {
        LP_404_Log_DB::purge_older_than( self::RETENTION_DAYS );
    }

    /**
     * Admin action: clear the entire log.
     */
    public static function handle_clear() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_404_clear' );
        LP_404_Log_DB::truncate();
        wp_safe_redirect( add_query_arg( 'cleared', '1', admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) ) );
        exit;
    }

    /**
     * Log the current request if it's a 404 on the frontend.
     */
    public static function maybe_log() {
        if ( ! is_404() || is_admin() ) {
            return;
        }
        if ( get_option( 'lp_404_log_enabled', 'yes' ) !== 'yes' ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $referer     = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
        if ( empty( $request_uri ) ) {
            return;
        }

        // Ignore obvious bot-probe noise.
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( class_exists( 'LP_Bot_Detector' ) && LP_Bot_Detector::is_bot( $ua ) ) {
            return;
        }

        // Normalize path.
        $parsed = wp_parse_url( $request_uri );
        $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        if ( empty( $path ) ) {
            return;
        }

        // Keep the query string separately? For simplicity we log path only —
        // most 404 redirect use cases are path-based, not query-string-based.
        LP_404_Log_DB::record( $path, $referer );
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( '404 Log', 'linkpilot' ),
            __( '404 Log', 'linkpilot' ),
            'manage_options',
            'lp-404-log',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination read.
        $page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $data    = LP_404_Log_DB::paginate( 50, $page );
        $rows    = $data['rows'];
        $total   = $data['total'];
        $pages   = (int) ceil( $total / 50 );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( '404 Log', 'linkpilot' ); ?></h1>
            <?php if ( $total > 0 ) :
                $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=lp_404_clear' ), 'lp_404_clear' );
                ?>
                <a href="<?php echo esc_url( $clear_url ); ?>" class="page-title-action"
                    onclick="return confirm('<?php echo esc_js( __( 'Delete all 404 log entries?', 'linkpilot' ) ); ?>');">
                    <?php esc_html_e( 'Clear log', 'linkpilot' ); ?>
                </a>
            <?php endif; ?>
            <hr class="wp-header-end" />
            <p>
                <?php
                /* translators: %s: number of 404 entries */
                echo esc_html( sprintf( _n( '%s unique 404 path logged.', '%s unique 404 paths logged.', $total, 'linkpilot' ), number_format_i18n( $total ) ) );
                ?>
                <?php
                /* translators: %d: retention window in days */
                echo ' ' . esc_html( sprintf( __( 'Entries older than %d days without a redirect are auto-purged daily.', 'linkpilot' ), self::RETENTION_DAYS ) );
                ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Path', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Hits', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Last seen', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Referer', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'linkpilot' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No 404s logged yet. Everything on your site is findable, or logging is disabled.', 'linkpilot' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) :
                            $delete_url = wp_nonce_url(
                                admin_url( 'admin-post.php?action=lp_404_delete&id=' . (int) $row->id ),
                                'lp_404_delete_' . (int) $row->id
                            );
                            ?>
                            <tr>
                                <td><code><?php echo esc_html( $row->path ); ?></code></td>
                                <td><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
                                <td><?php echo esc_html( $row->last_seen ); ?></td>
                                <td>
                                    <?php if ( ! empty( $row->referer ) ) : ?>
                                        <code><?php echo esc_html( wp_trim_words( $row->referer, 8, '…' ) ); ?></code>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( (int) $row->redirected === 1 ) : ?>
                                        <span style="color: #46b450;">✓ <?php esc_html_e( 'Redirected', 'linkpilot' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #a00;">404</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( (int) $row->redirected === 0 ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <?php wp_nonce_field( 'lp_404_redirect_' . (int) $row->id ); ?>
                                            <input type="hidden" name="action" value="lp_404_redirect" />
                                            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                                            <input type="text" name="destination" placeholder="/new-path/" required style="width: 160px;" />
                                            <select name="type">
                                                <option value="301">301</option>
                                                <option value="302">302</option>
                                                <option value="307">307</option>
                                            </select>
                                            <button type="submit" class="button button-small"><?php esc_html_e( 'Create redirect', 'linkpilot' ); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button-link-delete" style="margin-left:8px;"
                                        onclick="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'linkpilot' ) ); ?>');">
                                        <?php esc_html_e( 'Delete', 'linkpilot' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav">
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
        </div>
        <?php
    }

    /**
     * One-click: create a redirect from a 404 entry.
     */
    public static function handle_create_redirect() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        check_admin_referer( 'lp_404_redirect_' . $id );

        $row = LP_404_Log_DB::get_one( $id );
        if ( ! $row ) {
            wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) );
            exit;
        }

        $destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';
        $type        = isset( $_POST['type'] ) ? (int) $_POST['type'] : 301;
        if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
            $type = 301;
        }

        if ( '' === $destination ) {
            wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) );
            exit;
        }

        LP_Redirects_DB::insert( array(
            'source_path' => $row->path,
            'destination' => $destination,
            'match_type'  => 'exact',
            'type'        => $type,
            'created_at'  => current_time( 'mysql', true ),
        ) );

        LP_404_Log_DB::mark_redirected( $id, true );

        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'lp_404_delete_' . $id );
        if ( $id > 0 ) {
            LP_404_Log_DB::delete( $id );
        }
        wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-404-log' ) );
        exit;
    }
}
