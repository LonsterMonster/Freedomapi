(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.apiplatform-request-history');

        if (!root || root.getAttribute('data-request-history-ready') === '1') {
            return;
        }

        root.setAttribute('data-request-history-ready', '1');

        root.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-history-toggle]');

            if (toggle && root.contains(toggle)) {
                var target = document.getElementById(toggle.getAttribute('data-history-toggle'));

                if (!target) {
                    return;
                }

                var active = target.classList.toggle('is-open');
                toggle.textContent = active ? 'Close' : 'Inspect';
                toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
                return;
            }

            var filterLink = event.target.closest('[data-history-filter-url]');

            if (!filterLink || !root.contains(filterLink)) {
                return;
            }

            var url = filterLink.getAttribute('data-history-filter-url') || filterLink.getAttribute('href');

            if (!url) {
                return;
            }

            event.preventDefault();
            window.location.assign(url);
        });
    });
})();
