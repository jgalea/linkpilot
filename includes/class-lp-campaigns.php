<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Link groups / campaigns.
 *
 * Registers a custom taxonomy `lp_campaign` for lp_link posts. Each term
 * can have its own UTM defaults (stored as term meta). When a click is
 * resolved, the campaign's defaults feed into the UTM builder as fallbacks
 * before the global settings are consulted.
 *
 * UI:
 *   - Native taxonomy admin at Links > Campaigns
 *   - Extra fields on the term edit screen for utm_source / utm_medium /
 *     utm_campaign, plus default rel / target.
 */
class LP_Campaigns {

    const TAX     = 'lp_campaign';
    const UTM_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
        add_filter( 'lp_redirect_destination', array( __CLASS__, 'apply_campaign_utms' ), 18, 2 );

        if ( is_admin() ) {
            add_action( self::TAX . '_add_form_fields',  array( __CLASS__, 'render_add_fields' ) );
            add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 10, 2 );
            add_action( 'created_' . self::TAX,          array( __CLASS__, 'save_term_fields' ) );
            add_action( 'edited_' . self::TAX,           array( __CLASS__, 'save_term_fields' ) );
        }
    }

    public static function register_taxonomy() {
        register_taxonomy(
            self::TAX,
            'lp_link',
            array(
                'hierarchical'      => false,
                'labels'            => array(
                    'name'          => __( 'Campaigns', 'linkpilot' ),
                    'singular_name' => __( 'Campaign', 'linkpilot' ),
                    'search_items'  => __( 'Search campaigns', 'linkpilot' ),
                    'all_items'     => __( 'All campaigns', 'linkpilot' ),
                    'edit_item'     => __( 'Edit campaign', 'linkpilot' ),
                    'update_item'   => __( 'Update campaign', 'linkpilot' ),
                    'add_new_item'  => __( 'Add campaign', 'linkpilot' ),
                    'new_item_name' => __( 'New campaign name', 'linkpilot' ),
                    'menu_name'     => __( 'Campaigns', 'linkpilot' ),
                ),
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => false,
                'public'            => false,
            )
        );
    }

    /**
     * Feed campaign-level UTM defaults into the destination.
     *
     * Runs at priority 18 — between conditional/destinations (8-15) and the
     * UTM builder (20). We only set values that aren't already on the
     * destination or a per-link override. Think of this as a "campaign
     * context" injection.
     *
     * @param string  $destination
     * @param LP_Link $link
     * @return string
     */
    public static function apply_campaign_utms( $destination, $link ) {
        $campaigns = wp_get_object_terms( $link->get_id(), self::TAX, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $campaigns ) || empty( $campaigns ) ) {
            return $destination;
        }
        // First attached campaign wins if there's more than one.
        $term_id = (int) $campaigns[0];

        $parts = wp_parse_url( $destination );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $destination;
        }
        $existing = array();
        if ( ! empty( $parts['query'] ) ) {
            wp_parse_str( $parts['query'], $existing );
        }

        $changed = false;
        foreach ( self::UTM_KEYS as $k ) {
            if ( isset( $existing[ $k ] ) && '' !== $existing[ $k ] ) {
                continue;
            }
            // Per-link meta takes priority over campaign; skip if already set.
            $per_link = get_post_meta( $link->get_id(), '_lp_' . $k, true );
            if ( '' !== $per_link && null !== $per_link ) {
                continue;
            }
            $val = get_term_meta( $term_id, '_lp_' . $k, true );
            if ( '' !== $val && null !== $val ) {
                $existing[ $k ] = (string) $val;
                $changed = true;
            }
        }

        if ( ! $changed ) {
            return $destination;
        }

        $parts['query'] = http_build_query( $existing );
        return self::rebuild_url( $parts );
    }

    private static function rebuild_url( $parts ) {
        $scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
        $user     = isset( $parts['user'] ) ? $parts['user'] : '';
        $pass     = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
        $userpass = $user ? $user . $pass . '@' : '';
        $host     = isset( $parts['host'] ) ? $parts['host'] : '';
        $port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
        $path     = isset( $parts['path'] ) ? $parts['path'] : '';
        $query    = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';
        $fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
        return $scheme . $userpass . $host . $port . $path . $query . $fragment;
    }

    // ------------------------------------------------------------------
    // Term form
    // ------------------------------------------------------------------

    public static function render_add_fields() {
        ?>
        <div class="form-field">
            <h3><?php esc_html_e( 'Campaign UTM defaults (optional)', 'linkpilot' ); ?></h3>
            <p class="description"><?php esc_html_e( 'These apply to every link in this campaign unless the link sets its own UTM override. Settings-level defaults are used if both are blank.', 'linkpilot' ); ?></p>
            <?php foreach ( self::UTM_KEYS as $k ) : ?>
                <label><?php echo esc_html( $k ); ?>
                    <input type="text" name="lp_campaign_<?php echo esc_attr( $k ); ?>" value="" />
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function render_edit_fields( $term ) {
        ?>
        <tr class="form-field">
            <th><?php esc_html_e( 'Campaign UTM defaults', 'linkpilot' ); ?></th>
            <td>
                <?php foreach ( self::UTM_KEYS as $k ) :
                    $val = get_term_meta( $term->term_id, '_lp_' . $k, true );
                    ?>
                    <p style="margin: 4px 0;">
                        <label style="display: inline-block; width: 140px;"><?php echo esc_html( $k ); ?></label>
                        <input type="text" name="lp_campaign_<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $val ); ?>" class="regular-text" />
                    </p>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Only filled-in fields are applied. Per-link overrides on the link edit screen take priority.', 'linkpilot' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save_term_fields( $term_id ) {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }
        foreach ( self::UTM_KEYS as $k ) {
            $key = 'lp_campaign_' . $k;
            if ( ! isset( $_POST[ $key ] ) ) {
                continue;
            }
            $val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            if ( '' === $val ) {
                delete_term_meta( $term_id, '_lp_' . $k );
            } else {
                update_term_meta( $term_id, '_lp_' . $k, $val );
            }
        }
    }
}
