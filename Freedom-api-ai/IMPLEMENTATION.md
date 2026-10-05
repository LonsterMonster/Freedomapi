# FreedomAPI AI implementation

## Inspection and proposed interface (before implementation)

Core is `../api-platform2` (plugin header API Platform2 3.2). The AI workspace was empty. No AGENTS.md files or existing test runner were found.

Existing boundaries:
- Ownership: `APIPlatform_Ownership_Service::can`, `apiplatform_api_can`, permissions `apis.edit` and `apis.manage_schema`.
- Schema: `APIPlatform_Endpoint_Schema_Service::current_schema`, `schema_for_version`, `save_schema`, `save_models`, `normalize_schema`.
- Validation: that service's `validate_request` / `validate_response`; response validation can be disabled, so extension previews need unconditional validation.
- JSON runtime: `APIPlatform_Runtime::internal` prioritizes `api_json`, then `apiplatform_execute_logic`, then the component renderer. Gateway caches raw responses before response validation.
- Configuration writes: private frontend `handle_save` and builder `save_legacy_compatible_logic`; no suitable atomic public extension writer exists.
- UI: `APIPlatform_Frontend_Edit_API::render` assembles the existing management page after ownership checks.

Proposed contract: `freedomapi_extension_version`, `freedomapi_can_manage_api`, `freedomapi_get_api_configuration`, `freedomapi_preview_transformation`, `freedomapi_update_api_configuration`, `freedomapi_rollback_api_configuration`, and `freedomapi_transform_response`. Hook names: `freedomapi_extensions_ready`, `freedomapi_api_management_sections`, `freedomapi_api_configuration_changed`. All management functions authenticate the current WordPress user and return WP_Error on failure. Core owns storage and a bounded, non-executable JSON transformation format. Changes use revision checks and retain rollback history. AI owns only encrypted user connections and short-lived proposals.

Phases: (1) Core contract/interpreter and tests; (2) AI bootstrap, encrypted connections and HTTP client and tests; (3) management UI, proposal approval/rollback and integration tests. No live AI calls are required for automated tests.

## Implemented and verified

1. Core public contract, bounded interpreter, inferred output validation, optimistic updates and rollback: 24 assertions passed before the connection phase. Added runtime/schema integration: 13 assertions for actual Core runtime/cache/OpenAPI classes with mocked WordPress storage. The effective HTTP 200 response contract is overlaid, leaving base/request/error schema metadata intact.
2. User-bound AES-256-GCM encryption and Responses Structured Outputs client: 23 assertions passed before the UI phase. Credentials are never returned, provider errors are sanitized, and tests use no real credentials.
3. REST permissions/nonce checks, server-retained proposals, previews, explicit approval, expiry, stale detection and rollback: 24 assertions. Dependency tests: 4 missing-Core, 4 incompatible-Core and 4 unavailable-OpenSSL assertions.

Final automated total: 96 passing assertions, PHP lint on all changed PHP modules and AI files, JavaScript syntax validation, and clean Core `git diff --check`. Browser fixture verified the original/proposed view, disabled Apply until checked, successful approval, and rollback. Browser fixture used synthetic data and mocked OpenAI; a live WordPress database/theme and paid OpenAI request were not available and remain staging checks.

Additional correctness fixes from review: cache generation changes on approval/rollback; raw cached responses are transformed once; active runtime JSON decoding preserves empty objects versus arrays; Core stores a single slashed JSON state string to preserve escaped content; OpenAPI 3.1 exports the approved output schema and example.
