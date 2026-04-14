/**
 * LinkPilot Job Runner — chunked AJAX progress UI.
 */
(function (window, document) {
    'use strict';

    if (typeof window.lpJobRunner === 'undefined') {
        return;
    }

    var settings = window.lpJobRunner;

    function uuid() {
        return 'job-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    function el(tag, attrs, text) {
        var n = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        }
        if (text !== undefined && text !== null) {
            n.textContent = String(text);
        }
        return n;
    }

    function renderUI(container, label) {
        while (container.firstChild) container.removeChild(container.firstChild);
        container.classList.add('lp-progress');
        container.style.cssText = 'background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px;margin:12px 0;';

        var title = el('div', { 'class': 'lp-progress-label' }, label);
        title.style.cssText = 'font-weight:600;margin-bottom:8px;';

        var status = el('div', { 'class': 'lp-progress-status' }, 'Starting…');
        status.style.cssText = 'font-size:13px;color:#555;margin-bottom:8px;';

        var trackWrap = el('div');
        trackWrap.style.cssText = 'background:#e5e5e5;border-radius:3px;height:18px;overflow:hidden;';
        var bar = el('div', { 'class': 'lp-progress-bar' });
        bar.style.cssText = 'background:#2271b1;height:100%;width:0%;transition:width 0.3s ease;';
        trackWrap.appendChild(bar);

        var summary = el('div', { 'class': 'lp-progress-summary' });
        summary.style.cssText = 'font-size:13px;color:#555;margin-top:8px;';

        container.appendChild(title);
        container.appendChild(status);
        container.appendChild(trackWrap);
        container.appendChild(summary);

        return { status: status, bar: bar, summary: summary };
    }

    function postJSON(action, params) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', settings.nonce);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === null || typeof v === 'undefined') return;
            if (typeof v === 'object') v = JSON.stringify(v);
            body.append(k, v);
        });

        return fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        }).then(function (r) { return r.json(); });
    }

    var LABELS = {
        links:      'Links imported',
        categories: 'Categories created',
        skipped:    'Skipped (already existed)',
        errors:     'Errors',
        healthy:    'Healthy',
        broken:     'Broken (4xx)',
        server_error: 'Server error (5xx)',
        error:      'Unreachable',
        no_url:     'No URL',
        unknown:    'Unknown',
    };

    var HIDDEN = ['clicks'];

    function summarize(data) {
        if (!data || !data.results || typeof data.results !== 'object') return '';
        var parts = [];
        Object.keys(data.results).forEach(function (k) {
            if (HIDDEN.indexOf(k) !== -1) return;
            var v = data.results[k];
            if (v === 0 && (k === 'errors' || k === 'skipped')) return;
            var label = LABELS[k] || k;
            parts.push(label + ': ' + v);
        });
        return parts.join(' · ');
    }

    function start(opts) {
        var container = document.getElementById(opts.containerId);
        if (!container) return;

        var ui = renderUI(container, opts.label || 'Working…');
        var jobId = uuid();

        function tick() {
            var params = Object.assign({ job_id: jobId }, opts.params || {});

            postJSON(opts.action, params).then(function (resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Request failed';
                    ui.status.textContent = 'Error: ' + msg;
                    ui.bar.style.background = '#dc3232';
                    if (typeof opts.onError === 'function') opts.onError(resp);
                    return;
                }

                var d = resp.data;
                var pct = d.total > 0 ? Math.min(100, Math.round(d.processed / d.total * 100)) : 0;

                ui.bar.style.width = pct + '%';
                ui.status.textContent = d.processed + ' of ' + d.total + ' (' + pct + '%)';
                ui.summary.textContent = summarize(d);

                if (typeof opts.onChunk === 'function') opts.onChunk(d);

                if (d.done) {
                    ui.bar.style.background = '#46b450';
                    ui.status.textContent = 'Complete · ' + d.processed + ' of ' + d.total;
                    if (typeof opts.onDone === 'function') opts.onDone(d);
                } else {
                    setTimeout(tick, 100);
                }
            }).catch(function (err) {
                ui.status.textContent = 'Error: ' + (err && err.message ? err.message : 'Network error');
                ui.bar.style.background = '#dc3232';
                if (typeof opts.onError === 'function') opts.onError(err);
            });
        }

        tick();
    }

    window.LPJobRunner = { start: start, postJSON: postJSON };
})(window, document);
