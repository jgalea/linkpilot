<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$migrators = array(
    'thirstyaffiliates'  => 'LP_Migrator_ThirstyAffiliates',
    'prettylinks'        => 'LP_Migrator_PrettyLinks',
    'linkcentral'        => 'LP_Migrator_LinkCentral',
    'easyaffiliatelinks' => 'LP_Migrator_EasyAffiliateLinks',
);

$available = array();
foreach ( $migrators as $key => $class ) {
    if ( $class::is_available() ) {
        $available[ $key ] = array(
            'name'  => $class::get_source_name(),
            'count' => $class::get_source_count(),
            'class' => $class,
        );
    }
}

$migration_result = null;
$scan_result      = null;

if ( isset( $_POST['lp_migrate_source'] ) && check_admin_referer( 'lp_run_migration' ) ) {
    $source_key = sanitize_key( $_POST['lp_migrate_source'] );
    if ( isset( $migrators[ $source_key ] ) ) {
        $class    = $migrators[ $source_key ];
        $migrator = new $class();
        $migration_result = $migrator->run();
        $migration_result['source'] = $class::get_source_name();

        if ( ! empty( $_POST['lp_scan_content'] ) ) {
            $scanner = new LP_Content_Scanner( $migrator->get_id_map(), $class::get_source_name() );
            $scan_result = $scanner->scan_and_replace();
        }
    }
}
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Migrate to LinkPilot', 'linkpilot' ); ?></h1>

    <?php if ( $migration_result ) : ?>
        <div class="notice notice-success">
            <p><strong><?php printf( esc_html__( 'Migration from %s complete!', 'linkpilot' ), esc_html( $migration_result['source'] ) ); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php printf( esc_html__( '%d links imported', 'linkpilot' ), $migration_result['links'] ); ?></li>
                <li><?php printf( esc_html__( '%d categories created', 'linkpilot' ), $migration_result['categories'] ); ?></li>
                <li><?php printf( esc_html__( '%d click records imported', 'linkpilot' ), $migration_result['clicks'] ); ?></li>
                <?php if ( $migration_result['skipped'] ) : ?>
                    <li><?php printf( esc_html__( '%d links skipped (already exist or no URL)', 'linkpilot' ), $migration_result['skipped'] ); ?></li>
                <?php endif; ?>
                <?php if ( $migration_result['errors'] ) : ?>
                    <li style="color: #d63638;"><?php printf( esc_html__( '%d errors occurred', 'linkpilot' ), $migration_result['errors'] ); ?></li>
                <?php endif; ?>
            </ul>
        </div>

        <?php if ( $scan_result ) : ?>
            <div class="notice notice-info">
                <p><?php printf(
                    esc_html__( 'Content scan: %d posts scanned, %d posts updated with new link URLs.', 'linkpilot' ),
                    $scan_result['posts_scanned'],
                    $scan_result['replacements']
                ); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( empty( $available ) ) : ?>
        <div style="max-width: 600px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; margin-top: 20px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'No plugins detected', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'LinkPilot can migrate links from ThirstyAffiliates, Pretty Links, LinkCentral, and Easy Affiliate Links. No compatible data was found on this site.', 'linkpilot' ); ?></p>
            <p><?php esc_html_e( 'If you recently uninstalled a plugin, its data may have been removed. You can also import links via CSV from the Import/Export page.', 'linkpilot' ); ?></p>
        </div>
    <?php else : ?>
        <p><?php esc_html_e( 'LinkPilot detected link data from the following plugins. Migration is non-destructive — your original data is preserved.', 'linkpilot' ); ?></p>

        <?php foreach ( $available as $key => $info ) : ?>
            <div style="max-width: 600px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; margin-top: 15px;">
                <h2 style="margin-top: 0;"><?php echo esc_html( $info['name'] ); ?></h2>
                <p><?php printf( esc_html__( '%d links found.', 'linkpilot' ), $info['count'] ); ?></p>

                <form method="post">
                    <?php wp_nonce_field( 'lp_run_migration' ); ?>
                    <input type="hidden" name="lp_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <p>
                        <label>
                            <input type="checkbox" name="lp_scan_content" value="1" checked />
                            <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'linkpilot' ); ?>
                        </label>
                    </p>
                    <?php submit_button(
                        sprintf( __( 'Migrate from %s', 'linkpilot' ), $info['name'] ),
                        'primary',
                        'submit',
                        false
                    ); ?>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
