(function () {
    document.addEventListener('input', function (event) {
        var input = event.target.closest('[data-doc-workspace-search]');

        if (!input) {
            return;
        }

        var root = input.closest('.apiplatform-publisher-panel');
        var query = input.value.trim().toLowerCase();

        if (!root) {
            return;
        }

        root.querySelectorAll('[data-doc-workspace-section]').forEach(function (section) {
            var haystack = section.textContent.toLowerCase();
            section.hidden = query !== '' && haystack.indexOf(query) === -1;
        });
    });
})();
