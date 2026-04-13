(function() {
    'use strict';

    if (typeof lpLinkFixer === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function() {
        var links = document.querySelectorAll('a[href*="' + lpLinkFixer.linkPrefix + '"]');
        if (!links.length) return;

        var urls = [];
        links.forEach(function(link) {
            var href = link.getAttribute('href');
            if (href && urls.indexOf(href) === -1) {
                urls.push(href);
            }
        });

        if (!urls.length) return;

        var formData = new FormData();
        formData.append('action', 'lp_link_fixer');
        formData.append('nonce', lpLinkFixer.nonce);
        urls.forEach(function(url) {
            formData.append('urls[]', url);
        });

        fetch(lpLinkFixer.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(response) {
            if (!response.success || !response.data) return;

            var fixData = response.data;
            links.forEach(function(link) {
                var href = link.getAttribute('href');
                var fix = fixData[href];
                if (!fix) return;

                if (fix.href && fix.href !== href) {
                    link.setAttribute('href', fix.href);
                }
                if (fix.rel) {
                    link.setAttribute('rel', fix.rel);
                }
                if (fix.target) {
                    link.setAttribute('target', fix.target);
                }
                if (fix['class']) {
                    fix['class'].split(' ').forEach(function(cls) {
                        if (cls) link.classList.add(cls);
                    });
                }
            });
        })
        .catch(function() {
            // Silently fail
        });
    });
})();
