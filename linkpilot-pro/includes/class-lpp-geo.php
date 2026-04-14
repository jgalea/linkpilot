<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Geo {

    const META_KEY = '_lpp_geo_redirects';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_geo_redirect' ), 10, 2 );
        add_filter( 'lp_link_meta_keys', array( __CLASS__, 'register_meta_key' ) );
        add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_lp_link', array( __CLASS__, 'save_meta_box' ), 20, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
    }

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Geo Overrides', 'linkpilot-pro' ),
            __( 'Geo Overrides', 'linkpilot-pro' ),
            'manage_options',
            'lpp-geo-overrides',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function detected_header_name() {
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) return 'CF-IPCountry';
        if ( ! empty( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ) return 'CloudFront-Viewer-Country';
        if ( ! empty( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) return 'GEOIP_COUNTRY_CODE';
        return '';
    }

    public static function render_admin_page() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'lp_link'
             AND p.post_status = 'publish'
             AND pm.meta_key = %s
             ORDER BY p.post_title ASC",
            self::META_KEY
        ) );

        $detected_country = self::detect_country();
        $detected_header  = self::detected_header_name();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Geo-Targeted Redirect Overrides', 'linkpilot-pro' ); ?></h1>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 16px; margin: 16px 0; max-width: 900px;">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'Geo detection status', 'linkpilot-pro' ); ?></h2>
                <p>
                    <?php if ( $detected_country ) : ?>
                        <?php printf(
                            esc_html__( 'Your current country is detected as %1$s via the %2$s header.', 'linkpilot-pro' ),
                            '<strong>' . esc_html( $detected_country ) . '</strong>',
                            '<code>' . esc_html( $detected_header ) . '</code>'
                        ); ?>
                    <?php else : ?>
                        <strong style="color: #d63638;"><?php esc_html_e( 'No geo header detected.', 'linkpilot-pro' ); ?></strong>
                        <?php esc_html_e( 'Your host is not providing a country header. Geo redirects will silently fall back to the default destination.', 'linkpilot-pro' ); ?>
                    <?php endif; ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'LinkPilot checks three headers in order: CF-IPCountry (Cloudflare), CloudFront-Viewer-Country (AWS), GEOIP_COUNTRY_CODE (WP Engine / Kinsta). The first one found is used.', 'linkpilot-pro' ); ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'Links with geo overrides', 'linkpilot-pro' ); ?> <span style="color:#787c82;font-weight:normal;">(<?php echo count( $rows ); ?>)</span></h2>

            <?php if ( empty( $rows ) ) : ?>
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; max-width: 900px;">
                    <p><?php esc_html_e( 'No links have geo overrides configured yet.', 'linkpilot-pro' ); ?></p>
                    <p class="description">
                        <?php esc_html_e( 'Edit any link and scroll to the "Geo-Targeted Redirects" meta box to set per-country destination URLs. Use ISO 3166-1 alpha-2 country codes (US, GB, DE, ES...).', 'linkpilot-pro' ); ?>
                    </p>
                </div>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 1100px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Link', 'linkpilot-pro' ); ?></th>
                            <th><?php esc_html_e( 'Country overrides', 'linkpilot-pro' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'linkpilot-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $map = maybe_unserialize( $row->meta_value );
                            if ( ! is_array( $map ) ) continue;
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ); ?>"><strong><?php echo esc_html( $row->post_title ); ?></strong></a>
                                </td>
                                <td>
                                    <?php foreach ( $map as $cc => $url ) : ?>
                                        <div style="margin-bottom: 4px;">
                                            <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( $cc ); ?></code>
                                            &rarr; <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $url, PHP_URL_HOST ) ); ?></a>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td><?php echo count( $map ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function register_meta_key( $keys ) {
        $keys['geo_redirects'] = self::META_KEY;
        return $keys;
    }

    public static function apply_geo_redirect( $destination, $link ) {
        $map = get_post_meta( $link->get_id(), self::META_KEY, true );
        if ( empty( $map ) || ! is_array( $map ) ) {
            return $destination;
        }

        $country = self::detect_country();
        if ( $country && ! empty( $map[ $country ] ) ) {
            return $map[ $country ];
        }

        return $destination;
    }

    public static function detect_country() {
        $headers = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' );
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $cc = strtoupper( sanitize_text_field( $_SERVER[ $h ] ) );
                if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
                    return $cc;
                }
            }
        }
        return '';
    }

    public static function add_meta_box() {
        add_meta_box(
            'lpp_geo_redirects',
            __( 'Geo-Targeted Redirects', 'linkpilot-pro' ),
            array( __CLASS__, 'render_meta_box' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'lpp_geo_save', 'lpp_geo_nonce' );
        $map = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }
        ?>
        <p><?php esc_html_e( 'Override the destination URL for specific countries. Leave empty to use the default destination.', 'linkpilot-pro' ); ?></p>
        <table class="widefat striped" id="lpp-geo-table">
            <thead><tr>
                <th style="width: 120px;"><?php esc_html_e( 'Country Code', 'linkpilot-pro' ); ?></th>
                <th><?php esc_html_e( 'Destination URL', 'linkpilot-pro' ); ?></th>
                <th style="width: 50px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $map as $cc => $url ) : ?>
                <tr>
                    <td><input type="text" name="lpp_geo_country[]" value="<?php echo esc_attr( $cc ); ?>" maxlength="2" style="text-transform: uppercase;" /></td>
                    <td><input type="url" name="lpp_geo_url[]" value="<?php echo esc_attr( $url ); ?>" class="large-text" /></td>
                    <td><button type="button" class="button-link lpp-geo-remove">&times;</button></td>
                </tr>
            <?php endforeach; ?>
                <tr>
                    <td><input type="text" name="lpp_geo_country[]" value="" maxlength="2" style="text-transform: uppercase;" /></td>
                    <td><input type="url" name="lpp_geo_url[]" value="" class="large-text" /></td>
                    <td><button type="button" class="button-link lpp-geo-remove">&times;</button></td>
                </tr>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lpp-geo-add"><?php esc_html_e( 'Add Country', 'linkpilot-pro' ); ?></button></p>
        <p class="description"><?php esc_html_e( 'Use ISO 3166-1 alpha-2 country codes (e.g., US, GB, DE, FR). Country is detected via Cloudflare, CloudFront, or host-provided geo headers.', 'linkpilot-pro' ); ?></p>
        <script>
        (function(){
            var table = document.getElementById('lpp-geo-table');
            if (!table) return;
            document.getElementById('lpp-geo-add').addEventListener('click', function(){
                var row = table.querySelector('tbody tr:last-child').cloneNode(true);
                row.querySelectorAll('input').forEach(function(i){ i.value = ''; });
                table.querySelector('tbody').appendChild(row);
            });
            table.addEventListener('click', function(e){
                if (e.target.classList.contains('lpp-geo-remove')) {
                    var rows = table.querySelectorAll('tbody tr');
                    if (rows.length > 1) { e.target.closest('tr').remove(); }
                    else { e.target.closest('tr').querySelectorAll('input').forEach(function(i){ i.value = ''; }); }
                }
            });
        })();
        </script>
        <?php
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['lpp_geo_nonce'] ) || ! wp_verify_nonce( $_POST['lpp_geo_nonce'], 'lpp_geo_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $countries = isset( $_POST['lpp_geo_country'] ) ? (array) $_POST['lpp_geo_country'] : array();
        $urls      = isset( $_POST['lpp_geo_url'] ) ? (array) $_POST['lpp_geo_url'] : array();

        $map = array();
        foreach ( $countries as $i => $cc ) {
            $cc  = strtoupper( trim( sanitize_text_field( $cc ) ) );
            $url = isset( $urls[ $i ] ) ? esc_url_raw( trim( $urls[ $i ] ) ) : '';
            if ( preg_match( '/^[A-Z]{2}$/', $cc ) && $url ) {
                $map[ $cc ] = $url;
            }
        }

        if ( $map ) {
            update_post_meta( $post_id, self::META_KEY, $map );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }
}
