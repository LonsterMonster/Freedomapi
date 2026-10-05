(function () {
    var quickStartStorageKey = 'apiplatform.portal.quickstart.language';

    document.addEventListener('DOMContentLoaded', function () {
        var tabGroups = document.querySelectorAll('.apiplatform-portal-quickstart-code');

        tabGroups.forEach(function (group) {
            var tabs = Array.prototype.slice.call(group.querySelectorAll('[data-portal-quickstart-tab]'));
            var stored = readQuickStartTab();
            var initial = tabs.find(function (tab) {
                return tab.getAttribute('data-portal-quickstart-tab') === stored;
            }) || tabs[0];

            if (initial) {
                activateQuickStartTab(group, initial, false);
            }

            group.addEventListener('click', function (event) {
                var tab = event.target.closest('[data-portal-quickstart-tab]');

                if (tab) {
                    activateQuickStartTab(group, tab, true);
                }
            });

            group.addEventListener('keydown', function (event) {
                var current = event.target.closest('[data-portal-quickstart-tab]');

                if (!current || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) {
                    return;
                }

                event.preventDefault();

                var index = tabs.indexOf(current);
                var nextIndex = index;

                if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') nextIndex = 0;
                if (event.key === 'End') nextIndex = tabs.length - 1;

                tabs[nextIndex].focus();
                activateQuickStartTab(group, tabs[nextIndex], true);
            });
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-portal-copy]');

        if (!button) {
            return;
        }

        var block = button.closest('.apiplatform-portal-code-block');
        var activePanel = block ? block.querySelector('.apiplatform-portal-quickstart-panel.is-active') : null;
        var code = activePanel ? activePanel.querySelector('code') : (block ? block.querySelector('code') : null);

        if (!code || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(code.textContent || '').then(function () {
            var original = button.textContent;
            button.textContent = 'Copied!';
            showPortalToast('Copied to clipboard.');
            window.setTimeout(function () {
                button.textContent = original;
            }, 1600);
        });
    });

    function activateQuickStartTab(group, tab, persist) {
        var target = tab.getAttribute('aria-controls');
        var tabs = group.querySelectorAll('[data-portal-quickstart-tab]');
        var panels = group.querySelectorAll('.apiplatform-portal-quickstart-panel');

        tabs.forEach(function (item) {
            var active = item === tab;
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.classList.toggle('is-active', active);
            item.tabIndex = active ? 0 : -1;
        });

        panels.forEach(function (panel) {
            var active = panel.id === target;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });

        if (persist) {
            writeQuickStartTab(tab.getAttribute('data-portal-quickstart-tab') || '');
        }
    }

    function readQuickStartTab() {
        try {
            return localStorage.getItem(quickStartStorageKey);
        } catch (error) {
            return '';
        }
    }

    function writeQuickStartTab(value) {
        try {
            localStorage.setItem(quickStartStorageKey, value);
        } catch (error) {}
    }

    function showPortalToast(message) {
        var toast = document.querySelector('.apiplatform-portal-toast');

        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'apiplatform-portal-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.classList.add('is-visible');

        window.clearTimeout(showPortalToast.timer);
        showPortalToast.timer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 1800);
    }
})();
