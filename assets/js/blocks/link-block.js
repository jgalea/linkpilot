(function(wp) {
    'use strict';

    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var TextControl = wp.components.TextControl;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var RichText = wp.blockEditor.RichText;

    registerBlockType('linkpilot/link', {
        title: 'LinkPilot Link',
        icon: 'admin-links',
        category: 'common',
        attributes: {
            linkId: { type: 'number', default: 0 },
            linkUrl: { type: 'string', default: '' },
            linkTitle: { type: 'string', default: '' },
            text: { type: 'string', default: '' }
        },

        edit: function(props) {
            var attrs = props.attributes;
            var setAttrs = props.setAttributes;
            var _state = useState('');
            var search = _state[0];
            var setSearch = _state[1];
            var _results = useState([]);
            var results = _results[0];
            var setResults = _results[1];
            var _loading = useState(false);
            var loading = _loading[0];
            var setLoading = _loading[1];

            useEffect(function() {
                if (search.length < 2) {
                    setResults([]);
                    return;
                }
                setLoading(true);
                var url = lpEditor.ajaxUrl + '?action=lp_search_links&nonce=' + lpEditor.nonce + '&search=' + encodeURIComponent(search);
                fetch(url, { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        setResults(data.success ? data.data : []);
                        setLoading(false);
                    });
            }, [search]);

            if (attrs.linkId) {
                return el('div', { className: 'lp-block-selected' },
                    el('span', { className: 'lp-block-label' }, 'LinkPilot: '),
                    el('strong', null, attrs.linkTitle),
                    el('br'),
                    el(RichText, {
                        tagName: 'span',
                        value: attrs.text || attrs.linkTitle,
                        onChange: function(val) { setAttrs({ text: val }); },
                        placeholder: 'Link text...'
                    }),
                    el(Button, {
                        isSmall: true,
                        isDestructive: true,
                        onClick: function() { setAttrs({ linkId: 0, linkUrl: '', linkTitle: '' }); },
                        style: { marginLeft: '8px' }
                    }, 'Change')
                );
            }

            return el('div', { className: 'lp-block-picker' },
                el(TextControl, {
                    label: 'Search LinkPilot Links',
                    value: search,
                    onChange: setSearch,
                    placeholder: 'Start typing...'
                }),
                loading && el(Spinner),
                results.length > 0 && el('ul', { className: 'lp-block-results' },
                    results.map(function(item) {
                        return el('li', { key: item.id },
                            el(Button, {
                                onClick: function() {
                                    setAttrs({
                                        linkId: item.id,
                                        linkUrl: item.url,
                                        linkTitle: item.title,
                                        text: item.title
                                    });
                                    setSearch('');
                                    setResults([]);
                                }
                            }, item.title, el('small', { style: { marginLeft: '8px', opacity: 0.6 } }, item.url))
                        );
                    })
                )
            );
        },

        save: function(props) {
            var attrs = props.attributes;
            if (!attrs.linkUrl) return null;

            return el('a', {
                href: attrs.linkUrl,
                className: 'lp-link',
                'data-lp-id': attrs.linkId
            }, el(RichText.Content, { value: attrs.text || attrs.linkTitle }));
        }
    });
})(window.wp);
