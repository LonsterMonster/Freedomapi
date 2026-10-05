# FreedomAPI AI

A separate WordPress plugin for the existing FreedomAPI Core / API Platform2 plugin. Core owns configuration, schema enforcement, response transformation and rollback. AI owns encrypted per-user OpenAI connections and temporary proposals. It never reads or writes Core database tables or private post metadata.

## Install

1. Deploy the updated Core plugin from `../api-platform2` (or the packaged `api-platform2-core-extensions-1.0.0.zip`). Its existing plugin entry point and version remain unchanged; the extension interface is independently versioned at `1.0.0`.
2. Install `dist/freedomapi-ai-1.0.0.zip`, or copy the `freedomapi-ai` folder into `wp-content/plugins/`, then activate it.
3. Use WordPress 6.5+, PHP 8.1+, and OpenSSL with AES-256-GCM. AI remains inactive and displays an admin notice if Core interface 1.x or encryption support is unavailable.
4. Open an API's existing **Edit API** management page. Under **AI Schema Editor**, save your own OpenAI key and a Responses/Structured Outputs-compatible model ID. The default is `gpt-4.1-mini`; model availability depends on your OpenAI account.
5. Enter a change, consent to sending the shown source JSON and instruction, generate a preview, review original/proposed JSON and the output schema, then check approval and apply. Proposals expire after 15 minutes. Use the separately approved rollback control to restore the preceding configuration.

The response editor supports JSON object roots and nested object-property set/remove/copy/move operations. Renaming is a move. It does not generate PHP or transform individual array items. New plans replace previous plans and execute locally in Core on HTTP 200 responses of the approved API version. The generated output schema replaces that version's success-response contract; every preview property is required, and unexpected fields/types fail at runtime. See [Core interface documentation](../api-platform2/docs/extensions.md) for exact scope and limits.

## Keys and requests

Keys are encrypted with AES-256-GCM, a fresh nonce, and user-bound authenticated data; only the last four characters are exposed. The encryption secret is derived from WordPress authentication salts by default. For a separate secret, define `FREEDOMAPI_AI_ENCRYPTION_KEY` in `wp-config.php` using a securely generated value with at least 32 random bytes. Keep it outside the database and backed up separately. Changing this secret or the fallback salts requires users to re-enter their keys. Disconnect deletes only the current user's connection.

Only clicking Generate makes an OpenAI request. It sends the source JSON, current plan and instruction to `https://api.openai.com/v1/responses` using the current user's key. No shared server key is used. Requests disable response storage (`store: false`), TLS verification remains enabled, redirects are disabled, and provider errors are not echoed or logged. OpenAI account charges and provider retention policies still apply. The key remains server-side. API credentials appearing inside your own response JSON are not automatically redacted: review the shown source before consenting.

The request format follows the official [OpenAI Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs). All model output is independently validated by Core. Endpoint traffic never contacts OpenAI. Disabling AI leaves approved transformations running in Core; roll back before deactivation to remove them.

## Development and testing

The [inspection and phase plan](IMPLEMENTATION.md) records the existing Core functions and proposed hooks before implementation. The [Core extension contract](../api-platform2/docs/extensions.md) describes public functions, permissions, hooks, data limits, persistence and recovery.

Run each phase independently:

```powershell
php tests/core.php
php tests/runtime.php
php tests/connections.php
php tests/workflow.php
php tests/dependency.php
php tests/dependency.php incompatible
```

Or run `tests/run.ps1 -Php <php executable> -CorePath <Core folder>`. Add `-ExtensionDir <PHP ext folder>` for a portable Windows PHP installation without an ini file. OpenSSL must be enabled for connection/workflow tests. `php -n tests/dependency.php no-crypto` tests graceful handling of missing encryption support on builds without statically linked OpenSSL.

Tests execute actual Core/AI PHP with a small WordPress API/storage mock and mocked HTTP responses. They cover permission checks, nonce validation, encrypted credentials, isolation/tampering, malicious model operations, preview-only generation, stale/expired proposals, explicit approval, rollback, cache behavior, escaped literals, JSON shapes, OpenAPI output, and missing/incompatible Core. They do not substitute for a live WordPress database/theme integration test. No real OpenAI key or paid request was used.

For the browser fixture, run `php -S 127.0.0.1:8097 tests/ui-router.php` with OpenSSL and a writable PHP session directory. This fixture uses synthetic data and a fake connection with mocked provider output. Never deploy `tests/`.

## Staging smoke test before production

- Install updated Core and AI in staging. Test a personal owner, an organization schema editor, a viewer, and an unrelated user.
- Verify missing/disabled Core and unavailable OpenSSL display notices without registering AI routes or the editor.
- With your key, generate one preview; confirm the endpoint is unchanged until approval, then call the REST and public gateway routes with cache both enabled and disabled.
- Confirm exported OpenAPI, success-response schema enforcement and rollback. Edit source JSON in another tab and confirm stale approval is rejected.
- Test real upstream examples for proxy APIs; sample-derived schemas are strict and cannot predict unseen optional fields or types.

Only AI connection user metadata and 15-minute proposal transients are owned by this plugin. Uninstall intentionally retains encrypted connections; disconnect first to delete them. Core rollback records remain Core-owned. A killed generation process can leave `freedomapi_ai_generation_<user_id>` locked; after confirming no generation is running, an operator can delete that option. Normal completion and exceptions release it automatically.
