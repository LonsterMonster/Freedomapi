# FreedomAPI Source Reference

**Repository source code is authoritative.** If this document, `Implemented.md`, or `Planned.md` conflicts with the repository, inspect the implementation and update documentation; do not modify correct production code merely to satisfy stale documentation.

For current capabilities, see [Implemented.md](Implemented.md). For future intent, see [Planned.md](Planned.md).

FreedomAPI is a WordPress plugin (`api-platform2.php`, version 3.2) for creating and operating personal and organization-owned APIs. Current code includes a public gateway and developer portal, API builder and editing surfaces, applications, organizations and invitations, membership-derived entitlements, schemas/models and documentation tools, versions/lifecycle features, logging/analytics, and modular external authentication.

## Instructions for Future Codex Sessions

Recommended startup order:

1. Read `Source.md`.
2. Read `Implemented.md`.
3. Read `Planned.md`.
4. Inspect the relevant actual source files.
5. Begin work.

Never rely solely on old chat history. Never assume a prior completion report overrides current source, planned functionality exists, or a runtime result exists unless it is explicitly documented with execution evidence.

## Repository Map and Bootstrap

| Location | Role |
|---|---|
| `api-platform2.php` | Plugin entry point; defines paths/version, loads helper compatibility layer and module loader, registers `user_api`, and installs database tables on activation. |
| `modules/helpers.php` | Ordered compatibility loader for focused helper files. Helpers are available before feature modules. |
| `modules/modules.php` | Reads `modules/*/module.json`; on `plugins_loaded` priority 20 loads enabled/autoloaded module bootstraps. |
| `modules/*/module.json` | Module metadata and bootstrap declaration. |
| `modules/core/` | Core, REST, realtime, and admin analytics bootstrapping. |
| `modules/frontend/` | Dashboard layout, shortcodes, user-facing API/application/organization pages, rendering, and assets. |
| `modules/helpers/` | Cross-cutting services: routes, storage, gateway, plans, organizations, apps, validation, documentation, and database installation. |
| `modules/armember/` | Membership panel and pricing display. |
| `modules/auth/` | External provider registry, Google provider, state/callback, identity linking, settings, and audit. |
| `modules/developer-portal/` | Public developer portal routes, publishing, docs, tester, SEO, and queries. |
| `modules/builder/` | Builder UI, API save/migration, response rendering, and components. |
| `modules/backend/`, `logs/`, `automations/` | WordPress-admin pages, logs, and current admin automation UI. |
| `modules/data/`, `templates/`, `assets/` | Data router/services, templates, and shared static assets. |

Load order matters: helpers load before modules; modules load through manifests after `plugins_loaded`; module bootstraps use `apiplatform_bootstrap_class_once()` where available to avoid duplicate instances. `APIPlatform_Routes` and gateway/developer-portal rewrite registration occur on `init`. The older `APIPlatform_Module_Loader` class exists, but the active entry-point path is `apiplatform_load_modules_once()` in `modules/modules.php`.

## Canonical Sources of Truth

### Plans, entitlements, and seat policy

`modules/armember/class-membership-panel.php` / `APIPlatform_Membership_Panel` is the absolute source for plan definitions, labels, limits, unlimited semantics, seat policy, and future plan-specific entitlements. Its current plans are `free`, `pro`, `premium`, and `admin` (Admin Access). Key accessors include `get_plan_definitions()`, `get_plan_definition()`, `get_plan_label()`, `get_plan_limit()`, `get_user_plan()`, `get_effective_user_plan()`, `get_user_limit()`, `seat_policy()`, and `seat_usage()`.

Do not create another plan map. `APIPlatform_Organization_Plans` in `modules/helpers/organization-plans.php` is explicitly a compatibility resolver that delegates to the membership panel.

Seat usage is calculated by `APIPlatform_Organization_Seats` in `modules/helpers/organizations.php`: active members plus valid pending invitations. A pending invitation is valid only while pending and unexpired. Acceptance passes the reservation into member creation so the reservation becomes an active membership without double counting. Revoked, declined, and expired invitations no longer count. Organization entitlement enforcement reaches this count through `APIPlatform_Organization_Entitlements::usage()` and `can_consume()`.

### Errors

`modules/helpers/error-codes.php` / `APIPlatform_Error_Codes` owns FAPI definitions and creates normalized `WP_Error` values via `wp_error()`. FAPI codes are application-level identifiers, not HTTP statuses. Example: FAPI-603 uses key `organization_seat_limit_reached` and HTTP 409. The normal organization flow is:

`condition → stable key → APIPlatform_Error_Codes → WP_Error/context → PRG transient notice or response → user`

### Ownership, organization permissions, and entitlements

`APIPlatform_Ownership_Service` in `modules/helpers/organizations.php` owns personal-versus-organization API ownership metadata and ownership transfer/access helpers. `post_author` remains in compatibility/fallback paths for older personal APIs, but it is not organization authorization authority.

`APIPlatform_Organization_Permissions` resolves organization roles and capabilities, with `manage_options` administrator override. Membership roles are defined by `APIPlatform_Organization_Membership_Service`; permission mappings live in the permission service. Do not duplicate the matrix.

Organization plan entitlement is owner-membership-derived: `APIPlatform_Organization_Plans::plan_owner_id()` finds the creator-owner or an active owner, then resolves that user's effective plan through the membership panel. `APIPlatform_Organization_Entitlements` is an alias of that resolver.

### Plan Simulation

`modules/helpers/plan-simulation.php` / `APIPlatform_Plan_Simulation` is administrator-only and stores only an enabled flag and selected plan in user meta, gated by an option and nonce-protected admin save. `get_user_plan()` returns the actual membership-derived plan; `get_effective_user_plan()` may return the selected simulated plan. Simulation does not modify ARMember membership, WordPress capabilities, roles, or ownership.

### Applications and authentication

`modules/helpers/applications.php` / `APIPlatform_Applications_Service` manages application records, access, application keys, events, approval, and ownership. Application ownership/access is distinct from API ownership.

External authentication is modular under `modules/auth/`: `APIPlatform_Auth_Providers` is the provider registry, `APIPlatform_Google_Auth_Provider` is the present provider, `APIPlatform_Auth_Controller` handles routes/callbacks, `APIPlatform_Auth_State` stores one-time PKCE state in transients, and `APIPlatform_External_Identity_Service` normalizes identities and supports login versus account linking. Provider settings control enablement and account/linking behavior.

### Gateway, documentation, and API operations

`modules/helpers/gateway.php` owns gateway rewrite routing, request/response objects, route resolution, runtime dispatch, and cache service. Public `/gateway/...` requests dispatch at an early `template_redirect` priority because they are machine responses, preventing later theme/page-builder frontend callbacks from running. Gateway authentication calls detailed API-key validation in `modules/helpers/keys.php`; it then applies request processing, rate/abuse checks, logging, and response handling. It supports publisher/API paths and organization-aware ownership resolution rather than treating an organization as a separate per-seat namespace.

Canonical supporting services include `APIPlatform_Endpoint_Schema_Service`, `APIPlatform_Documentation_Workspace`, `APIPlatform_OpenAPI_Service`, `APIPlatform_SDK_Generator_Service`, `APIPlatform_Lifecycle_Policy`, the builder save/migrator classes, and Developer Portal publishing/documentation classes. Reuse them for schema, model, validation, docs, OpenAPI, SDK, version, publishing, lifecycle, and runtime-settings work.

### API-key storage and authentication

`modules/helpers/keys.php` is the canonical published-API-key helper. New and regenerated keys are generated as `apk_live_` secrets, shown only through the protected one-time notice, and stored in `api_keys` post meta as records containing a password hash, mask, prefix, label, enabled state, creation timestamp, storage marker, and (for new/migrated records) `key_id`. Creator and last-used timestamps are not currently captured for published API keys. The separate `api_key` meta is a masked display/compatibility field for current records, not plaintext storage.

Validation is current-first: hashed `api_keys` records use `password_verify`, while raw historical records are compatibility candidates. A successful raw match is promoted in place to a verified hash while preserving status/expiry/scope metadata; if persistence fails, validation safely retains legacy compatibility. The first successful promotion is classified `legacy_promoted`; subsequent requests use `current`. A plaintext legacy `api_key` match is likewise promoted and then masked after hash verification. It returns safe classifications and never logs a credential value. Request History persists `auth_transport` from the canonical request auth context and renders it separately from auth type; historical rows without the field remain unrecorded. Response contracts are canonical endpoint `responses` from `APIPlatform_Endpoint_Schema_Service`; its bounded runtime validator selects the resolved HTTP status contract, respects JSON content types, and records safe validation metadata for Request History. Modes are off, log/observe, and enforce; no-schema APIs pass without validation. Transport extraction is centralized in `apiplatform_extract_presented_api_key()`, returning the credential and `authorization_bearer`, `x_api_key`, or `query` transport. Precedence is Bearer, X-API-Key, then query; conflicting different credentials are rejected, malformed Bearer is rejected, and Basic is not interpreted as a published key. Transport is separate from auth classification (`current`, `legacy_promoted`, `legacy`, `application`, `public`). Application credentials are distinct `fapi_app_...` custom-table records managed by `APIPlatform_Applications_Service` and are not published API keys.

Database version `1.8.2` invokes the idempotent `apiplatform_migrate_legacy_api_keys()` migration. It hashes safely recoverable plaintext historical `api_key` or raw `api_keys` entries, preserves compatible enabled/created/expiry/scope metadata, verifies an equivalent hash before replacing plaintext `api_key` with its mask, and never silently generates a replacement secret. Validation also promotes recoverable matches encountered after the versioned migration; only failed/unavailable promotion remains legacy compatibility. Key management uses canonical `apis.manage_keys` organization authorization; `post_author` is not organization key authority.

## Frontend and Public Routing

`APIPlatform_Frontend_Layout` dispatches dashboard content from `apipage` when present, otherwise from the page path for non-administrators; it renders registered shortcodes such as organization, application, API, and publisher views. Organization subviews use `org_view`. `APIPlatform_Routes` manages configurable prefixes and recognizes Dashboard, Developers, and Docs managed pages. The Developer Portal adds independent rewrite routes below `/developers/`; gateway routes are registered below its gateway prefix.

WordPress wrapper pages are a required deployment layer for standalone authenticated paths. The flow is:

`WordPress Page → [api_fulllayout_page page="identifier" size="full"] → APIPlatform_Frontend_Layout → existing renderer`

A renderer can exist while its standalone WordPress URL returns 404 because the wrapper page is missing. The canonical wrapper definitions are `APIPlatform_Frontend_Layout::wrapper_page_definitions()` and are checked from **API Platform → Settings → Frontend Pages**. WordPress page existence is separate from FreedomAPI authorization; the wrapper does not grant access.

| Wrapper page | Identifier |
|---|---|
| `/organizations` | `organizations` |
| `/applications` | `applications` |
| `/my-apis` | `my-apis` |
| `/logs` | `logs` |
| `/documentation-manager` | `documentation-manager` |
| `/versions` | `versions` |
| `/publisher-settings` | `publisher-settings` |
| `/docs` | `docs` |
| `/automations` | `automations` |

The administrator checker validates existence, published status, shortcode presence, and its `page` identifier. It reports Missing, Draft, Trashed, Wrong Shortcode, Wrong Page Identifier, or Duplicate/Conflict. It never repairs automatically; a nonce-protected `manage_options` Create Page action is available only for a missing allowlisted page.

## Important Data Flows

```text
ARMember/current-user plan
  → Membership Panel actual plan
  → optional Plan Simulation effective plan
  → personal or owner-derived organization entitlement
  → limit enforcement
```

```text
Authenticated user → organization membership → role/capability
  → ownership check → entitlement check → service action → event/log
```

```text
Active members + valid pending invitations
  → APIPlatform_Organization_Seats::used_count()
  → organization seat limit → allow or FAPI-603
```

```text
Gateway request → rewrite/route resolution → API resolution
  → API-key validation → rate/abuse controls → runtime processing
  → request log → response
```

## Storage Map

`user_api` is the primary API post type. The optional `documentation` post type is registered by the documentation system when enabled. Important database installation lives in `modules/helpers/database.php` and creates prefixed tables for logs, transactions, rules, portal/lifecycle data, applications and application keys/access/events, documentation snapshots/releases/events, external identities, organizations, organization members, organization invitations, organization API access, and organization events. The installer runs on activation and on a stored database-version mismatch; the current admin automations module does not independently invoke schema creation on each request.

WordPress options hold module settings, route prefixes, authentication/simulation configuration, and other system settings. User meta stores selected ownership context and plan-simulation state. Transients are used for short-lived PRG notices and external-auth state. API and organization metadata remain in post/meta and custom tables as implemented by their services. Do not document or store secrets/live values here.

## Legacy and Compatibility Boundaries

- `modules/helpers.php` is a compatibility loader after helper extraction into `modules/helpers/`.
- `APIPlatform_Organization_Plans` is a compatibility resolver; it does not own plan definitions.
- `post_author` and legacy ownership metadata are still fallback compatibility paths for older personal APIs.
- Gateway key validation retains legacy API-key compatibility helpers.
- The database includes organization plan-related compatibility/migration-era storage while current organization entitlements are owner-membership-derived.
- `APIPlatform_Module_Loader` remains in source alongside the active manifest loader.

Verify a boundary before calling it legacy; do not remove it merely because it looks old.

## Security Boundaries

Current actions use WordPress nonces, capability checks, ownership checks, organization permissions, and entitlement checks. API keys are validated through key helpers; one-time reveal behavior is handled by API-key/UI flows. Gateway applies authentication, rate/abuse processing, and request logging. External authentication uses expiring one-time state, PKCE data, safe redirects, provider settings, normalized identity records, and profile redaction of token-like fields. FAPI errors provide stable, safe user-facing context. Do not log secrets.

Automations and CLI security rules are future intent only; see `Planned.md`.

## Do Not Duplicate

| Concern | Canonical location |
|---|---|
| Plans / entitlements / seat policy | `modules/armember/class-membership-panel.php` |
| Organization seat counting | `APIPlatform_Organization_Seats` |
| FAPI errors | `modules/helpers/error-codes.php` |
| Ownership | `APIPlatform_Ownership_Service` |
| Organization permissions | `APIPlatform_Organization_Permissions` |
| Organization entitlements | `APIPlatform_Organization_Entitlements` |
| Plan simulation | `APIPlatform_Plan_Simulation` |
| Gateway | `modules/helpers/gateway.php` |
| External auth providers | `modules/auth/` provider registry |
| Applications | `APIPlatform_Applications_Service` |
| Docs/schema/OpenAPI/SDK | documentation workspace, endpoint schema, OpenAPI, SDK services |

Before adding a helper, map, permission table, plan definition, error registry, gateway, or auth system, search for the existing canonical service.

## Documentation Lifecycle

### Phase start

Read all three root documents, inspect relevant source, classify the work (planned feature, extension, bug fix, hardening, or documentation), and do not create parallel canonical systems.

### Phase completion

Reconcile all three documents against resulting source. Move only completed functionality from `Planned.md` to `Implemented.md`; retain unfinished portions as planned. Update this file with actual files, services, flows, permissions, integrations, and storage. Record actual verification honestly: static verification is not runtime verification. If implementation differs from a plan, document what exists.

### Drift and partial implementation

If documentation conflicts with source, inspect source and correct documentation. Partial features belong in both documents only in their respective scopes: implemented foundation in `Implemented.md`, unfinished roadmap in `Planned.md`. Bug fixes usually update architecture, material behavior, or known limitations rather than moving roadmap entries.

### Request/response observability
Gateway logging passes request and final response details to `APIPlatform_Log_Sanitizer` before the single `apiplatform_log_api_request()` database write. The sanitizer redacts sensitive headers and explicit secret query/body field names, recursively handles JSON, records safe metadata for malformed or unsupported payloads, and applies bounded capture limits (8 KB headers/query, 32 KB request body, 64 KB response body). Captured fields are stored only in the log table and Request History renders/exports those already-sanitized values; credentials are never reconstructed from the UI. Missing user agents are recorded as `Not provided`, and clean route paths are used without secret-bearing query strings.

The publisher-facing `Endpoint Schema Workspace` in `modules/frontend/classes/class-publisher-pages.php` edits the canonical versioned endpoint schema JSON, versioned data-model JSON, and `apiplatform_schema_validation_mode`. The selector presents Off, Observe, and Enforce while storing `off`, `log`, and `enforce`; the same `APIPlatform_Endpoint_Schema_Service` data feeds runtime validation, generated documentation/OpenAPI, and tester defaults. Response definitions support status-specific contracts and a `default` fallback.

Request and response contracts share the canonical per-API mode setting but execute in separate scopes: request validation runs before runtime and checks only declared parameters/body fields; response validation runs after runtime and checks status/body responses. An endpoint with no request parameters and a disabled request body is explicitly treated as request-validation-not-applicable, so Enforce can still evaluate the final response contract. Genuine request validation failures retain structured issue metadata and use the request `validation_failed` category; response mismatches use the registry-backed `response_schema_validation_failed` category.

Gateway WP_Error handling extracts the nested `status` member from canonical error data before constructing the response envelope; it does not pass the entire error-data array to `absint()`. This preserves registry statuses such as response-schema FAPI-516/502 through public and REST response paths. Request History persists canonical error code/message when available, while older rows continue using safe status-based fallbacks.

Request objects begin with `auth_type=not_attempted`; authentication replaces this with the canonical classification only after credential processing. Request-schema normalization uses the gateway-resolved API version and canonical boolean parsing for `request_body.enabled`, so malformed or string-valued false flags cannot activate body validation unexpectedly. Under `WP_DEBUG`, schema resolution emits only version, endpoint ID, parameter count, and enabled state metadata.

### Phase 10H security hardening notes

`APIPlatform_Cache_Service` only caches application-authenticated GET responses when both application and application-key IDs are present; the cache identity contains those non-secret IDs. Legacy/published-key responses are not cached to prevent cross-identity response reuse. One-time API-key reveal diagnostics never log the transient token name. Open operational findings and runtime-deferred certification are tracked in `PRODUCTION_READINESS.md`.

### Request History retention

`APIPlatform_Request_History_Retention` in `modules/helpers/request-history-retention.php` owns the global retention option, allowlist normalization, daily hook `apiplatform_request_history_retention_cleanup`, bounded cleanup, and administrator purge handling. The service targets only `{prefix}apiplatform_logs`, uses the indexed `created_at` MySQL datetime in WordPress site time, limits cron work to 1,000 rows per run in 500-row batches, and is scheduled idempotently on activation/wp_loaded and cleared on deactivation. `APIPlatform_Admin_Settings` exposes the existing Settings → Request History tab; all mutations require `manage_options`, POST, nonce, and explicit confirmation for purge. Retention deletes historical logs only, so analytics are retention-window based and usage/rate-limit accounting remains separate.

### Plan-aware Request History retention

`APIPlatform_Membership_Panel::get_plan_definitions()` owns the only `request_history_days` values (Free 7, Pro 30, Premium 90, Admin 365). `APIPlatform_Request_History_Retention::effective_retention_days_for_api()` resolves a log row through `api_id` → `APIPlatform_Ownership_Service` → personal owner or `APIPlatform_Organization_Plans::plan_owner_id()` → membership-panel plan entitlement, then applies the global `apiplatform_request_history_retention_days` safety maximum. Missing/deleted APIs, owners, or plans fall back to that bounded maximum. Cleanup selects rows older than the shortest canonical plan window, resolves each API once per in-memory run cache, and deletes only rows whose `created_at` has exceeded their effective retention. Scheduled/manual cleanup uses actual plans; simulation is diagnostic-only unless explicitly requested.

The earlier global-retention description refers to the same option now used as the platform safety maximum; it is not a second plan-entitlement source.

### Temporary Request History retention test utility

`APIPlatform_Request_History_Retention_Test_Utility` in `modules/helpers/request-history-retention-test.php` is a development-only, administrator-only fixture harness loaded after the retention service. It inserts minimal marked rows into the canonical logs table, uses `APIPlatform_Request_History_Retention::plan_id_for_api()` and `effective_retention_days_for_api()`, and never hooks gateway execution or changes production cleanup. It stores only a temporary per-admin fixture manifest in user meta; deletion is strictly limited to request IDs beginning `retention_test_`.

Retention cleanup orders candidates by `created_at ASC, id ASC` and advances a bounded keyset cursor across batches. This prevents repeated inspection of the same not-yet-expired rows and allows later expired rows to be reached while preserving the 500-row batch and 1,000-row run limits. Synthetic `retention_test_` diagnostics are WP_DEBUG-only.
