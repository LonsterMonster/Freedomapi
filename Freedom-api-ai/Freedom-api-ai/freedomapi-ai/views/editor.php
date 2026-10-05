<?php if (!defined('ABSPATH')) exit; ?>
<section class="freedomapi-ai" data-api-url="<?php echo esc_url(rest_url('freedomapi-ai/v1/apis/' . absint($api_id) . '/')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" aria-label="AI Schema Editor">
    <h2>AI Schema Editor</h2>
    <p>Describe a JSON response change, compare the preview, and approve it when ready. Approved changes run locally in Core.</p>
    <p class="fai-status" role="status" aria-live="polite">Loading editor…</p>
    <details class="fai-connection"><summary>Your OpenAI connection</summary>
        <p class="fai-connection-status"></p>
        <form class="fai-connection-form">
            <label>OpenAI API key <input name="key" type="password" autocomplete="new-password" required placeholder="sk-…" maxlength="503"></label>
            <label>Model ID <input name="model" required value="gpt-4.1-mini" maxlength="100"></label>
            <p>Your key is encrypted on this site. It is never shown again. Requests use your OpenAI account and may incur charges.</p>
            <button type="submit">Save connection</button>
            <button type="button" class="fai-disconnect">Disconnect</button>
        </form>
    </details>
    <div class="fai-workspace" hidden>
        <details><summary>Source JSON sent to OpenAI</summary><pre class="fai-source" tabindex="0"></pre></details>
        <details><summary>Base Core endpoint schema</summary><pre class="fai-current-schema" tabindex="0"></pre></details>
        <details class="fai-active-details" hidden><summary>Current approved transformation and output schema</summary><pre class="fai-active" tabindex="0"></pre></details>
        <p>Changes apply to HTTP 200 JSON object responses for this API’s current version, across its methods and routes. Supported changes: set, remove, copy, or rename object properties, including nested properties. Array-item transformations are not supported.</p>
        <p class="fai-runtime-note"></p>
        <form class="fai-generate-form">
            <label>What should change?<textarea name="instruction" rows="3" maxlength="4000" required placeholder="Rename name to full_name and add an active flag set to true."></textarea></label>
            <label class="fai-check"><input name="consent" type="checkbox" required> Send the source JSON, current transformation, and my instruction to OpenAI using my connection.</label>
            <button type="submit">Generate preview</button>
        </form>
        <div class="fai-preview" hidden>
            <div class="fai-comparison">
                <div><h3>Original response</h3><pre class="fai-original" tabindex="0"></pre></div>
                <div><h3>Proposed response</h3><pre class="fai-proposed" tabindex="0"></pre></div>
            </div>
            <details><summary>Proposed declarative transformation</summary><pre class="fai-plan" tabindex="0"></pre></details>
            <details open><summary>Proposed output schema</summary><pre class="fai-schema" tabindex="0"></pre></details>
            <p>The proposed JSON passes Core’s transformation and schema checks. This output contract replaces the response contract for HTTP 200 in this version; requests and error responses keep their existing contracts. Every preview field is required. Unexpected fields or types will fail at runtime. Empty arrays have unconstrained item types. A preview does not prove all future upstream responses will match.</p>
            <label class="fai-check"><input class="fai-approval" type="checkbox"> I reviewed the JSON and schema and approve applying this change.</label>
            <button type="button" class="fai-apply" disabled>Approve and apply</button>
        </div>
        <div class="fai-rollback" hidden>
            <h3>Rollback</h3>
            <p>Restore the previous approved transformation and output schema. Up to 10 prior configurations are retained.</p>
            <label class="fai-check"><input class="fai-rollback-approval" type="checkbox"> Restore the previous configuration.</label>
            <button type="button" class="fai-rollback-button" disabled>Roll back</button>
        </div>
        <button type="button" class="fai-reload">Reload configuration</button>
    </div>
</section>
