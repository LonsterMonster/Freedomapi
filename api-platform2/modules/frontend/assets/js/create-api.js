(function(){
    function slugify(value){
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function initCreateApi(){
        var source = document.querySelector('[data-apiplatform-endpoint-source]');
        var target = document.querySelector('[data-apiplatform-endpoint-target]');

        if (!source || !target) {
            return;
        }

        source.addEventListener('input', function(){
            if (target.dataset.userEdited === '1') {
                return;
            }

            target.value = slugify(source.value);
        });

        target.addEventListener('input', function(){
            target.dataset.userEdited = '1';
            target.value = slugify(target.value);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCreateApi);
    } else {
        initCreateApi();
    }
})();
