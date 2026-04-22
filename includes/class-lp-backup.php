<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Backup & restore.
 *
 * Export a single JSON containing:
 *   - Plugin options (all lp_* options)
 *   - Links (posts + meta)
 *   - Keyword auto-linking rules
 *   - Site redirects (lp_redirects table rows)
 *
 * Import reads the JSON and re-creates everything. Uses slug uniqueness to
 * avoid duplicating existing links.
 */
class LP_Backup {

    const VERSION = 1;

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
        add_action( 'admin_post_lp_backup_export', array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_lp_backup_import', array( __CLASS__, 'handle_import' ) );
    }

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Backup / Restore', 'linkpilot' ),
            __( 'Backup / Restore', 'linkpilot' ),
            'manage_options',
            'lp-backup',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
        $result = isset( $_GET['lp_result'] ) ? sanitize_key( wp_unslash( $_GET['lp_result'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LinkPilot Backup / Restore', 'linkpilot' ); ?></h1>

            <?php if ( 'imported' === $result ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Import complete.', 'linkpilot' ); ?></p></div>
            <?php elseif ( 'import_failed' === $result ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Import failed: invalid or unreadable JSON.', 'linkpilot' ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Export', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'Download everything LinkPilot knows about as a single JSON file: settings, links, keyword rules, redirects. Click data is not included — export that separately from Reports.', 'linkpilot' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lp_backup_export' ); ?>
                <input type="hidden" name="action" value="lp_backup_export" />
                <?php submit_button( __( 'Download backup', 'linkpilot' ), 'primary', 'submit', false ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Import', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'Upload a LinkPilot backup JSON. Existing links with the same slug are left untouched; new ones are created. Settings and redirects are merged.', 'linkpilot' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'lp_backup_import' ); ?>
                <input type="hidden" name="action" value="lp_backup_import" />
                <input type="file" name="lp_backup_file" accept=".json,application/json" required />
                <?php submit_button( __( 'Import backup', 'linkpilot' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_backup_export' );

        $data = array(
            'version'    => self::VERSION,
            'site_url'   => home_url( '/' ),
            'exported_at'=> gmdate( 'c' ),
            'options'    => self::collect_options(),
            'links'      => self::collect_links(),
            'keywords'   => get_option( 'lp_keyword_rules', array() ),
            'redirects'  => self::collect_redirects(),
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=linkpilot-backup-' . gmdate( 'Y-m-d' ) . '.json' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body.
        exit;
    }

    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_backup_import' );

        if ( ! isset( $_FILES['lp_backup_file'] ) || ! isset( $_FILES['lp_backup_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['lp_backup_file']['error'] ) {
            wp_safe_redirect( add_query_arg( 'lp_result', 'import_failed', admin_url( 'edit.php?post_type=lp_link&page=lp-backup' ) ) );
            exit;
        }
        $tmp_name = isset( $_FILES['lp_backup_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['lp_backup_file']['tmp_name'] ) ) : '';
        if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            wp_safe_redirect( add_query_arg( 'lp_result', 'import_failed', admin_url( 'edit.php?post_type=lp_link&page=lp-backup' ) ) );
            exit;
        }

        $raw = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded file.
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) {
            wp_safe_redirect( add_query_arg( 'lp_result', 'import_failed', admin_url( 'edit.php?post_type=lp_link&page=lp-backup' ) ) );
            exit;
        }

        // Options.
        if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
            foreach ( $data['options'] as $key => $value ) {
                if ( 0 === strpos( $key, 'lp_' ) ) {
                    update_option( $key, $value );
                }
            }
        }

        // Links.
        if ( ! empty( $data['links'] ) && is_array( $data['links'] ) ) {
            foreach ( $data['links'] as $item ) {
                self::restore_link( $item );
            }
        }

        // Keyword rules.
        if ( isset( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
            update_option( 'lp_keyword_rules', $data['keywords'] );
        }

        // Redirects.
        if ( ! empty( $data['redirects'] ) && is_array( $data['redirects'] ) && class_exists( 'LP_Redirects_DB' ) ) {
            foreach ( $data['redirects'] as $row ) {
                if ( ! is_array( $row ) || empty( $row['source_path'] ) ) {
                    continue;
                }
                LP_Redirects_DB::insert( array(
                    'source_path' => (string) $row['source_path'],
                    'destination' => (string) ( isset( $row['destination'] ) ? $row['destination'] : '' ),
                    'match_type'  => isset( $row['match_type'] ) ? (string) $row['match_type'] : 'exact',
                    'type'        => isset( $row['type'] ) ? (int) $row['type'] : 301,
                    'created_at'  => current_time( 'mysql', true ),
                ) );
            }
        }

        wp_safe_redirect( add_query_arg( 'lp_result', 'imported', admin_url( 'edit.php?post_type=lp_link&page=lp-backup' ) ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Collect / restore helpers
    // ------------------------------------------------------------------

    private static function collect_options() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            'lp\\_%'
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            // Skip transients and internal tracking keys.
            if ( 0 === strpos( $r->option_name, 'lp_flush_rewrite' ) ) {
                continue;
            }
            $out[ $r->option_name ] = maybe_unserialize( $r->option_value );
        }
        return $out;
    }

    private static function collect_links() {
        $posts = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $out = array();
        foreach ( $posts as $p ) {
            $meta = get_post_meta( $p->ID );
            $flat = array();
            foreach ( $meta as $k => $vals ) {
                $flat[ $k ] = count( $vals ) === 1 ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', $vals );
            }
            $out[] = array(
                'title'    => $p->post_title,
                'slug'     => $p->post_name,
                'status'   => $p->post_status,
                'excerpt'  => $p->post_excerpt,
                'meta'     => $flat,
                'cats'     => wp_get_post_terms( $p->ID, 'lp_category', array( 'fields' => 'names' ) ),
                'tags'     => wp_get_post_terms( $p->ID, 'lp_tag', array( 'fields' => 'names' ) ),
            );
        }
        return $out;
    }

    private static function collect_redirects() {
        if ( ! class_exists( 'LP_Redirects_DB' ) ) {
            return array();
        }
        $rows = LP_Redirects_DB::all();
        $out  = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'source_path' => $r->source_path,
                'destination' => $r->destination,
                'match_type'  => $r->match_type,
                'type'        => (int) $r->type,
            );
        }
        return $out;
    }

    private static function restore_link( $item ) {
        if ( empty( $item['slug'] ) ) {
            return;
        }
        // Skip if a link with this slug already exists.
        $existing = get_posts( array(
            'post_type'      => 'lp_link',
            'name'           => $item['slug'],
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ) );
        if ( ! empty( $existing ) ) {
            return;
        }

        $post_id = wp_insert_post( array(
            'post_type'    => 'lp_link',
            'post_title'   => isset( $item['title'] ) ? $item['title'] : $item['slug'],
            'post_name'    => $item['slug'],
            'post_status'  => isset( $item['status'] ) ? $item['status'] : 'publish',
            'post_excerpt' => isset( $item['excerpt'] ) ? $item['excerpt'] : '',
        ) );
        if ( is_wp_error( $post_id ) ) {
            return;
        }
        if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
            foreach ( $item['meta'] as $k => $v ) {
                if ( 0 === strpos( $k, '_lp_' ) ) {
                    update_post_meta( $post_id, $k, $v );
                }
            }
        }
        if ( ! empty( $item['cats'] ) ) {
            wp_set_post_terms( $post_id, (array) $item['cats'], 'lp_category' );
        }
        if ( ! empty( $item['tags'] ) ) {
            wp_set_post_terms( $post_id, (array) $item['tags'], 'lp_tag' );
        }
    }
}
