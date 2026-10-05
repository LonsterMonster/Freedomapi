(function () {
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[role="tab"]');

        if (tab) {
            activateTab(tab);
            return;
        }

        var rawButton = event.target.closest('[data-json-raw]');
        if (rawButton) {
            toggleRawJson(rawButton);
            return;
        }

        var downloadButton = event.target.closest('[data-json-download]');
        if (downloadButton) {
            downloadJson(downloadButton);
        }
    });

    document.addEventListener('keydown', function (event) {
        var tab = event.target.closest('[role="tab"]');

        if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) !== -1) {
            moveTab(event, tab);
            return;
        }

        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.apiplatform-portal-code-block button').forEach(function (button) {
            button.blur();
        });
    });

    if ('IntersectionObserver' in window) {
        var toc = document.querySelector('.apiplatform-portal-toc');
        var sections = document.querySelectorAll('[data-doc-section]');

        if (toc && sections.length) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    toc.querySelectorAll('a').forEach(function (link) {
                        link.classList.toggle('is-active', link.getAttribute('href') === '#' + entry.target.id);
                    });
                });
            }, {
                rootMargin: '-20% 0px -65% 0px',
                threshold: 0.01
            });

            sections.forEach(function (section) {
                observer.observe(section);
            });
        }
    }

    document.addEventListener('input', function (event) {
        var input = event.target.closest('[data-portal-doc-search]');

        if (!input) {
            return;
        }

        searchDocs(input);
    });

    function activateTab(tab) {
        var tabs = tab.closest('[data-portal-doc-tabs]');
        var panelId = tab.getAttribute('aria-controls');

        if (!tabs || !panelId) {
            return;
        }

        tabs.querySelectorAll('[role="tab"]').forEach(function (button) {
            var active = button === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });

        tabs.querySelectorAll('[role="tabpanel"]').forEach(function (panel) {
            var active = panel.id === panelId;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
    }

    function moveTab(event, current) {
        var group = current.closest('[data-portal-doc-tabs]');
        var tabs = group ? Array.prototype.slice.call(group.querySelectorAll('[role="tab"]')) : [];
        var index = tabs.indexOf(current);
        var next = index;

        if (index === -1) {
            return;
        }

        event.preventDefault();
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        tabs[next].focus();
        activateTab(tabs[next]);
    }

    function searchDocs(input) {
        var root = input.closest('.apiplatform-portal-docs');
        var query = normalize(input.value);
        var terms = query.split(' ').filter(Boolean);
        var matches = 0;

        if (!root) {
            return;
        }

        root.querySelectorAll('[data-doc-section]').forEach(function (section) {
            clearHighlights(section);

            var haystack = normalize(section.textContent || '');
            var visible = terms.length === 0 || terms.every(function (term) {
                return haystack.indexOf(term) !== -1 || fuzzyMatch(haystack, term);
            });

            section.hidden = !visible;
            if (visible) {
                matches++;
                terms.slice(0, 4).forEach(function (term) {
                    highlightTerm(section, term);
                });
            }
        });

        var meta = root.querySelector('[data-portal-search-meta]');
        if (meta) {
            meta.textContent = terms.length ? matches + ' matching section' + (matches === 1 ? '' : 's') : '';
        }
    }

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9_./ -]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function fuzzyMatch(value, term) {
        var position = 0;
        for (var i = 0; i < value.length && position < term.length; i++) {
            if (value.charAt(i) === term.charAt(position)) {
                position++;
            }
        }
        return term.length > 2 && position === term.length;
    }

    function clearHighlights(root) {
        root.querySelectorAll('mark[data-portal-search-mark]').forEach(function (mark) {
            mark.replaceWith(document.createTextNode(mark.textContent || ''));
        });
    }

    function highlightTerm(root, term) {
        if (!term || term.length < 2) {
            return;
        }

        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || node.parentElement.closest('script, style, code, pre, textarea')) {
                    return NodeFilter.FILTER_REJECT;
                }
                return normalize(node.nodeValue).indexOf(term) !== -1 ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        var nodes = [];
        var node;

        while ((node = walker.nextNode())) {
            nodes.push(node);
        }

        nodes.slice(0, 20).forEach(function (textNode) {
            var value = textNode.nodeValue;
            var index = value.toLowerCase().indexOf(term);
            if (index === -1) return;
            var mark = document.createElement('mark');
            mark.setAttribute('data-portal-search-mark', '1');
            mark.textContent = value.slice(index, index + term.length);
            var fragment = document.createDocumentFragment();
            fragment.appendChild(document.createTextNode(value.slice(0, index)));
            fragment.appendChild(mark);
            fragment.appendChild(document.createTextNode(value.slice(index + term.length)));
            textNode.replaceWith(fragment);
        });
    }

    function toggleRawJson(button) {
        var block = button.closest('[data-json-viewer]');
        var code = block ? block.querySelector('code') : null;
        if (!code) {
            return;
        }
        var raw = code.getAttribute('data-json-raw-value') || code.textContent || '';
        if (!code.getAttribute('data-json-raw-value')) {
            code.setAttribute('data-json-raw-value', raw);
        }
        if (button.getAttribute('aria-pressed') === 'true') {
            code.textContent = prettyJson(raw);
            button.setAttribute('aria-pressed', 'false');
            button.textContent = 'Raw View';
        } else {
            code.textContent = raw.replace(/\s+/g, ' ').trim();
            button.setAttribute('aria-pressed', 'true');
            button.textContent = 'Pretty View';
        }
    }

    function downloadJson(button) {
        var block = button.closest('[data-json-viewer]');
        var code = block ? block.querySelector('code') : null;
        var value = code ? (code.getAttribute('data-json-raw-value') || code.textContent || '') : '';
        var filename = button.getAttribute('data-json-download') || 'example.json';

        if (!value) {
            return;
        }

        var link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([prettyJson(value)], { type: 'application/json' }));
        link.download = filename;
        link.click();
        window.setTimeout(function () {
            URL.revokeObjectURL(link.href);
        }, 1000);
    }

    function prettyJson(value) {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch (error) {
            return value;
        }
    }
})();
