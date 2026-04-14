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
