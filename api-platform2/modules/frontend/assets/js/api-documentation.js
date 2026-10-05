(function () {
    function setActiveTab(tabs, panels, key) {
        tabs.forEach(function (tab) {
            var active = tab.getAttribute('data-doc-tab') === key;
            tab.classList.toggle('is-active', active);
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-doc-panel') === key);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.apiplatform-doc-tabs').forEach(function (tabsRoot) {
            var tabs = Array.prototype.slice.call(tabsRoot.querySelectorAll('[data-doc-tab]'));
            var panels = Array.prototype.slice.call(tabsRoot.querySelectorAll('[data-doc-panel]'));

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    setActiveTab(tabs, panels, tab.getAttribute('data-doc-tab'));
                });
            });
        });
    });
})();
