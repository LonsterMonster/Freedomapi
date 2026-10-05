(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var root = document.querySelector('.apiplatform-api-tester');

        if (!root || typeof APIPlatformTester === 'undefined') {
            return;
        }

        var form = document.getElementById('apiplatform-api-tester-form');
        var method = document.getElementById('apiplatform-tester-method');
        var apiKey = document.getElementById('apiplatform-tester-api-key');
        var toggleKey = document.getElementById('apiplatform-toggle-test-key');
        var body = document.getElementById('apiplatform-tester-body');
        var send = document.getElementById('apiplatform-send-test-request');
        var error = document.getElementById('apiplatform-api-tester-error');
        var status = document.getElementById('apiplatform-test-status');
        var time = document.getElementById('apiplatform-test-time');
        var headersOutput = document.getElementById('apiplatform-test-headers');
        var bodyOutput = document.getElementById('apiplatform-test-body');
        var copyResponse = document.querySelector('.apiplatform-copy-response');
        var copyCurl = document.querySelector('.apiplatform-copy-curl');

        seedFromSchema();
        syncBodyState();
        updateCurl();

        root.addEventListener('click', function(event){
            var addType = event.target.getAttribute('data-add-row');

            if (addType) {
                addRow(addType);
                return;
            }

            if (event.target.classList.contains('apiplatform-remove-row')) {
                var row = event.target.closest('.apiplatform-api-tester-row');

                if (row) {
                    row.remove();
                    updateCurl();
                }
            }
        });

        root.addEventListener('input', updateCurl);
        method.addEventListener('change', function(){
            syncBodyState();
            updateCurl();
        });

        toggleKey.addEventListener('click', function(){
            var showing = apiKey.type === 'text';
            apiKey.type = showing ? 'password' : 'text';
            toggleKey.textContent = showing ? 'Show' : 'Hide';
        });

        form.addEventListener('submit', function(event){
            event.preventDefault();
            clearError();

            var payload = buildPayload();

            if (methodAllowsBody(method.value) && body.value.trim() !== '') {
                try {
                    JSON.parse(body.value);
                } catch (parseError) {
                    showError('Request body must be valid JSON.');
                    return;
                }
            }

            setLoading(true);

            var data = new FormData();
            data.append('action', 'apiplatform_test_api');
            data.append('api_id', String(APIPlatformTester.apiId || ''));
            data.append('nonce', APIPlatformTester.nonce || '');
            data.append('method', method.value || 'GET');
            data.append('api_key', apiKey.value || '');
            data.append('payload', JSON.stringify(payload));

            fetch(APIPlatformTester.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function(response){
                return response.json();
            }).then(function(json){
                if (!json || !json.success) {
                    showError(json && json.data && json.data.message ? json.data.message : 'Request failed.');
                    return;
                }

                renderResult(json.data);
            }).catch(function(){
                showError('Request failed. Please check the API key and try again.');
            }).finally(function(){
                setLoading(false);
            });
        });

        function addRow(type, keyValue, valueValue){
            var rows = root.querySelector('[data-rows="' + type + '"]');

            if (!rows) {
                return;
            }

            var row = document.createElement('div');
            row.className = 'apiplatform-api-tester-row';
            row.innerHTML = [
                '<input class="apiplatform-input" type="text" data-pair-key="' + type + '" placeholder="Key">',
                '<input class="apiplatform-input" type="text" data-pair-value="' + type + '" placeholder="Value">',
                '<button type="button" class="apiplatform-button apiplatform-button-secondary apiplatform-remove-row">Remove</button>'
            ].join('');
            rows.appendChild(row);

            var key = row.querySelector('[data-pair-key="' + type + '"]');
            var value = row.querySelector('[data-pair-value="' + type + '"]');

            if (key && keyValue) {
                key.value = keyValue;
            }

            if (value && valueValue) {
                value.value = valueValue;
            }
        }

        function seedFromSchema(){
            var schema = APIPlatformTester.schema || {};
            var endpoint = schema.endpoints && schema.endpoints[0] ? schema.endpoints[0] : null;

            if (!endpoint) {
                addRow('query');
                addRow('header');
                return;
            }

            var seeded = {query: false, header: false};

            (endpoint.parameters || []).forEach(function(parameter){
                var location = String(parameter.location || 'query').toLowerCase();

                if (location !== 'query' && location !== 'header') {
                    return;
                }

                addRow(location, parameter.name || '', parameter.example || parameter.default || '');
                seeded[location] = true;
            });

            if (!seeded.query) {
                addRow('query');
            }

            if (!seeded.header) {
                addRow('header');
            }

            if (body && endpoint.request_body && endpoint.request_body.enabled) {
                body.value = JSON.stringify(endpoint.request_body.example || exampleFromFields(endpoint.request_body.fields || []), null, 2);
            }
        }

        function exampleFromFields(fields){
            var example = {};

            fields.forEach(function(field){
                if (!field.name) {
                    return;
                }

                example[field.name] = field.example || typeExample(field.type || 'string');
            });

            return example;
        }

        function typeExample(type){
            if (type === 'integer') return 123;
            if (type === 'number') return 12.3;
            if (type === 'boolean') return true;
            if (type === 'array') return [];
            if (type === 'object') return {};
            if (type === 'email') return 'user@example.com';
            if (type === 'url') return 'https://example.com';
            if (type === 'uuid') return '00000000-0000-4000-8000-000000000000';
            return 'string';
        }

        function buildPayload(){
            return {
                query_params: collectPairs('query'),
                headers: collectPairs('header'),
                body: methodAllowsBody(method.value) ? body.value : ''
            };
        }

        function collectPairs(type){
            var pairs = [];
            var rows = root.querySelectorAll('[data-rows="' + type + '"] .apiplatform-api-tester-row');

            rows.forEach(function(row){
                var key = row.querySelector('[data-pair-key="' + type + '"]');
                var value = row.querySelector('[data-pair-value="' + type + '"]');

                if (key && key.value.trim() !== '') {
                    pairs.push({
                        key: key.value.trim(),
                        value: value ? value.value : ''
                    });
                }
            });

            return pairs;
        }

        function syncBodyState(){
            var allowed = methodAllowsBody(method.value);
            body.disabled = !allowed;
            body.placeholder = allowed ? '{\n  "example": true\n}' : 'Request body is disabled for this method';
        }

        function methodAllowsBody(value){
            return ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(String(value).toUpperCase()) !== -1;
        }

        function renderResult(result){
            var responseText = formatValue(result.body);
            var headerText = formatValue(result.headers || {});

            status.textContent = String(result.status || '') + (result.statusText ? ' ' + result.statusText : '');
            time.textContent = String(result.responseTimeMs || 0) + ' ms';
            headersOutput.textContent = headerText;
            bodyOutput.textContent = responseText;

            if (copyResponse) {
                copyResponse.dataset.copyValue = responseText;
                copyResponse.value = responseText;
            }

            updateCurl();
        }

        function formatValue(value){
            if (typeof value === 'string') {
                try {
                    return JSON.stringify(JSON.parse(value), null, 2);
                } catch (error) {
                    return value;
                }
            }

            return JSON.stringify(value, null, 2);
        }

        function updateCurl(){
            if (!copyCurl) {
                return;
            }

            var url = APIPlatformTester.endpoint || '';
            var query = collectPairs('query');

            if (query.length) {
                var params = new URLSearchParams();

                query.forEach(function(pair){
                    params.append(pair.key, pair.value);
                });

                url += (url.indexOf('?') === -1 ? '?' : '&') + params.toString();
            }

            var command = ['curl', '-X', shellQuote(method.value || 'GET'), shellQuote(url), '-H', shellQuote('X-API-Key: YOUR_API_KEY')];

            collectPairs('header').forEach(function(pair){
                if (!isSensitiveHeader(pair.key)) {
                    command.push('-H', shellQuote(pair.key + ': ' + pair.value));
                }
            });

            if (methodAllowsBody(method.value) && body.value.trim() !== '') {
                command.push('-H', shellQuote('Content-Type: application/json'));
                command.push('--data', shellQuote(body.value.trim()));
            }

            var curl = command.join(' ');
            copyCurl.dataset.copyValue = curl;
            copyCurl.value = curl;
        }

        function shellQuote(value){
            return '"' + String(value).replace(/(["\\])/g, '\\$1') + '"';
        }

        function isSensitiveHeader(key){
            return ['authorization', 'x-api-key', 'cookie', 'set-cookie'].indexOf(String(key).toLowerCase()) !== -1;
        }

        function setLoading(active){
            send.disabled = active;
            send.textContent = active ? 'Sending...' : 'Send Request';
        }

        function showError(message){
            error.textContent = message;
            error.classList.add('is-visible');
        }

        function clearError(){
            error.textContent = '';
            error.classList.remove('is-visible');
        }
    });
})();
