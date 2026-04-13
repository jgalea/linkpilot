(function($) {
    'use strict';

    $(document).on('tinymce-editor-setup', function(event, editor) {
        editor.addButton('linkpilot', {
            title: 'Insert LinkPilot Link',
            icon: 'link',
            onclick: function() {
                openLinkPicker(editor);
            }
        });
    });

    function openLinkPicker(editor) {
        var $modal = $('<div class="lp-picker-modal"><div class="lp-picker-inner">' +
            '<h2>Insert LinkPilot Link</h2>' +
            '<input type="text" class="lp-picker-search" placeholder="Search links&hellip;" autofocus />' +
            '<div class="lp-picker-results"><p>Type to search your links.</p></div>' +
            '<div class="lp-picker-footer"><button class="button lp-picker-close">Cancel</button></div>' +
            '</div></div>');

        $('body').append($modal);

        $modal.on('click', '.lp-picker-close', function() { $modal.remove(); });
        $modal.on('click', function(e) { if (e.target === this) $modal.remove(); });
        $(document).on('keydown.lp-picker', function(e) {
            if (e.key === 'Escape') { $modal.remove(); $(document).off('keydown.lp-picker'); }
        });

        $modal.on('input', '.lp-picker-search', function() {
            var search = $(this).val();
            if (search.length < 2) {
                $modal.find('.lp-picker-results').empty();
                return;
            }
            $.get(lpEditor.ajaxUrl, {
                action: 'lp_search_links',
                nonce: lpEditor.nonce,
                search: search
            }, function(response) {
                if (!response.success) return;
                var html = '';
                response.data.forEach(function(item) {
                    html += '<div class="lp-picker-item" data-url="' + item.url + '" data-title="' + item.title + '">' +
                        '<strong>' + item.title + '</strong> <code>' + item.url + '</code></div>';
                });
                $modal.find('.lp-picker-results').html(html || '<p>No links found.</p>');
            });
        });

        $modal.on('click', '.lp-picker-item', function() {
            var url = $(this).data('url');
            var title = $(this).data('title');
            var selected = editor.selection.getContent() || title;
            editor.insertContent('<a href="' + url + '">' + selected + '</a>');
            $modal.remove();
        });
    }

})(jQuery);
