(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.apiplatform-portal-tester[data-portal-api-slug]');

        if (!root || !window.APIPlatformPortalTester) {
            return;
        }

        var form = root.querySelector('[data-portal-tester-form]');
        var endpoint = root.querySelector('#apiplatform-portal-tester-endpoint');
        var method = root.querySelector('#apiplatform-portal-tester-method');
        var apiKey = root.querySelector('#apiplatform-portal-tester-api-key');
        var toggleKey = root.querySelector('[data-portal-toggle-key]');
        var body = root.querySelector('#apiplatform-portal-tester-body');
        var send = root.querySelector('[data-portal-send-test]');
        var error = root.querySelector('.apiplatform-portal-tester-error');
        var status = root.querySelector('[data-portal-result-status]');
        var time = root.querySelector('[data-portal-result-time]');
        var requestId = root.querySelector('[data-portal-result-request-id]');
        var responseSize = root.querySelector('[data-portal-result-size]');
        var headersOutput = root.querySelector('[data-portal-result-headers]');
        var bodyOutput = root.querySelector('[data-portal-result-body]');
        var curlOutput = root.querySelector('[data-portal-result-curl]');
        var pending = false;

        syncBodyState();

        if (toggleKey && apiKey) {
            toggleKey.addEventListener('click', function () {
                var showing = apiKey.type === 'text';
                apiKey.type = showing ? 'password' : 'text';
                toggleKey.textContent = showing ? 'Show' : 'Hide';
            });
        }

        if (method) {
            method.addEventListener('change', syncBodyState);
        }

        if (endpoint) {
            endpoint.addEventListener('change', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('endpoint', endpoint.value || 'default-endpoint');
                window.location.href = url.toString();
            });
        }

        root.addEventListener('click', function (event) {
            var copyButton = event.target.closest('[data-portal-copy-result]');

            if (!copyButton) {
                return;
            }

            var target = copyButton.getAttribute('data-portal-copy-result') === 'curl' ? curlOutput : bodyOutput;
            var value = target ? target.textContent || '' : '';

            if (!value || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(value).then(function () {
                var original = copyButton.textContent;
                copyButton.textContent = 'Copied!';
                window.setTimeout(function () {
                    copyButton.textContent = original;
                }, 1600);
            });
        });

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (pending) {
                    return;
                }

                clearError();

                if (!apiKey || apiKey.value.trim() === '') {
                    showError('Enter an API key to test this endpoint.');
                    return;
                }

                if (methodAllowsBody(method.value) && body.value.trim() !== '') {
                    try {
                        JSON.parse(body.value);
                    } catch (parseError) {
                        showError('Request body must be valid JSON.');
                        return;
                    }
                }

                sendRequest();
            });
        }

        function sendRequest() {
            var data = new FormData(form);
            var payload = buildPayload();

            data.append('action', 'apiplatform_portal_test');
            data.append('portal_slug', root.getAttribute('data-portal-api-slug') || '');
            data.append('payload', JSON.stringify(payload));

            setLoading(true);

            fetch(window.APIPlatformPortalTester.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            }).then(function (json) {
                if (!json) {
                    showError('Request failed before a readable response was received.');
                    return;
                }

                if (!json.success) {
                    showError(json.data && json.data.message ? json.data.message : 'Request failed.');
                    return;
                }

                renderResult(json.data || {});
            }).catch(function () {
                showError('Request failed before a response was received.');
            }).finally(function () {
                setLoading(false);
            });
        }

        function buildPayload() {
            return {
                query_params: collectParams('query'),
                headers: collectParams('header'),
                body: methodAllowsBody(method.value) && body ? body.value : ''
            };
        }

        function collectParams(location) {
            var values = [];
            var fields = root.querySelectorAll('[data-portal-param="' + location + '"]');

            fields.forEach(function (field) {
                var key = field.getAttribute('data-portal-param-name') || '';

                if (key && field.value.trim() !== '') {
                    values.push({
                        key: key,
                        value: field.value
                    });
                }
            });

            return values;
        }

        function renderResult(result) {
            if (status) {
                status.textContent = String(result.status || '') + (result.statusText ? ' ' + result.statusText : '');
                status.className = Number(result.status || 0) >= 400 ? 'is-error' : 'is-success';
            }

            if (time) {
                time.textContent = String(result.responseTimeMs || 0) + ' ms';
            }

            if (requestId) {
                requestId.textContent = result.requestId || '-';
            }

            if (responseSize) {
                responseSize.textContent = formatBytes(Number(result.responseSize || 0));
            }

            if (headersOutput) {
                headersOutput.textContent = formatValue(result.headers || {});
            }

            if (bodyOutput) {
                bodyOutput.textContent = formatValue(result.body);
            }

            if (curlOutput) {
                curlOutput.textContent = result.curl || curlOutput.textContent || '';
            }
        }

        function formatValue(value) {
            if (typeof value === 'string') {
                try {
                    return JSON.stringify(JSON.parse(value), null, 2);
                } catch (error) {
                    return value;
                }
            }

            return JSON.stringify(value, null, 2);
        }

        function methodAllowsBody(value) {
            return ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(String(value).toUpperCase()) !== -1;
        }

        function formatBytes(bytes) {
            if (!bytes) {
                return '-';
            }

            if (bytes < 1024) {
                return String(bytes) + ' B';
            }

            return (bytes / 1024).toFixed(1) + ' KB';
        }

        function syncBodyState() {
            if (!method || !body) {
                return;
            }

            var allowed = methodAllowsBody(method.value);
            body.disabled = !allowed;
            body.placeholder = allowed ? 'Enter documented JSON body when this endpoint accepts one' : 'Request body is disabled for this method';
        }

        function setLoading(active) {
            pending = active;

            if (send) {
                send.disabled = active;
                send.textContent = active ? 'Sending...' : 'Send Request';
            }
        }

        function showError(message) {
            if (error) {
                error.textContent = message;
                error.classList.add('is-visible');
            }
        }

        function clearError() {
            if (error) {
                error.textContent = '';
                error.classList.remove('is-visible');
            }
        }
    });
})();
