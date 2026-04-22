<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keyword auto-linking.
 *
 * A deterministic keyword -> link rule engine. Stores rules as an option
 * (array of associative arrays) and processes post content via the_content
 * to wrap the first N occurrences of each keyword in an <a> tag pointing to
 * the configured link.
 *
 * Skips content that is already inside <a>, <code>, <pre>, heading tags, or
 * tags that have a class matching the excluded list.
 */
class LP_Keyword_Links {

    const OPTION_RULES  = 'lp_keyword_rules';
    const OPTION_ENABLE = 'lp_keyword_enabled';

    public static function init() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
            add_action( 'admin_post_lp_keyword_save', array( __CLASS__, 'handle_save' ) );
            add_action( 'admin_post_lp_keyword_delete', array( __CLASS__, 'handle_delete' ) );
        }

        if ( get_option( self::OPTION_ENABLE, 'no' ) !== 'yes' ) {
            return;
        }
        add_filter( 'the_content', array( __CLASS__, 'process_content' ), 40 );
    }

    /**
     * Get the configured rules.
     *
     * @return array<int, array{keyword:string, link_id:int, case_sensitive:bool, max:int}>
     */
    public static function get_rules() {
        $rules = get_option( self::OPTION_RULES, array() );
        if ( ! is_array( $rules ) ) {
            return array();
        }
        return $rules;
    }

    /**
     * Content filter: replace keywords with cloaked links.
     *
     * @param string $content Post content HTML.
     * @return string
     */
    public static function process_content( $content ) {
        if ( '' === $content || is_admin() || is_feed() ) {
            return $content;
        }

        $rules = self::get_rules();
        if ( empty( $rules ) ) {
            return $content;
        }

        // Split content into segments that are either "processable text" or
        // "protected HTML" we must leave alone.
        $segments = self::tokenize_content( $content );

        foreach ( $rules as $rule ) {
            $keyword = isset( $rule['keyword'] ) ? (string) $rule['keyword'] : '';
            $link_id = isset( $rule['link_id'] ) ? (int) $rule['link_id'] : 0;
            $max     = isset( $rule['max'] ) ? max( 1, (int) $rule['max'] ) : 1;
            $cs      = ! empty( $rule['case_sensitive'] );
            if ( '' === $keyword || $link_id <= 0 ) {
                continue;
            }

            $link = get_post( $link_id );
            if ( ! $link || 'lp_link' !== $link->post_type || 'publish' !== $link->post_status ) {
                continue;
            }

            $url = self::get_cloaked_url_for( $link );
            if ( empty( $url ) ) {
                continue;
            }

            $replaced = 0;
            $pattern  = '/(?<![\w>])' . preg_quote( $keyword, '/' ) . '(?![\w<])/' . ( $cs ? '' : 'i' ) . 'u';

            foreach ( $segments as $i => $seg ) {
                if ( 'text' !== $seg['type'] ) {
                    continue;
                }
                if ( $replaced >= $max ) {
                    break;
                }
                $need = $max - $replaced;
                $segments[ $i ]['value'] = preg_replace_callback(
                    $pattern,
                    function ( $m ) use ( &$replaced, $max, $url ) {
                        if ( $replaced >= $max ) {
                            return $m[0];
                        }
                        $replaced++;
                        return '<a href="' . esc_url( $url ) . '" class="lp-keyword-link">' . $m[0] . '</a>';
                    },
                    $segments[ $i ]['value'],
                    $need
                );
            }
        }

        // Re-assemble.
        $out = '';
        foreach ( $segments as $seg ) {
            $out .= $seg['value'];
        }
        return $out;
    }

    /**
     * Split content into an array of segments, tagging each as "text" (safe
     * to process) or "html" (leave as-is). Protected regions are:
     *   - <a>...</a>
     *   - <code>...</code>, <pre>...</pre>
     *   - <h1>...</h6>
     *   - <script>/<style>
     *
     * @param string $content HTML.
     * @return array<int, array{type:string, value:string}>
     */
    private static function tokenize_content( $content ) {
        $protected = '(a|code|pre|script|style|h1|h2|h3|h4|h5|h6)';
        $pattern   = '#<(' . $protected . ')\b[^>]*>.*?</\2>#is';

        $segments = array();
        $offset   = 0;
        if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $match ) {
                $start = $match[1];
                $html  = $match[0];
                if ( $start > $offset ) {
                    $segments[] = array( 'type' => 'text', 'value' => substr( $content, $offset, $start - $offset ) );
                }
                $segments[] = array( 'type' => 'html', 'value' => $html );
                $offset = $start + strlen( $html );
            }
        }
        if ( $offset < strlen( $content ) ) {
            $segments[] = array( 'type' => 'text', 'value' => substr( $content, $offset ) );
        }
        if ( empty( $segments ) ) {
            $segments[] = array( 'type' => 'text', 'value' => $content );
        }
        return $segments;
    }

    /**
     * Get the cloaked URL for a link post, reusing LP_Link if present.
     *
     * @param WP_Post $link Link post.
     * @return string
     */
    private static function get_cloaked_url_for( $link ) {
        if ( class_exists( 'LP_Link' ) ) {
            $obj = new LP_Link( $link->ID );
            if ( method_exists( $obj, 'get_cloaked_url' ) ) {
                return (string) $obj->get_cloaked_url();
            }
        }
        $prefix = trim( (string) get_option( 'lp_link_prefix', 'go' ), '/' );
        return home_url( '/' . $prefix . '/' . $link->post_name . '/' );
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public static function add_admin_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Keyword Links', 'linkpilot' ),
            __( 'Keyword Links', 'linkpilot' ),
            'manage_options',
            'lp-keyword-links',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $rules   = self::get_rules();
        $enabled = get_option( self::OPTION_ENABLE, 'no' );

        // Fetch a list of lp_link posts for the destination dropdown.
        $links = get_posts( array(
            'post_type'      => 'lp_link',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Keyword Links', 'linkpilot' ); ?></h1>
            <p><?php esc_html_e( 'Automatically turn a keyword into a cloaked link wherever it appears in your posts. The first matching occurrences are linked (up to the per-rule max).', 'linkpilot' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lp_keyword_save' ); ?>
                <input type="hidden" name="action" value="lp_keyword_save" />

                <p>
                    <label>
                        <input type="checkbox" name="lp_keyword_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                        <?php esc_html_e( 'Enable keyword auto-linking on post content.', 'linkpilot' ); ?>
                    </label>
                </p>

                <h2><?php esc_html_e( 'Rules', 'linkpilot' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Keyword', 'linkpilot' ); ?></th>
                            <th><?php esc_html_e( 'Destination link', 'linkpilot' ); ?></th>
                            <th><?php esc_html_e( 'Case-sensitive', 'linkpilot' ); ?></th>
                            <th><?php esc_html_e( 'Max per post', 'linkpilot' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'linkpilot' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rules as $idx => $rule ) : ?>
                            <tr>
                                <td>
                                    <input type="text" name="rules[<?php echo (int) $idx; ?>][keyword]"
                                        value="<?php echo esc_attr( $rule['keyword'] ); ?>" class="regular-text" />
                                </td>
                                <td>
                                    <select name="rules[<?php echo (int) $idx; ?>][link_id]">
                                        <option value="0">&mdash;</option>
                                        <?php foreach ( $links as $link ) : ?>
                                            <option value="<?php echo (int) $link->ID; ?>" <?php selected( (int) $rule['link_id'], (int) $link->ID ); ?>>
                                                <?php echo esc_html( $link->post_title ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="checkbox" name="rules[<?php echo (int) $idx; ?>][case_sensitive]" value="1" <?php checked( ! empty( $rule['case_sensitive'] ) ); ?> />
                                </td>
                                <td>
                                    <input type="number" min="1" max="50" name="rules[<?php echo (int) $idx; ?>][max]"
                                        value="<?php echo (int) ( isset( $rule['max'] ) ? $rule['max'] : 1 ); ?>" style="width: 70px;" />
                                </td>
                                <td>
                                    <?php
                                    $delete_url = wp_nonce_url(
                                        admin_url( 'admin-post.php?action=lp_keyword_delete&idx=' . (int) $idx ),
                                        'lp_keyword_delete_' . (int) $idx
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button-link-delete"
                                        onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'linkpilot' ) ); ?>');">
                                        <?php esc_html_e( 'Delete', 'linkpilot' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Empty row for a new rule -->
                        <tr>
                            <td>
                                <input type="text" name="new_rule[keyword]" placeholder="<?php esc_attr_e( 'New keyword', 'linkpilot' ); ?>" class="regular-text" />
                            </td>
                            <td>
                                <select name="new_rule[link_id]">
                                    <option value="0">&mdash;</option>
                                    <?php foreach ( $links as $link ) : ?>
                                        <option value="<?php echo (int) $link->ID; ?>"><?php echo esc_html( $link->post_title ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="checkbox" name="new_rule[case_sensitive]" value="1" />
                            </td>
                            <td>
                                <input type="number" min="1" max="50" name="new_rule[max]" value="1" style="width: 70px;" />
                            </td>
                            <td>&mdash;</td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save Rules', 'linkpilot' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle saving the rules form.
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        check_admin_referer( 'lp_keyword_save' );

        update_option( self::OPTION_ENABLE, ! empty( $_POST['lp_keyword_enabled'] ) ? 'yes' : 'no' );

        $clean = array();

        // Existing rules.
        if ( isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.
            $raw_rules = wp_unslash( $_POST['rules'] );
            foreach ( $raw_rules as $row ) {
                $entry = self::sanitize_rule_row( $row );
                if ( $entry ) {
                    $clean[] = $entry;
                }
            }
        }

        // New rule row.
        if ( isset( $_POST['new_rule'] ) && is_array( $_POST['new_rule'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.
            $new_rule = wp_unslash( $_POST['new_rule'] );
            $entry    = self::sanitize_rule_row( $new_rule );
            if ( $entry ) {
                $clean[] = $entry;
            }
        }

        update_option( self::OPTION_RULES, $clean );

        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'edit.php?post_type=lp_link&page=lp-keyword-links' ) ) );
        exit;
    }

    /**
     * Sanitize a single rule row.
     *
     * @param array $row
     * @return array|null Clean rule or null if invalid.
     */
    private static function sanitize_rule_row( $row ) {
        if ( ! is_array( $row ) ) {
            return null;
        }
        $keyword = isset( $row['keyword'] ) ? trim( sanitize_text_field( $row['keyword'] ) ) : '';
        $link_id = isset( $row['link_id'] ) ? absint( $row['link_id'] ) : 0;
        if ( '' === $keyword || $link_id <= 0 ) {
            return null;
        }
        return array(
            'keyword'        => $keyword,
            'link_id'        => $link_id,
            'case_sensitive' => ! empty( $row['case_sensitive'] ),
            'max'            => isset( $row['max'] ) ? max( 1, min( 50, (int) $row['max'] ) ) : 1,
        );
    }

    /**
     * Handle a single rule deletion.
     */
    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'linkpilot' ) );
        }
        $idx = isset( $_GET['idx'] ) ? absint( $_GET['idx'] ) : -1;
        check_admin_referer( 'lp_keyword_delete_' . $idx );

        $rules = self::get_rules();
        if ( isset( $rules[ $idx ] ) ) {
            unset( $rules[ $idx ] );
            update_option( self::OPTION_RULES, array_values( $rules ) );
        }
        wp_safe_redirect( admin_url( 'edit.php?post_type=lp_link&page=lp-keyword-links' ) );
        exit;
    }
}
