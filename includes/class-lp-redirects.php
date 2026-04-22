<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Site-level redirects engine + admin UI.
 *
 * Handles arbitrary old-URL -> new-URL redirects (not cloaked LinkPilot
 * links — those run earlier in the request via LP_Redirect). Checks each
 * non-cloaked request against the lp_redirects table and redirects if a
 * row matches.
 */
class LP_Redirects {

    public static function init() {
        LP_Redirects_DB::maybe_upgrade();

        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 5 );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
            add_action( 'admin_post_lp_redirect_save', array( __CLASS__, 'handle_save' ) );
            add_action( 'admin_post_lp_redirect_delete', array( __CLASS__, 'handle_delete' ) );
        }
    }

    /**
     * Check the current request against site redirects.
     */
    public static function maybe_redirect() {
        if ( is_admin() ) {
            return;
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( empty( $request_uri ) ) {
            return;
        }

        $parsed = wp_parse_url( $request_uri );
        $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        $match = LP_Redirects_DB::find_match( $path );
        if ( ! $match ) {
            return;
        }

        // Resolve destination: may be a full URL or a path relative to site.
        $destination = self::resolve_destination( $match, $path );
        if ( empty( $destination ) ) {
            return;
        }

        LP_Redirects_DB::record_hit( $match->id );

        $status = in_array( (int) $match->type, array( 301, 302, 307 ), true ) ? (int) $match->type : 301;
        wp_safe_redirect( $destination, $status );
        exit;
    }

    /**
     * Resolve the destination URL. Supports:
     *   - full URLs (https?://...)
     *   - site-relative paths ("/foo/bar")
     *   - regex back-references via preg_replace when match_type=regex
     *
     * @param object $row  Row from lp_redirects table.
     * @param string $path Incoming request path.
     * @return string
     */
    private static function resolve_destination( $row, $path ) {
        $dest = (string) $row->destination;

        if ( 'regex' === $row->match_type ) {
            $pattern = '@' . str_replace( '@', '\\@', $row->source_path ) . '@i';
            $result  = @preg_replace( $pattern, $dest, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ( null !== $result ) {
                $dest = $result;
            }
        }

        if ( preg_match( '#^https?://#i', $dest ) ) {
            return $dest;
        }
        return home_url( $dest );
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Redirects', 'linkpilot' ),
            __( 'Redirects', 'linkpilot' ),
            'manage_options',
            'lp-redirects',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $rows = LP_Redirects_DB::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Site Redirects', 'linkpilot' ); ?></h1>
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only read.
            $err = isset( $_GET['lp_err'] ) ? sanitize_key( wp_unslash( $_GET['lp_err'] ) ) : '';
            if ( 'empty' === $err ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Source path and destination are required.', 'linkpilot' ); ?></p></div>
            <?php elseif ( 'regex' === $err ) :
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
                $bad = isset( $_GET['lp_err_pattern'] ) ? sanitize_text_field( wp_unslash( $_GET['lp_err_pattern'] ) ) : '';
                ?>
                <div class="notice notice-error"><p>
                    <?php esc_html_e( 'Invalid regex pattern — redirect not saved. Pattern:', 'linkpilot' ); ?>
                    <code><?php echo esc_html( $bad ); ?></code>
                </p></div>
            <?php endif; ?>
            <p><?php esc_html_e( 'Redirect arbitrary URLs on your site to new destinations. Useful after URL changes, site restructures, or retiring old content. Cloaked LinkPilot links are handled separately — add them under Links instead.', 'linkpilot' ); ?></p>

            <h2><?php esc_html_e( 'Add redirect', 'linkpilot' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lp-redirect-form">
                <?php wp_nonce_field( 'lp_redirect_save' ); ?>
                <input type="hidden" name="action" value="lp_redirect_save" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="lp_redirect_source"><?php esc_html_e( 'Source path', 'linkpilot' ); ?></label></th>
                        <td>
                            <input type="text" id="lp_redirect_source" name="source_path" class="regular-text" placeholder="/old-url-or-pattern" required />
                            <p class="description"><?php esc_html_e( 'Leading slash. Exact: "/old-post/". Prefix: "/blog/". Regex: "^/blog/(.+)$".', 'linkpilot' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lp_redirect_dest"><?php esc_html_e( 'Destination', 'linkpilot' ); ?></label></th>
                        <td>
                            <input type="text" id="lp_redirect_dest" name="destination" class="regular-text" placeholder="/new-path/ or https://example.com/" required />
                            <p class="description"><?php esc_html_e( 'Absolute URL or site-relative path. Regex mode supports $1, $2 back-references.', 'linkpilot' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lp_redirect_match"><?php esc_html_e( 'Match type', 'linkpilot' ); ?></label></th>
                        <td>
                            <select id="lp_redirect_match" name="match_type">
                                <option value="exact">exact</option>
                                <option value="prefix">prefix</option>
                                <option value="regex">regex</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="lp_redirect_type"><?php esc_html_e( 'HTTP status', 'linkpilot' ); ?></label></th>
                        <td>
                            <select id="lp_redirect_type" name="type">
                                <option value="301">301 (Permanent)</option>
                                <option value="302">302 (Found)</option>
                                <option value="307">307 (Temporary)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Add redirect', 'linkpilot' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Existing redirects', 'linkpilot' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Source', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Destination', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Match', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Hits', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Last hit', 'linkpilot' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'linkpilot' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No redirects yet.', 'linkpilot' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) :
                            $delete_url = wp_nonce_url(
                                admin_url( 'admin-post.php?action=lp_redirect_delete&id=' . (int) $row->id ),
                                'lp_redirect_delete_' . (int) $row->id
                            );
                            ?>
                            <tr>
                                <td><code><?php echo esc_html( $row->source_path ); ?></code></td>
                                <td><code><?php echo esc_html( $row->destination ); ?></code></td>
                                <td><?php echo esc_html( $row->match_type ); ?></td>
                                <td><?php echo esc_html( (int) $row->type ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
                                <td><?php echo esc_html( $row->last_hit ?: '—' ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button-link-delete"
                                        onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'linkpilot' ) ); ?>');">
                                        <?php esc_html_e( 'Delete', 'linkpilot' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_redirect_save' );

        $source      = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
        $destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';
        $match       = isset( $_POST['match_type'] ) ? sanitize_key( wp_unslash( $_POST['match_type'] ) ) : 'exact';
        $type        = isset( $_POST['type'] ) ? (int) $_POST['type'] : 301;

        if ( ! in_array( $match, array( 'exact', 'prefix', 'regex' ), true ) ) {
            $match = 'exact';
        }
        if ( ! in_array( $type, array( 301, 302, 307 ), true ) ) {
            $type = 301;
        }
        if ( 'regex' !== $match && '/' !== substr( $source, 0, 1 ) ) {
            $source = '/' . ltrim( $source, '/' );
        }

        if ( '' === $source || '' === $destination ) {
            wp_safe_redirect( add_query_arg( 'lp_err', 'empty', admin_url( 'edit.php?post_type=lp_link&page=lp-redirects' ) ) );
            exit;
        }

        // Validate regex patterns before saving.
        if ( 'regex' === $match ) {
            $pattern = '@' . str_replace( '@', '\\@', $source ) . '@i';
            set_error_handler( function() {} ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- capture preg warnings.
            $valid = @preg_match( $pattern, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- intentional, we test validity.
            restore_error_handler();
            if ( false === $valid ) {
                wp_safe_redirect( add_query_arg(
                    array( 'lp_err' => 'regex', 'lp_err_pattern' => rawurlencode( $source ) ),
                    admin_url( 'edit.php?post_type=lp_link&page=lp-redirects' )
                ) );
                exit;
            }
        }

        LP_Redirects_DB::insert( array(
            'source_path' => $source,
            'destination' => $destination,
            'match_type'  => $match,
            'type'        => $type,
            'created_at'  => current_time( 'mysql', true ),
        ) );

        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'edit.php?post_type=lp_link&page=lp-redirects' ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'lp_redirect_delete_' . $id );
        if ( $id > 0 ) {
            LP_Redirects_DB::delete( $id );
        }
        wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-redirects' ) );
        exit;
    }
}
