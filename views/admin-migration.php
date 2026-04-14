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
                <div class="lp-scan-review" id="lp-scan-review-<?php echo esc_attr( $key ); ?>" style="display:none;"></div>
                <div class="lp-scan-apply-progress" id="lp-scan-apply-<?php echo esc_attr( $key ); ?>"></div>
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
                    params: { source: source, id_map: data.id_map, dry_run: 1 },
                    containerId: 'lp-scan-' + source,
                    label: '<?php echo esc_js( __( 'Previewing content changes (dry run)', 'linkpilot' ) ); ?>',
                    onDone: function (scanData) {
                        renderScanReview(source, scanData, data.id_map);
                    }
                });
            }
        });
    });

    function renderScanReview(source, data, idMap) {
        var container = document.getElementById('lp-scan-review-' + source);
        var btnApply = document.createElement('button');
        btnApply.type = 'button';
        btnApply.className = 'button button-primary';
        btnApply.textContent = '<?php echo esc_js( __( 'Apply changes to my posts', 'linkpilot' ) ); ?>';

        var btnSkip = document.createElement('button');
        btnSkip.type = 'button';
        btnSkip.className = 'button';
        btnSkip.style.marginLeft = '8px';
        btnSkip.textContent = '<?php echo esc_js( __( 'Skip content update', 'linkpilot' ) ); ?>';

        while (container.firstChild) container.removeChild(container.firstChild);
        container.style.cssText = 'background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;margin:12px 0;';

        var heading = document.createElement('h3');
        heading.style.marginTop = '0';
        heading.textContent = '<?php echo esc_js( __( 'Review before applying', 'linkpilot' ) ); ?>';
        container.appendChild(heading);

        var summary = document.createElement('p');
        summary.textContent = data.updated + ' <?php echo esc_js( __( 'posts would be updated', 'linkpilot' ) ); ?> · ' +
            data.replacements + ' <?php echo esc_js( __( 'replacements total', 'linkpilot' ) ); ?>.';
        container.appendChild(summary);

        if (data.samples && data.samples.length > 0) {
            var listHeading = document.createElement('p');
            listHeading.style.cssText = 'font-weight:600;margin-bottom:4px;';
            listHeading.textContent = '<?php echo esc_js( __( 'Examples of affected posts:', 'linkpilot' ) ); ?>';
            container.appendChild(listHeading);

            var ul = document.createElement('ul');
            ul.style.cssText = 'margin:0 0 12px 20px;';
            data.samples.forEach(function (s) {
                var li = document.createElement('li');
                li.textContent = s.title + ' (' + s.replacements + ' replacement' + (s.replacements === 1 ? '' : 's') + ')';
                ul.appendChild(li);
            });
            container.appendChild(ul);
        } else {
            var nothing = document.createElement('p');
            nothing.style.color = '#757575';
            nothing.textContent = '<?php echo esc_js( __( 'No changes needed — no old shortcodes or URLs found in your posts.', 'linkpilot' ) ); ?>';
            container.appendChild(nothing);
        }

        var note = document.createElement('p');
        note.style.cssText = 'font-size:12px;color:#555;';
        note.textContent = '<?php echo esc_js( __( 'Post revisions are kept automatically, so you can roll back any post later via Edit Post → Revisions.', 'linkpilot' ) ); ?>';
        container.appendChild(note);

        if (data.updated > 0) {
            container.appendChild(btnApply);
            container.appendChild(btnSkip);
        } else {
            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'button button-primary';
            ok.textContent = '<?php echo esc_js( __( 'Close', 'linkpilot' ) ); ?>';
            ok.addEventListener('click', function () { container.style.display = 'none'; });
            container.appendChild(ok);
        }

        container.style.display = 'block';

        btnApply.addEventListener('click', function () {
            btnApply.disabled = true;
            btnApply.textContent = '<?php echo esc_js( __( 'Applying…', 'linkpilot' ) ); ?>';
            btnSkip.remove();
            LPJobRunner.start({
                action: 'lp_job_scan',
                params: { source: source, id_map: idMap, dry_run: 0 },
                containerId: 'lp-scan-apply-' + source,
                label: '<?php echo esc_js( __( 'Applying changes', 'linkpilot' ) ); ?>',
                onDone: function () {
                    btnApply.textContent = '<?php echo esc_js( __( 'Done', 'linkpilot' ) ); ?>';
                }
            });
        });

        btnSkip.addEventListener('click', function () {
            container.style.display = 'none';
            var btn = document.querySelector('.lp-migrator-card[data-source="' + source + '"] .lp-start-migration');
            if (btn) btn.textContent = '<?php echo esc_js( __( 'Done (content update skipped)', 'linkpilot' ) ); ?>';
        });
    }
})();
</script>
