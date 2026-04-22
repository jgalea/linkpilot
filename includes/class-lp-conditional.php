<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditional redirects.
 *
 * Per-link set of rules evaluated in order. First match wins. Falls through
 * to the normal destination if no rule matches.
 *
 * Rule shape:
 *   { type: 'device'|'country'|'referrer'|'cookie', operator, value, url }
 *
 *   device    op: is_mobile | is_desktop | is_tablet
 *   country   op: is | is_not              value: "US" or comma list "US,CA"
 *   referrer  op: contains | starts_with   value: substring
 *   cookie    op: present | equals         value: "name=value" or just "name"
 *
 * Storage: _lp_cond_rules = JSON array.
 */
class LP_Conditional {

    const META = '_lp_cond_rules';

    public static function init() {
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'maybe_override' ), 8, 2 );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes_lp_link', array( __CLASS__, 'add_meta_box' ) );
            add_action( 'save_post_lp_link', array( __CLASS__, 'save' ), 20, 2 );
        }
    }

    /**
     * Filter callback: evaluate rules, return matching URL if any.
     *
     * @param string  $destination
     * @param LP_Link $link
     * @return string
     */
    public static function maybe_override( $destination, $link ) {
        $rules = self::get_rules( $link->get_id() );
        if ( empty( $rules ) ) {
            return $destination;
        }
        foreach ( $rules as $rule ) {
            if ( self::match( $rule ) && ! empty( $rule['url'] ) ) {
                return $rule['url'];
            }
        }
        return $destination;
    }

    /**
     * Evaluate a single rule against the current request.
     *
     * @param array $rule
     * @return bool
     */
    private static function match( $rule ) {
        $type = isset( $rule['type'] ) ? $rule['type'] : '';
        $op   = isset( $rule['operator'] ) ? $rule['operator'] : '';
        $val  = isset( $rule['value'] ) ? (string) $rule['value'] : '';

        switch ( $type ) {
            case 'device':
                return self::match_device( $op );

            case 'country':
                $country = self::detect_country();
                if ( '' === $country ) {
                    return false;
                }
                $list = array_map( 'strtoupper', array_map( 'trim', explode( ',', $val ) ) );
                $is_in = in_array( $country, $list, true );
                return 'is_not' === $op ? ! $is_in : $is_in;

            case 'referrer':
                $ref = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
                if ( '' === $ref ) {
                    return false;
                }
                if ( 'starts_with' === $op ) {
                    return strpos( $ref, $val ) === 0;
                }
                return stripos( $ref, $val ) !== false;

            case 'cookie':
                if ( strpos( $val, '=' ) !== false ) {
                    list( $name, $expected ) = array_map( 'trim', explode( '=', $val, 2 ) );
                } else {
                    $name     = trim( $val );
                    $expected = null;
                }
                if ( '' === $name || ! isset( $_COOKIE[ $name ] ) ) {
                    return false;
                }
                if ( 'present' === $op ) {
                    return true;
                }
                $got = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
                return $got === $expected;
        }

        return false;
    }

    private static function match_device( $op ) {
        if ( ! function_exists( 'wp_is_mobile' ) ) {
            return false;
        }
        $is_mobile = wp_is_mobile();
        // WordPress lumps tablets in with mobile; rough tablet detection via UA.
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_tablet = $is_mobile && preg_match( '/iPad|Tablet|Android(?!.*Mobile)/i', $ua );

        switch ( $op ) {
            case 'is_mobile':
                return $is_mobile && ! $is_tablet;
            case 'is_desktop':
                return ! $is_mobile;
            case 'is_tablet':
                return (bool) $is_tablet;
        }
        return false;
    }

    private static function detect_country() {
        $headers = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' );
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                return strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
            }
        }
        return '';
    }

    public static function get_rules( $link_id ) {
        $raw = get_post_meta( $link_id, self::META, true );
        if ( empty( $raw ) ) {
            return array();
        }
        $data = is_array( $raw ) ? $raw : json_decode( $raw, true );
        return is_array( $data ) ? $data : array();
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public static function add_meta_box() {
        add_meta_box(
            'lp_conditional',
            __( 'Conditional redirects', 'linkpilot' ),
            array( __CLASS__, 'render' ),
            'lp_link',
            'normal',
            'default'
        );
    }

    public static function render( $post ) {
        wp_nonce_field( 'lp_conditional_save', 'lp_conditional_nonce' );
        $rules = self::get_rules( $post->ID );
        if ( empty( $rules ) ) {
            $rules = array( array( 'type' => '', 'operator' => '', 'value' => '', 'url' => '' ) );
        }
        ?>
        <p class="description" style="margin-top: 0;">
            <?php esc_html_e( 'Rules are evaluated top-to-bottom. First match wins. Falls through to the default destination if no rule matches.', 'linkpilot' ); ?>
        </p>
        <table class="widefat" style="max-width: 960px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'If', 'linkpilot' ); ?></th>
                    <th><?php esc_html_e( 'Operator', 'linkpilot' ); ?></th>
                    <th><?php esc_html_e( 'Value', 'linkpilot' ); ?></th>
                    <th><?php esc_html_e( 'Redirect to', 'linkpilot' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rows = array_merge( $rules, array_fill( 0, 3, array( 'type' => '', 'operator' => '', 'value' => '', 'url' => '' ) ) ); ?>
                <?php foreach ( $rows as $i => $rule ) : ?>
                    <tr>
                        <td>
                            <select name="lp_cond_rules[<?php echo (int) $i; ?>][type]">
                                <option value="" <?php selected( $rule['type'], '' ); ?>>&mdash;</option>
                                <option value="device"   <?php selected( $rule['type'], 'device' ); ?>><?php esc_html_e( 'Device', 'linkpilot' ); ?></option>
                                <option value="country"  <?php selected( $rule['type'], 'country' ); ?>><?php esc_html_e( 'Country', 'linkpilot' ); ?></option>
                                <option value="referrer" <?php selected( $rule['type'], 'referrer' ); ?>><?php esc_html_e( 'Referrer', 'linkpilot' ); ?></option>
                                <option value="cookie"   <?php selected( $rule['type'], 'cookie' ); ?>><?php esc_html_e( 'Cookie', 'linkpilot' ); ?></option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="lp_cond_rules[<?php echo (int) $i; ?>][operator]" value="<?php echo esc_attr( $rule['operator'] ); ?>" placeholder="is / is_mobile / contains / present" style="width: 100%;" />
                        </td>
                        <td>
                            <input type="text" name="lp_cond_rules[<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $rule['value'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. US, US,CA — or cookie name', 'linkpilot' ); ?>" style="width: 100%;" />
                        </td>
                        <td>
                            <input type="url" name="lp_cond_rules[<?php echo (int) $i; ?>][url]" value="<?php echo esc_url( $rule['url'] ); ?>" placeholder="https://" style="width: 100%;" />
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <strong><?php esc_html_e( 'Operators', 'linkpilot' ); ?>:</strong>
            <code>device</code>: is_mobile, is_desktop, is_tablet.
            <code>country</code>: is, is_not.
            <code>referrer</code>: contains, starts_with.
            <code>cookie</code>: present, equals.
        </p>
        <?php
    }

    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['lp_conditional_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lp_conditional_nonce'] ) ), 'lp_conditional_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $valid_types = array( 'device', 'country', 'referrer', 'cookie' );
        $clean       = array();
        if ( isset( $_POST['lp_cond_rules'] ) && is_array( $_POST['lp_cond_rules'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields sanitized individually.
            $rows = wp_unslash( $_POST['lp_cond_rules'] );
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
                if ( ! in_array( $type, $valid_types, true ) ) {
                    continue;
                }
                $url = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
                if ( '' === $url ) {
                    continue;
                }
                $clean[] = array(
                    'type'     => $type,
                    'operator' => isset( $row['operator'] ) ? sanitize_text_field( $row['operator'] ) : '',
                    'value'    => isset( $row['value'] ) ? sanitize_text_field( $row['value'] ) : '',
                    'url'      => $url,
                );
            }
        }

        if ( empty( $clean ) ) {
            delete_post_meta( $post_id, self::META );
        } else {
            update_post_meta( $post_id, self::META, wp_json_encode( $clean ) );
        }
    }
}
