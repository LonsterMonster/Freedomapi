(() => {
    'use strict';
    document.querySelectorAll('.freedomapi-ai').forEach((root) => {
        const find = (selector) => root.querySelector(selector);
        let config = null;
        let proposal = null;
        let busy = false;
        const status = (message, error = false) => {
            find('.fai-status').textContent = message;
            find('.fai-status').classList.toggle('fai-error', error);
        };
        const json = (selector, value) => { find(selector).textContent = JSON.stringify(value, null, 2); };
        const controls = () => {
            root.querySelectorAll('button').forEach((button) => { button.disabled = busy; });
            find('.fai-apply').disabled = busy || !proposal || !find('.fai-approval').checked;
            find('.fai-rollback-button').disabled = busy || !config?.can_rollback || !find('.fai-rollback-approval').checked;
        };
        const request = async (route, method = 'GET', data) => {
            const response = await fetch(root.dataset.apiUrl + route, {
                method, credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-WP-Nonce': root.dataset.nonce, 'Content-Type': 'application/json' },
                body: data === undefined ? undefined : JSON.stringify(data),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Request failed. Reload and try again.');
            return result;
        };
        const connection = (value) => {
            find('.fai-connection-status').textContent = value.connected
                ? `Saved key ending in ${value.last4}. Model: ${value.model}.` : 'No OpenAI connection saved.';
            find('[name=model]').value = value.model;
        };
        const clearPreview = () => {
            proposal = null;
            find('.fai-preview').hidden = true;
            find('.fai-approval').checked = false;
        };
        const load = async () => {
            clearPreview();
            config = await request('configuration');
            connection(config.connection);
            json('.fai-source', config.source);
            json('.fai-current-schema', config.schema);
            find('.fai-active-details').hidden = !config.active;
            if (config.active) json('.fai-active', { version: config.active.version_label, plan: config.active.plan, schema: config.active.schema });
            find('.fai-workspace').hidden = false;
            find('.fai-rollback').hidden = !config.can_rollback;
            find('.fai-rollback-approval').checked = false;
            find('.fai-runtime-note').textContent = config.runtime === 'internal'
                ? 'Preview uses the saved response. Each new proposal replaces the active transformation.'
                : 'Preview uses a documented example. Review your upstream response variations before approving.';
        };
        const run = async (task) => {
            if (busy) return;
            busy = true; controls();
            try { await task(); }
            catch (error) { status(error.message || 'Request failed.', true); }
            finally { busy = false; controls(); }
        };
        find('.fai-connection-form').addEventListener('submit', (event) => {
            event.preventDefault();
            run(async () => {
                const field = find('[name=key]');
                try {
                    connection(await request('connection', 'POST', { key: field.value, model: find('[name=model]').value.trim() }));
                    status('Connection encrypted and saved. It will be verified when you generate a preview.');
                } finally { field.value = ''; }
            });
        });
        find('.fai-disconnect').addEventListener('click', () => run(async () => {
            connection(await request('connection', 'DELETE', {}));
            status('Your OpenAI connection was removed.');
        }));
        find('.fai-generate-form').addEventListener('submit', (event) => {
            event.preventDefault();
            run(async () => {
                clearPreview();
                status('Generating a proposal. No configuration changes have been made…');
                proposal = await request('proposals', 'POST', {
                    instruction: find('[name=instruction]').value,
                    consent: find('[name=consent]').checked, fingerprint: config.fingerprint,
                });
                json('.fai-original', proposal.original);
                json('.fai-proposed', proposal.proposed);
                json('.fai-plan', proposal.plan);
                json('.fai-schema', proposal.output_schema);
                find('.fai-preview').hidden = false;
                status('Preview validated. Review and approve within 15 minutes to apply it.');
            });
        });
        find('.fai-approval').addEventListener('change', controls);
        find('.fai-rollback-approval').addEventListener('change', controls);
        find('.fai-apply').addEventListener('click', () => run(async () => {
            if (!proposal || !find('.fai-approval').checked) return;
            await request('approve', 'POST', { proposal_id: proposal.proposal_id, approved: true });
            await load(); status('Approved transformation and schema are now active.');
        }));
        find('.fai-rollback-button').addEventListener('click', () => run(async () => {
            if (!find('.fai-rollback-approval').checked) return;
            await request('rollback', 'POST', { fingerprint: config.fingerprint, approved: true });
            await load(); status('Previous configuration restored.');
        }));
        find('.fai-reload').addEventListener('click', () => run(async () => { await load(); status('Configuration reloaded.'); }));
        run(async () => { await load(); status('Ready. Generate a preview to propose a change.'); });
    });
})();
