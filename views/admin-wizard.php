<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$competitors = LP_Setup_Wizard::detect_competitors();
?>

<div class="wrap" style="max-width: 700px;">
    <h1><?php esc_html_e( 'Welcome to LinkPilot', 'linkpilot' ); ?></h1>
    <p><?php esc_html_e( 'Let\'s get your link manager set up. This will only take a moment.', 'linkpilot' ); ?></p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'lp_wizard_save' ); ?>
        <input type="hidden" name="action" value="lp_wizard_save" />

        <?php if ( ! empty( $competitors ) ) : ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'Migrate Your Links', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'We detected link data from another plugin. Want to import it?', 'linkpilot' ); ?></p>

            <?php foreach ( $competitors as $key => $info ) : ?>
                <label style="display: block; margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; cursor: pointer;">
                    <input type="radio" name="lp_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <strong><?php echo esc_html( $info['name'] ); ?></strong>
                    — <?php printf( esc_html__( '%d links found', 'linkpilot' ), $info['count'] ); ?>
                </label>
            <?php endforeach; ?>

            <label style="display: block; margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; cursor: pointer;">
                <input type="radio" name="lp_migrate_source" value="" checked />
                <?php esc_html_e( 'Skip migration — I\'ll start fresh', 'linkpilot' ); ?>
            </label>

            <p>
                <label>
                    <input type="checkbox" name="lp_scan_content" value="1" checked />
                    <?php esc_html_e( 'Also update shortcodes in existing posts', 'linkpilot' ); ?>
                </label>
            </p>
        </div>
        <?php endif; ?>

        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'Link Defaults', 'linkpilot' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'URL Prefix', 'linkpilot' ); ?></th>
                    <td>
                        <input type="text" name="lp_link_prefix" value="<?php echo esc_attr( get_option( 'lp_link_prefix', 'go' ) ); ?>" class="regular-text" />
                        <p class="description"><?php printf( esc_html__( 'Links will be: %s/go/your-link/', 'linkpilot' ), esc_html( home_url() ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Redirect Type', 'linkpilot' ); ?></th>
                    <td>
                        <select name="lp_redirect_type">
                            <option value="307" <?php selected( get_option( 'lp_redirect_type', '307' ), '307' ); ?>>307 (Temporary)</option>
                            <option value="301" <?php selected( get_option( 'lp_redirect_type', '307' ), '301' ); ?>>301 (Permanent)</option>
                            <option value="302" <?php selected( get_option( 'lp_redirect_type', '307' ), '302' ); ?>>302 (Found)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Add nofollow', 'linkpilot' ); ?></th>
                    <td>
                        <select name="lp_nofollow">
                            <option value="yes" <?php selected( get_option( 'lp_nofollow', 'yes' ), 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                            <option value="no" <?php selected( get_option( 'lp_nofollow', 'yes' ), 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Open in new window', 'linkpilot' ); ?></th>
                    <td>
                        <select name="lp_new_window">
                            <option value="yes" <?php selected( get_option( 'lp_new_window', 'yes' ), 'yes' ); ?>><?php esc_html_e( 'Yes', 'linkpilot' ); ?></option>
                            <option value="no" <?php selected( get_option( 'lp_new_window', 'yes' ), 'no' ); ?>><?php esc_html_e( 'No', 'linkpilot' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button( __( 'Finish Setup', 'linkpilot' ), 'primary large' ); ?>
    </form>

    <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lp_link' ) ); ?>"><?php esc_html_e( 'Skip setup', 'linkpilot' ); ?></a></p>
</div>
