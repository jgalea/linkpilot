<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var LP_Link $link */

$dest_url      = $link->get_destination_url();
$redirect_type = get_post_meta( $link->get_id(), '_lp_redirect_type', true ) ?: 'default';
$nofollow      = get_post_meta( $link->get_id(), '_lp_nofollow', true ) ?: 'default';
$sponsored     = get_post_meta( $link->get_id(), '_lp_sponsored', true ) ?: 'default';
$new_window    = get_post_meta( $link->get_id(), '_lp_new_window', true ) ?: 'default';
$pass_qs       = get_post_meta( $link->get_id(), '_lp_pass_query_str', true ) ?: 'default';
$css_classes   = $link->get_css_classes();
$rel_tags      = get_post_meta( $link->get_id(), '_lp_rel_tags', true );
$js_redirect   = get_post_meta( $link->get_id(), '_lp_js_redirect', true ) ?: 'no';
?>
<table class="form-table lp-meta-box">
    <tr>
        <th><label for="lp_destination_url"><?php esc_html_e( 'Destination URL', 'linkpilot' ); ?></label></th>
        <td><input type="url" id="lp_destination_url" name="lp_destination_url" value="<?php echo esc_url( $dest_url ); ?>" class="widefat" placeholder="https://" required /></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e( 'Cloaked URL', 'linkpilot' ); ?></label></th>
        <td>
            <span class="lp-cloaked-url-wrap">
                <code id="lp-cloaked-url"><?php echo esc_html( $link->get_cloaked_url() ); ?></code>
                <button type="button" class="lp-copy-btn" data-clipboard-target="#lp-cloaked-url" aria-label="<?php esc_attr_e( 'Copy URL to clipboard', 'linkpilot' ); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                    <span class="lp-copy-label"><?php esc_html_e( 'Copy', 'linkpilot' ); ?></span>
                </button>
            </span>
        </td>
    </tr>
    <tr class="lp-field-group">
        <th><label for="lp_redirect_type"><?php esc_html_e( 'Redirect Type', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_redirect_type" name="lp_redirect_type">
                <option value="default" <?php selected( $redirect_type, 'default' ); ?>><?php printf( esc_html__( 'Default (%s)', 'linkpilot' ), esc_html( get_option( 'lp_redirect_type', '307' ) ) ); ?></option>
                <option value="301" <?php selected( $redirect_type, '301' ); ?>>301 (Permanent)</option>
                <option value="302" <?php selected( $redirect_type, '302' ); ?>>302 (Temporary)</option>
                <option value="307" <?php selected( $redirect_type, '307' ); ?>>307 (Temporary Strict)</option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="lp_nofollow"><?php esc_html_e( 'Nofollow', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_nofollow" name="lp_nofollow">
                <option value="default" <?php selected( $nofollow, 'default' ); ?>><?php printf( esc_html__( 'Default (%s)', 'linkpilot' ), get_option( 'lp_nofollow', 'yes' ) === 'yes' ? 'Yes' : 'No' ); ?></option>
                <option value="yes" <?php selected( $nofollow, 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                <option value="no" <?php selected( $nofollow, 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="lp_sponsored"><?php esc_html_e( 'Sponsored', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_sponsored" name="lp_sponsored">
                <option value="default" <?php selected( $sponsored, 'default' ); ?>><?php printf( esc_html__( 'Default (%s)', 'linkpilot' ), get_option( 'lp_sponsored', 'no' ) === 'yes' ? 'Yes' : 'No' ); ?></option>
                <option value="yes" <?php selected( $sponsored, 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                <option value="no" <?php selected( $sponsored, 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="lp_new_window"><?php esc_html_e( 'Open in New Window', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_new_window" name="lp_new_window">
                <option value="default" <?php selected( $new_window, 'default' ); ?>><?php printf( esc_html__( 'Default (%s)', 'linkpilot' ), get_option( 'lp_new_window', 'yes' ) === 'yes' ? 'Yes' : 'No' ); ?></option>
                <option value="yes" <?php selected( $new_window, 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                <option value="no" <?php selected( $new_window, 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="lp_pass_query_str"><?php esc_html_e( 'Pass Query Strings', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_pass_query_str" name="lp_pass_query_str">
                <option value="default" <?php selected( $pass_qs, 'default' ); ?>><?php printf( esc_html__( 'Default (%s)', 'linkpilot' ), get_option( 'lp_pass_query_str', 'no' ) === 'yes' ? 'Yes' : 'No' ); ?></option>
                <option value="yes" <?php selected( $pass_qs, 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                <option value="no" <?php selected( $pass_qs, 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
            </select>
        </td>
    </tr>
    <tr class="lp-field-group">
        <th><label for="lp_css_classes"><?php esc_html_e( 'CSS Classes', 'linkpilot' ); ?></label></th>
        <td><input type="text" id="lp_css_classes" name="lp_css_classes" value="<?php echo esc_attr( $css_classes ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Optional CSS classes', 'linkpilot' ); ?>" /></td>
    </tr>
    <tr>
        <th><label for="lp_rel_tags"><?php esc_html_e( 'Additional Rel Tags', 'linkpilot' ); ?></label></th>
        <td>
            <input type="text" id="lp_rel_tags" name="lp_rel_tags" value="<?php echo esc_attr( $rel_tags ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. ugc', 'linkpilot' ); ?>" />
            <p class="description"><?php esc_html_e( 'Space-separated. Nofollow and sponsored are handled by their own settings above.', 'linkpilot' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="lp_js_redirect"><?php esc_html_e( 'JavaScript Redirect', 'linkpilot' ); ?></label></th>
        <td>
            <select id="lp_js_redirect" name="lp_js_redirect">
                <option value="no" <?php selected( $js_redirect, 'no' ); ?>><?php esc_html_e( 'No (use HTTP redirect)', 'linkpilot' ); ?></option>
                <option value="yes" <?php selected( $js_redirect, 'yes' ); ?>><?php esc_html_e( 'Yes (preserves referrer)', 'linkpilot' ); ?></option>
            </select>
            <p class="description"><?php esc_html_e( 'Use JavaScript to redirect. Preserves the HTTP referrer for affiliate networks that require it. Slower than HTTP redirect.', 'linkpilot' ); ?></p>
        </td>
    </tr>
</table>
