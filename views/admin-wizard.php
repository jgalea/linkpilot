<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$competitors = LP_Setup_Wizard::detect_competitors();
?>

<div class="wrap" style="max-width: 720px;">
    <h1><?php esc_html_e( 'Welcome to LinkPilot', 'linkpilot' ); ?></h1>
    <p><?php esc_html_e( 'Let\'s get your link manager set up. This will only take a moment.', 'linkpilot' ); ?></p>

    <form id="lp-wizard-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'lp_wizard_save' ); ?>
        <input type="hidden" name="action" value="lp_wizard_save" />
        <input type="hidden" name="lp_skip_server_migration" value="1" />

        <?php if ( ! empty( $competitors ) ) : ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;"><?php esc_html_e( 'Migrate Your Links', 'linkpilot' ); ?></h2>
            <p><?php esc_html_e( 'We detected link data from another plugin. Want to import it?', 'linkpilot' ); ?></p>

            <?php foreach ( $competitors as $key => $info ) : ?>
                <label style="display: block; margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9; cursor: pointer;">
                    <input type="radio" name="lp_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <strong><?php echo esc_html( $info['name'] ); ?></strong>
                    — <?php printf( esc_html__( '%s links found', 'linkpilot' ), '<strong>' . esc_html( number_format_i18n( $info['count'] ) ) . '</strong>' ); ?>
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

        <p>
            <button type="submit" class="button button-primary button-large" id="lp-wizard-submit">
                <?php esc_html_e( 'Finish Setup', 'linkpilot' ); ?>
            </button>
        </p>

        <div id="lp-wizard-progress"></div>
        <div id="lp-wizard-scan-progress"></div>
        <div id="lp-wizard-scan-review" style="display:none;"></div>
        <div id="lp-wizard-scan-apply"></div>
    </form>

    <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lp_link' ) ); ?>"><?php esc_html_e( 'Skip setup', 'linkpilot' ); ?></a></p>
</div>

<script>
(function () {
    var form = document.getElementById('lp-wizard-form');
    var btn  = document.getElementById('lp-wizard-submit');

    function finalizeSetup() {
        btn.textContent = '<?php echo esc_js( __( 'Finishing…', 'linkpilot' ) ); ?>';
        form.submit();
    }

    form.addEventListener('submit', function (e) {
        var sourceInput = form.querySelector('input[name="lp_migrate_source"]:checked');
        var source = sourceInput ? sourceInput.value : '';

        if (!source) {
            return; // skip migration, normal form submit
        }

        e.preventDefault();
        btn.disabled = true;
        btn.textContent = '<?php echo esc_js( __( 'Working…', 'linkpilot' ) ); ?>';

        var doScan = form.querySelector('input[name="lp_scan_content"]').checked;

        LPJobRunner.start({
            action: 'lp_job_migrate',
            params: { source: source },
            containerId: 'lp-wizard-progress',
            label: '<?php echo esc_js( __( 'Migrating links', 'linkpilot' ) ); ?>',
            onDone: function (data) {
                if (!doScan) {
                    finalizeSetup();
                    return;
                }
                LPJobRunner.start({
                    action: 'lp_job_scan',
                    params: { source: source, id_map: data.id_map, dry_run: 1 },
                    containerId: 'lp-wizard-scan-progress',
                    label: '<?php echo esc_js( __( 'Previewing content changes (dry run)', 'linkpilot' ) ); ?>',
                    onDone: function (scanData) { showScanReview(source, scanData, data.id_map); }
                });
            }
        });
    });

    function showScanReview(source, data, idMap) {
        var container = document.getElementById('lp-wizard-scan-review');
        while (container.firstChild) container.removeChild(container.firstChild);
        container.style.cssText = 'background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;margin:12px 0;';

        var h = document.createElement('h3');
        h.style.marginTop = '0';
        h.textContent = '<?php echo esc_js( __( 'Review before applying', 'linkpilot' ) ); ?>';
        container.appendChild(h);

        var p = document.createElement('p');
        p.textContent = data.updated + ' <?php echo esc_js( __( 'posts would be updated', 'linkpilot' ) ); ?> · ' +
            data.replacements + ' <?php echo esc_js( __( 'replacements total', 'linkpilot' ) ); ?>.';
        container.appendChild(p);

        if (data.samples && data.samples.length > 0) {
            var lh = document.createElement('p');
            lh.style.cssText = 'font-weight:600;margin-bottom:4px;';
            lh.textContent = '<?php echo esc_js( __( 'Examples of affected posts:', 'linkpilot' ) ); ?>';
            container.appendChild(lh);
            var ul = document.createElement('ul');
            ul.style.cssText = 'margin:0 0 12px 20px;';
            data.samples.forEach(function (s) {
                var li = document.createElement('li');
                li.textContent = s.title + ' (' + s.replacements + ' replacement' + (s.replacements === 1 ? '' : 's') + ')';
                ul.appendChild(li);
            });
            container.appendChild(ul);
        }

        var note = document.createElement('p');
        note.style.cssText = 'font-size:12px;color:#555;';
        note.textContent = '<?php echo esc_js( __( 'Post revisions are kept automatically. You can roll back any post via Edit Post → Revisions.', 'linkpilot' ) ); ?>';
        container.appendChild(note);

        if (data.updated === 0) {
            container.style.display = 'block';
            setTimeout(finalizeSetup, 500);
            return;
        }

        var apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'button button-primary';
        apply.textContent = '<?php echo esc_js( __( 'Apply changes and finish', 'linkpilot' ) ); ?>';
        var skip = document.createElement('button');
        skip.type = 'button';
        skip.className = 'button';
        skip.style.marginLeft = '8px';
        skip.textContent = '<?php echo esc_js( __( 'Skip content update', 'linkpilot' ) ); ?>';
        container.appendChild(apply);
        container.appendChild(skip);
        container.style.display = 'block';

        apply.addEventListener('click', function () {
            apply.disabled = true;
            skip.remove();
            LPJobRunner.start({
                action: 'lp_job_scan',
                params: { source: source, id_map: idMap, dry_run: 0 },
                containerId: 'lp-wizard-scan-apply',
                label: '<?php echo esc_js( __( 'Applying changes', 'linkpilot' ) ); ?>',
                onDone: function () { finalizeSetup(); }
            });
        });
        skip.addEventListener('click', function () { finalizeSetup(); });
    }
})();
</script>
