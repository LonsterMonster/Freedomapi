document.addEventListener('DOMContentLoaded', function(){
    var buttons = document.querySelectorAll('.apiplatform-copy-value, .apiplatform-copy-key');
    var debug = Boolean(window.APIPlatformKeysDebug);

    logCopy('Copy initialized. Buttons: ' + buttons.length);

    buttons.forEach(function(button){
        button.addEventListener('click', function(){
            var text = button.dataset.copyValue || button.value || '';
            var expected = button.dataset.copyValue || button.value || '';
            var displayed = getDisplayedValue(button) || expected;
            var successText = button.dataset.copySuccess || 'Copied';
            var original = button.textContent.trim() || 'Copy';

            if (!text || text !== expected || text !== displayed) {
                logCopy('Copy failure: displayed value mismatch');
                showFeedback(button, 'Copy failed', original, false);
                return;
            }

            copyText(text).then(function(copiedText){
                if (copiedText !== expected) {
                    logCopy('Copy failure: copied text mismatch');
                    showFeedback(button, 'Copy failed', original, false);
                    return;
                }

                logCopy('Copy success: ' + successText);
                showFeedback(button, successText, original, true);
            }).catch(function(error){
                logCopy('Copy failure: ' + (error && error.message ? error.message : 'unknown'));
                showFeedback(button, 'Copy failed', original, false);
            });
        });
    });

    function copyText(text){
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function(){
                return text;
            }).catch(function(){
                return fallbackCopyText(text);
            });
        }

        return fallbackCopyText(text);
    }

    function fallbackCopyText(text){
        return new Promise(function(resolve, reject){
            var textarea = document.createElement('textarea');

            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.className = 'apiplatform-copy-buffer';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                if (!document.execCommand('copy')) {
                    throw new Error('Fallback copy command failed');
                }

                resolve(text);
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(textarea);
            }
        });
    }

    function showFeedback(button, message, original, success){
        button.textContent = message;
        button.classList.toggle('is-copied', success);
        button.classList.toggle('has-copy-error', !success);

        window.setTimeout(function(){
            button.textContent = original;
            button.classList.remove('is-copied');
            button.classList.remove('has-copy-error');
        }, 1600);
    }

    function getDisplayedValue(button){
        var sourceId = button.dataset.copySource || '';

        if (!sourceId) {
            return '';
        }

        var source = document.getElementById(sourceId);

        if (!source) {
            return '';
        }

        if (typeof source.value === 'string') {
            return source.value;
        }

        return (source.textContent || '').trim();
    }

    function logCopy(message){
        if (debug && window.console) {
            window.console.log('[COPY ACTION] ' + message);
        }
    }
});
