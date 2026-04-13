<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LP_CSV {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_post_lp_export_csv', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_lp_import_csv', array( __CLASS__, 'import' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Import / Export', 'linkpilot' ),
            __( 'Import / Export', 'linkpilot' ),
            'manage_options',
            'lp-import-export',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        $imported = isset( $_GET['lp_imported'] ) ? (int) $_GET['lp_imported'] : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import / Export Links', 'linkpilot' ); ?></h1>

            <?php if ( $imported ) : ?>
                <div class="notice notice-success"><p><?php printf( esc_html__( '%d links imported successfully.', 'linkpilot' ), $imported ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Export', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'Download all your links as a CSV file.', 'linkpilot' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lp_export_csv' ); ?>
                <input type="hidden" name="action" value="lp_export_csv" />
                <?php submit_button( __( 'Export CSV', 'linkpilot' ), 'secondary' ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Import', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'Upload a CSV file with columns: title, slug, destination_url, redirect_type, nofollow, sponsored, new_window, category', 'linkpilot' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'lp_import_csv' ); ?>
                <input type="hidden" name="action" value="lp_import_csv" />
                <input type="file" name="lp_csv_file" accept=".csv" required />
                <?php submit_button( __( 'Import CSV', 'linkpilot' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'lp_export_csv' );

        $links = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=linkpilot-export-' . gmdate( 'Y-m-d' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'title', 'slug', 'destination_url', 'redirect_type', 'nofollow', 'sponsored', 'new_window', 'pass_query_str', 'css_classes', 'rel_tags', 'categories', 'tags', 'status' ) );

        foreach ( $links as $post ) {
            $link       = new LP_Link( $post->ID );
            $categories = wp_get_post_terms( $post->ID, 'lp_category', array( 'fields' => 'names' ) );
            $tags       = wp_get_post_terms( $post->ID, 'lp_tag', array( 'fields' => 'names' ) );

            fputcsv( $output, array(
                $post->post_title,
                $post->post_name,
                $link->get_destination_url(),
                get_post_meta( $post->ID, '_lp_redirect_type', true ) ?: 'default',
                get_post_meta( $post->ID, '_lp_nofollow', true ) ?: 'default',
                get_post_meta( $post->ID, '_lp_sponsored', true ) ?: 'default',
                get_post_meta( $post->ID, '_lp_new_window', true ) ?: 'default',
                get_post_meta( $post->ID, '_lp_pass_query_str', true ) ?: 'default',
                $link->get_css_classes(),
                get_post_meta( $post->ID, '_lp_rel_tags', true ),
                implode( '|', $categories ),
                implode( '|', $tags ),
                $post->post_status,
            ) );
        }

        fclose( $output );
        exit;
    }

    public static function import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'lp_import_csv' );

        if ( ! isset( $_FILES['lp_csv_file'] ) || $_FILES['lp_csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_die( 'File upload failed.' );
        }

        $file = fopen( $_FILES['lp_csv_file']['tmp_name'], 'r' );
        if ( ! $file ) {
            wp_die( 'Could not read file.' );
        }

        $header  = fgetcsv( $file );
        $count   = 0;
        $col_map = array_flip( $header );

        while ( ( $row = fgetcsv( $file ) ) !== false ) {
            $title = isset( $col_map['title'] ) ? $row[ $col_map['title'] ] : '';
            $slug  = isset( $col_map['slug'] ) ? $row[ $col_map['slug'] ] : sanitize_title( $title );
            $url   = isset( $col_map['destination_url'] ) ? $row[ $col_map['destination_url'] ] : '';

            if ( ! $title || ! $url ) {
                continue;
            }

            $existing = LP_Link::find_by_slug( $slug );
            if ( $existing ) {
                continue;
            }

            $post_id = wp_insert_post( array(
                'post_type'   => 'lp_link',
                'post_title'  => sanitize_text_field( $title ),
                'post_name'   => sanitize_title( $slug ),
                'post_status' => isset( $col_map['status'] ) ? sanitize_text_field( $row[ $col_map['status'] ] ) : 'publish',
            ) );

            if ( is_wp_error( $post_id ) ) {
                continue;
            }

            $link = new LP_Link( $post_id );
            $link->save_meta( array(
                'destination_url' => esc_url_raw( $url ),
                'redirect_type'   => isset( $col_map['redirect_type'] ) ? sanitize_text_field( $row[ $col_map['redirect_type'] ] ) : 'default',
                'nofollow'        => isset( $col_map['nofollow'] ) ? sanitize_text_field( $row[ $col_map['nofollow'] ] ) : 'default',
                'sponsored'       => isset( $col_map['sponsored'] ) ? sanitize_text_field( $row[ $col_map['sponsored'] ] ) : 'default',
                'new_window'      => isset( $col_map['new_window'] ) ? sanitize_text_field( $row[ $col_map['new_window'] ] ) : 'default',
                'pass_query_str'  => isset( $col_map['pass_query_str'] ) ? sanitize_text_field( $row[ $col_map['pass_query_str'] ] ) : 'default',
                'css_classes'     => isset( $col_map['css_classes'] ) ? sanitize_text_field( $row[ $col_map['css_classes'] ] ) : '',
                'rel_tags'        => isset( $col_map['rel_tags'] ) ? sanitize_text_field( $row[ $col_map['rel_tags'] ] ) : '',
            ) );

            if ( isset( $col_map['categories'] ) && ! empty( $row[ $col_map['categories'] ] ) ) {
                $cats = array_map( 'trim', explode( '|', $row[ $col_map['categories'] ] ) );
                $term_ids = array();
                foreach ( $cats as $cat_name ) {
                    $term = get_term_by( 'name', $cat_name, 'lp_category' );
                    if ( ! $term ) {
                        $result = wp_insert_term( $cat_name, 'lp_category' );
                        if ( ! is_wp_error( $result ) ) {
                            $term_ids[] = $result['term_id'];
                        }
                    } else {
                        $term_ids[] = $term->term_id;
                    }
                }
                if ( $term_ids ) {
                    wp_set_post_terms( $post_id, $term_ids, 'lp_category' );
                }
            }

            if ( isset( $col_map['tags'] ) && ! empty( $row[ $col_map['tags'] ] ) ) {
                $tags = array_map( 'trim', explode( '|', $row[ $col_map['tags'] ] ) );
                wp_set_post_terms( $post_id, $tags, 'lp_tag' );
            }

            $count++;
        }

        fclose( $file );

        wp_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-import-export&lp_imported=' . $count ) );
        exit;
    }
}
