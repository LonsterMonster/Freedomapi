(function(){
    function buildApiUrl(endpoint, key, params){
        var base = window.location.origin + '/wp-json/platform/v1/api/' + encodeURIComponent(endpoint);
        var query = new URLSearchParams();

        query.set('api_key', key || '');

        if (params) {
            params.split('&').forEach(function(pair){
                var parts = pair.split('=');
                if (!parts[0]) {
                    return;
                }
                query.set(parts[0], parts.slice(1).join('='));
            });
        }

        return base + '?' + query.toString();
    }

    function initApiTest(){
        var form = document.getElementById('apiTestForm');
        var output = document.getElementById('apiResponse');

        if (!form || !output) {
            return;
        }

        form.addEventListener('submit', async function(e){
            e.preventDefault();

            var endpoint = document.getElementById('endpoint').value;
            var key = document.getElementById('api_key').value;
            var params = document.getElementById('params').value;
            var url = buildApiUrl(endpoint, key, params);

            output.textContent = 'Loading...';

            try {
                var res = await fetch(url);
                var text = await res.text();

                try {
                    output.textContent = JSON.stringify(JSON.parse(text), null, 2);
                } catch (jsonError) {
                    output.textContent = text;
                }
            } catch (requestError) {
                output.textContent = 'Request failed';
            }
        });

        var copyBtn = document.getElementById('copyResponse');
        if (copyBtn && navigator.clipboard) {
            copyBtn.addEventListener('click', function(){
                navigator.clipboard.writeText(output.textContent || '');
            });
        }
    }

    window.replayRequest = function(endpoint){
        var endpointInput = document.getElementById('endpoint');
        var form = document.getElementById('apiTestForm');

        if (!endpointInput || !form) {
            return;
        }

        endpointInput.value = endpoint;
        form.dispatchEvent(new Event('submit'));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initApiTest);
    } else {
        initApiTest();
    }
})();
