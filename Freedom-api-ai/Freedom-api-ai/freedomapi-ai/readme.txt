=== FreedomAPI AI ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Per-user OpenAI connections and reviewed JSON transformations for FreedomAPI Core.

== Dependencies ==
Requires the updated API Platform2 / FreedomAPI Core plugin exposing extension interface 1.x and OpenSSL AES-256-GCM. The editor is added to Core's existing Edit API page. Missing dependencies produce an admin notice; no AI routes are registered.

== External service ==
When a user explicitly clicks Generate and consents, this plugin sends the shown source JSON, current declarative plan and natural-language instruction to https://api.openai.com/v1/responses using that user's own OpenAI API key. Requests use store:false and may incur charges on the user's OpenAI account. No endpoint traffic is sent to OpenAI.
OpenAI terms: https://openai.com/policies/terms-of-use/
OpenAI privacy policy: https://openai.com/policies/privacy-policy/

== Data ==
API keys are encrypted in private user metadata using AES-256-GCM and a user-bound authentication tag. They are never displayed after saving. Disconnect removes the current user's key. Proposals expire after 15 minutes. Core retains up to 10 rollback configurations. Uninstall intentionally retains encrypted keys; disconnect before uninstalling to remove them. Roll back before disabling AI if you want to remove approved transformations, which continue to run in Core.

== Limits ==
Version 1 transforms JSON object responses with bounded set/remove/copy/move operations. Arrays can be literal values but individual array-item changes are unsupported. Approval replaces the HTTP 200 response contract across the API's approved version. Every preview property is required; future responses must match the inferred contract. Core schema permissions and explicit approval are required.
