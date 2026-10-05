(function () {
    'use strict';

    var docs = document.querySelector('.apiplatform-docs');
    if (!docs) { return; }

    var links = Array.prototype.slice.call(
        docs.querySelectorAll('.apiplatform-docs-nav a[href^="#"]')
    );
    var targets = links
        .map(function (link) {
            var id = link.getAttribute('href').slice(1);
            var target = document.getElementById(id);
            return target ? { id: id, link: link, target: target } : null;
        })
        .filter(Boolean);

    if (!links.length || !targets.length) {
        return;
    }

    function setActiveLink(activeId) {
        links.forEach(function (link) {
            var isActive = link.getAttribute('href') === '#' + activeId;
            link.classList.toggle('is-active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function currentVisibleTarget() {
        var active = targets[0];
        var viewportAnchor = Math.max(120, window.innerHeight * 0.22);
        targets.forEach(function (item) {
            var rect = item.target.getBoundingClientRect();
            if (rect.top <= viewportAnchor && rect.bottom > viewportAnchor) {
                active = item;
            } else if (rect.top <= viewportAnchor) {
                active = item;
            }
        });
        return active;
    }

    function updateFromScrollPosition() {
        setActiveLink(currentVisibleTarget().id);
    }

    function scrollToTarget(event) {
        var id = event.currentTarget.getAttribute('href').slice(1);
        var target = document.getElementById(id);
        if (!target) { return; }
        event.preventDefault();
        setActiveLink(id);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (window.history && window.history.pushState) {
            window.history.pushState(null, '', '#' + id);
        }
    }

    links.forEach(function (link) {
        link.addEventListener('click', scrollToTarget);
    });

    if ('IntersectionObserver' in window) {
        var visible = {};
        var observer = new IntersectionObserver(function (entries) {
            var active;
            entries.forEach(function (entry) {
                visible[entry.target.id] = entry.isIntersecting;
            });
            active = targets
                .filter(function (item) { return visible[item.id]; })
                .sort(function (a, b) {
                    return Math.abs(a.target.getBoundingClientRect().top) -
                        Math.abs(b.target.getBoundingClientRect().top);
                })[0];
            setActiveLink((active || currentVisibleTarget()).id);
        }, {
            root: null,
            rootMargin: '-18% 0px -68% 0px',
            threshold: [0, 0.1, 0.4, 0.8]
        });
        targets.forEach(function (item) {
            observer.observe(item.target);
        });
    } else {
        window.addEventListener('scroll', updateFromScrollPosition, { passive: true });
        window.addEventListener('resize', updateFromScrollPosition);
    }
    updateFromScrollPosition();
}());
