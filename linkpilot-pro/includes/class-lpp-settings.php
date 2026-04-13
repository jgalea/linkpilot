<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LPP_Settings {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
    }

    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=lp_link',
            __( 'Pro Settings', 'linkpilot-pro' ),
            __( 'Pro Settings', 'linkpilot-pro' ),
            'manage_options',
            'lpp-settings',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'lpp_settings_group', 'lpp_ai_provider', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'openai',
        ) );
        register_setting( 'lpp_settings_group', 'lpp_ai_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ) );
        register_setting( 'lpp_settings_group', 'lpp_enable_auto_linking', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'no',
        ) );
        register_setting( 'lpp_settings_group', 'lpp_enable_suggestions', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'yes',
        ) );
    }

    public static function render_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LinkPilot Pro Settings', 'linkpilot-pro' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'lpp_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'AI Provider', 'linkpilot-pro' ); ?></th>
                        <td>
                            <select name="lpp_ai_provider">
                                <option value="openai" <?php selected( get_option( 'lpp_ai_provider', 'openai' ), 'openai' ); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected( get_option( 'lpp_ai_provider', 'openai' ), 'anthropic' ); ?>>Anthropic</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'API Key', 'linkpilot-pro' ); ?></th>
                        <td>
                            <input type="password" name="lpp_ai_api_key" value="<?php echo esc_attr( get_option( 'lpp_ai_api_key', '' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Your API key is stored in the database and never sent to our servers.', 'linkpilot-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Link Suggestions', 'linkpilot-pro' ); ?></th>
                        <td>
                            <select name="lpp_enable_suggestions">
                                <option value="yes" <?php selected( get_option( 'lpp_enable_suggestions', 'yes' ), 'yes' ); ?>><?php esc_html_e( 'Enabled', 'linkpilot-pro' ); ?></option>
                                <option value="no" <?php selected( get_option( 'lpp_enable_suggestions', 'yes' ), 'no' ); ?>><?php esc_html_e( 'Disabled', 'linkpilot-pro' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Show AI link suggestions in the editor sidebar while writing.', 'linkpilot-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Smart Auto-Linking', 'linkpilot-pro' ); ?></th>
                        <td>
                            <select name="lpp_enable_auto_linking">
                                <option value="yes" <?php selected( get_option( 'lpp_enable_auto_linking', 'no' ), 'yes' ); ?>><?php esc_html_e( 'Enabled', 'linkpilot-pro' ); ?></option>
                                <option value="no" <?php selected( get_option( 'lpp_enable_auto_linking', 'no' ), 'no' ); ?>><?php esc_html_e( 'Disabled', 'linkpilot-pro' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Automatically insert managed links into content based on context understanding.', 'linkpilot-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
