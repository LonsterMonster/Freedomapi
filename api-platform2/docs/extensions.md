# FreedomAPI extension interface 1.0.0

The existing plugin remains API Platform2 (FreedomAPI Core). This additive contract does not rename its plugin slug or alter its existing management save handlers. It is loaded by `modules/helpers.php`. Extensions should initialize after `plugins_loaded` priority 25 and require a compatible interface major version, rather than assume a particular Core release number.

## Management functions

All IDs refer to Core `user_api` posts. Management functions use the **current authenticated WordPress user**, require both `apis.edit` and `apis.manage_schema`, and validate the post type before checking ownership. Caller HTTP handlers must additionally enforce CSRF protection and explicit approval. Failures return `WP_Error` with an HTTP `status` in error data.

| Function | Result |
| --- | --- |
| `freedomapi_extension_version()` | Semantic interface version `1.0.0` |
| `freedomapi_can_manage_api($api_id)` | Boolean permission check, including organization permissions |
| `freedomapi_get_api_configuration($api_id)` | Source object, base endpoint schema, runtime type, active transformation/schema, revision, opaque fingerprint and rollback availability |
| `freedomapi_validate_transformation($plan)` | `true` or `WP_Error`; validates the declarative grammar and limits |
| `freedomapi_preview_transformation($api_id, $plan, $fingerprint)` | Original/proposed JSON, inferred output schema, plan and revision, without writes or upstream execution |
| `freedomapi_update_api_configuration($api_id, $plan, $fingerprint)` | Commit a reviewed proposal, returning new revision and rollback availability |
| `freedomapi_rollback_api_configuration($api_id, $fingerprint)` | Restore the preceding transformation/schema; revisions always increase |
| `freedomapi_execute_transformation($source, $plan)` | Pure bounded interpreter; never runs code or makes HTTP calls |
| `freedomapi_transform_response($api_id, $body, $version, $status = 200)` | Runtime application plus enforced output validation; original body when inactive/out of scope |
| `freedomapi_validate_response_schema($value, $schema)` | Boolean validation for the bounded inferred JSON Schema subset |

Names beginning `freedomapi_extension_` other than the version function are internal implementation helpers, not extension storage APIs. Extensions must never write the private `_freedomapi_extension_configuration` post metadata.

## Hooks

- `do_action('freedomapi_extensions_ready', $interface_version)` at `plugins_loaded` priority 25.
- `apply_filters('freedomapi_api_management_sections', $html, $api_id, $user_id)` inside the authorized existing Edit API page. Append escaped HTML; independently check edit/schema permission. The hook runs outside existing forms.
- `apply_filters('freedomapi_endpoint_schema', $schema, $api_id)` on effective schema reads. Trusted PHP extensions only; no automatic permission grant.
- `do_action('freedomapi_api_configuration_changed', $api_id, $revision, $event, $actor_user_id)` after a successful commit; events are `approved` and `rolled_back`. No credentials or response bodies are passed.

## Declarative grammar

```json
{"version":1,"operations":[{"op":"move","path":["full_name"],"from":["name"],"value_json":"null"},{"op":"set","path":["active"],"from":[],"value_json":"true"}]}
```

Operations are sequential and always start with the raw response. A new plan replaces the old plan; it does not stack. `path`/`from` are arrays of object property names, not JSON Pointer strings or array indexes. `set` can insert/replace a property with a literal decoded from `value_json`; `remove` requires an existing property; `copy` and `move` require an existing source. All destination parents must exist. Missing data fails closed. No eval, callbacks, expressions, templates, filesystem access, network requests or wildcards are supported.

Limits: 32 operations, 32 KiB plan, 12 path levels, 128-byte property names, 256 KiB source/output, 12 schema nesting levels, 128 properties per object and 32 distinct item schemas per array. Root must be an object. JSON `{}` and `[]` are preserved.

## Response contracts and lifecycle

Approval infers a bounded JSON Schema output contract from the proposed object. Every property is required; unexpected properties fail. Arrays allow the item shapes observed in the preview; empty arrays are unconstrained. This deliberately replaces the HTTP 200 response contract for every endpoint/method of the API's approved version; request and non-200 contracts are unchanged. The effective endpoint schema exposes `freedomapi_output_schema` and the proposed example; response validation enforces it regardless of the legacy validation mode. OpenAPI 3.1 exports use this contract. Base schema metadata is never overwritten, so rollback to baseline restores legacy behavior.

Core applies the plan after retrieving/storing the **raw** cache body and before validating/logging the response. Cache keys include the extension revision; approval and rollback therefore invalidate prior cached representations. Content length and validators are removed after a body change. AI deactivation does not disable approved transformations; explicitly roll back first to remove them.

Core stores a JSON-encoded state record with its previous 10 configurations, actor, timestamp and increasing revision. A unique option lock serializes extension commits; the metadata update uses a previous-value comparison. Fingerprints include the base schema, source and revision and are rechecked under the lock. Existing unrelated Core writers do not participate in that lock; a later ordinary Core edit can make an active transformation fail validation and require regeneration or rollback. A hard process termination can leave `freedomapi_extension_lock_<api_id>` behind; an operator may delete that option after verifying no writer is running. Normal exceptions release it in `finally`.

Unsupported component-only or non-object APIs return a clear preview error. Proxy APIs preview a documented example, never a fetched upstream response. Previews cannot establish correctness for every future upstream response.
