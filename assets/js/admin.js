(function($) {
    'use strict';

    /* Copy-to-clipboard for cloaked URL button */
    $(document).on('click', '.lp-copy-btn', function(e) {
        e.preventDefault();
        var $btn   = $(this);
        var $label = $btn.find('.lp-copy-label');
        var $code  = $( $btn.data('clipboard-target') );
        var text   = $code.length ? $code.text().trim() : '';

        if ( ! text ) {
            return;
        }

        navigator.clipboard.writeText(text).then(function() {
            var original = $label.text();
            $btn.addClass('lp-copied');
            $label.text('Copied!');
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');

            setTimeout(function() {
                $btn.removeClass('lp-copied');
                $label.text(original);
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        });
    });

    /* Click-to-copy on cloaked URL codes in list table */
    $(document).on('click', '.column-lp_cloaked_url code', function() {
        var $el  = $(this);
        var text = window.location.origin + $el.text().trim();

        navigator.clipboard.writeText(text).then(function() {
            $el.addClass('lp-code-copied');
            setTimeout(function() {
                $el.removeClass('lp-code-copied');
            }, 800);
        });
    });

})(jQuery);
