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
        );
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Migrate to LinkPilot', 'linkpilot' ); ?></h1>

    <?php if ( empty( $available ) ) : ?>
        <div style="max-width: 600px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; margin-top: 20px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'No plugins detected', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'LinkPilot can migrate links from ThirstyAffiliates, Pretty Links, LinkCentral, and Easy Affiliate Links. No compatible data was found on this site.', 'linkpilot' ); ?></p>
            <p><?php esc_html_e( 'You can also import links via CSV from the Import/Export page.', 'linkpilot' ); ?></p>
        </div>
    <?php else : ?>
        <p><?php esc_html_e( 'LinkPilot detected link data from the following plugins. Migration is non-destructive — your original data is preserved.', 'linkpilot' ); ?></p>

        <?php foreach ( $available as $key => $info ) : ?>
            <div class="lp-migrator-card" data-source="<?php echo esc_attr( $key ); ?>"
                 style="max-width: 700px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; margin-top: 15px;">
                <h2 style="margin-top: 0;"><?php echo esc_html( $info['name'] ); ?></h2>
                <p><?php printf( esc_html__( '%s links found.', 'linkpilot' ), '<strong>' . esc_html( number_format_i18n( $info['count'] ) ) . '</strong>' ); ?></p>

                <p>
                    <label>
                        <input type="checkbox" class="lp-scan-content" checked />
                        <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'linkpilot' ); ?>
                    </label>
                </p>

                <p>
                    <button type="button" class="button button-primary lp-start-migration">
                        <?php printf( esc_html__( 'Migrate from %s', 'linkpilot' ), esc_html( $info['name'] ) ); ?>
                    </button>
                </p>

                <div class="lp-migration-progress" id="lp-progress-<?php echo esc_attr( $key ); ?>"></div>
                <div class="lp-scan-progress" id="lp-scan-<?php echo esc_attr( $key ); ?>"></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.lp-start-migration');
        if (!btn) return;

        var card = btn.closest('.lp-migrator-card');
        var source = card.getAttribute('data-source');
        var doScan = card.querySelector('.lp-scan-content').checked;

        btn.disabled = true;
        btn.textContent = '<?php echo esc_js( __( 'Migration in progress…', 'linkpilot' ) ); ?>';

        LPJobRunner.start({
            action: 'lp_job_migrate',
            params: { source: source },
            containerId: 'lp-progress-' + source,
            label: '<?php echo esc_js( __( 'Migrating links', 'linkpilot' ) ); ?>',
            onDone: function (data) {
                if (!doScan) {
                    btn.textContent = '<?php echo esc_js( __( 'Done', 'linkpilot' ) ); ?>';
                    return;
                }
                LPJobRunner.start({
                    action: 'lp_job_scan',
                    params: { source: source, id_map: data.id_map },
                    containerId: 'lp-scan-' + source,
                    label: '<?php echo esc_js( __( 'Scanning posts for old shortcodes/links', 'linkpilot' ) ); ?>',
                    onDone: function () {
                        btn.textContent = '<?php echo esc_js( __( 'Done', 'linkpilot' ) ); ?>';
                    }
                });
            }
        });
    });
})();
</script>
