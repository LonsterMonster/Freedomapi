(function(){
    'use strict';

    function getSidebar(){
        return document.getElementById('apiSidebar');
    }

    function getOverlay(){
        return document.getElementById('apiplatformSidebarOverlay');
    }

    function getButton(){
        return document.querySelector('.apiplatform-mobile-toggle');
    }

    window.toggleSidebar = function(btn){
        var sidebar = getSidebar();
        var overlay = getOverlay();
        var button = btn || getButton();

        if (!sidebar) {
            return;
        }

        var isOpen = sidebar.classList.toggle('open');
        localStorage.setItem('apiplatform_sidebar', isOpen ? 'open' : 'closed');

        if (overlay) {
            overlay.classList.toggle('show', isOpen);
        }

        if (button) {
            button.classList.toggle('active', isOpen);
        }
    };

    document.addEventListener('DOMContentLoaded', function(){
        var sidebar = getSidebar();
        var overlay = getOverlay();
        var button = getButton();

        if (!sidebar) {
            return;
        }

        var saved = localStorage.getItem('apiplatform_sidebar');
        var shouldOpen = saved === 'open' || (saved === null && window.innerWidth > 768);

        if (shouldOpen) {
            sidebar.classList.add('open');

            if (overlay) {
                overlay.classList.add('show');
            }

            if (button) {
                button.classList.add('active');
            }
        }

        if (overlay) {
            overlay.addEventListener('click', function(){
                sidebar.classList.remove('open');
                overlay.classList.remove('show');

                if (button) {
                    button.classList.remove('active');
                }

                localStorage.setItem('apiplatform_sidebar', 'closed');
            });
        }
    });
})();
