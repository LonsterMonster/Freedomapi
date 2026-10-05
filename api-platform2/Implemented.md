# FreedomAPI Implemented Capabilities

**Repository source code is authoritative.** This is an inventory of functionality present in the current repository, not a roadmap. For architecture see [Source.md](Source.md); for unfinished/future work see [Planned.md](Planned.md).

## Status and Verification Language

`Implemented` means code exists in this repository. `Static Verification` means source inspection only. `Runtime Deferred` means no runtime PASS is claimed. Manual observations are evidence for the stated interaction only; they do not certify a complete feature suite.

## Core Platform and API Authoring

**Status:** Implemented / Statically Verified  
**Purpose:** Create, edit, persist, and present `user_api` APIs.

**Implemented capabilities:** custom `user_api` post type; frontend create/edit/dashboard pages; builder save/migration and response rendering; API status/lifecycle helpers; ownership-aware publisher/API pages; API keys and one-time UI behavior.

**Canonical implementation:** `api-platform2.php`, `modules/builder/`, `modules/frontend/classes/class-create.php`, `class-edit-api.php`, `class-publisher-pages.php`, `modules/helpers/keys.php`, `api-status.php`, and `lifecycle.php`.

**Verification:** Static: source audited. Runtime: Deferred.

**Related architecture:** Source.md → Gateway, ownership, and canonical services.

## Gateway, Rate/Abuse Processing, and Logs

**Status:** Implemented / Statically Verified  
**Purpose:** Execute public API requests through rewrite-based gateway routes.

**Implemented capabilities:** gateway route registration/dispatch before later frontend/page-builder `template_redirect` callbacks; request and response objects; route/API resolution; current-first detailed API-key validation with safe `current`/`legacy_promoted`/`legacy`/application classifications; runtime processing; cache service; request logs; request-history UI; analytics/admin log classes; rate/abuse helper integration.

**Canonical implementation:** `modules/helpers/gateway.php`, `keys.php`, `usage.php`, `abuse.php`, `modules/logs/`, and frontend request-history/publisher classes.

**Known limitation:** legacy API-key compatibility remains as a migration boundary, not a claim of modern-only credential handling. Query-string key transport also remains supported pending a separate transport-hardening phase.

### Phase 10E — API-Key Storage and Authentication Hardening

**Status:** Implemented / Statically Verified / Runtime Deferred  
**Implemented capabilities:** canonical hashed `api_keys` records for new/regenerated keys; one-time plaintext reveal; safe masks/prefixes; stable IDs for new/migrated records; current-first gateway auth; on-match promotion of recoverable raw compatibility records; explicit `current`, `legacy_promoted`, and `legacy` classification; persisted/reverified promotion diagnostics; idempotent database-version migration of historical key material; replacement of migrated plaintext `api_key` meta with a mask; masked-versus-active legacy diagnostics; existing API-key-panel security status; and Request History credential-class filtering. Application credentials remain separate custom-table records.

**Verification:** Static: source audited, migration/version hook and source structure checked. Runtime: Deferred; no local WordPress/PHP runtime was installed, configured, faked, or executed.

**Known limitation:** no published-API-key creator or last-used timestamp exists yet. Legacy compatibility remains only when promotion cannot safely persist a verified hash, and query-string authentication remains supported.

**Verification:** Static: source audited. Runtime: Deferred.


### Phase 10F — API-Key Transport Hardening

**Status:** Implemented / Statically Verified / Runtime Deferred  
**Implemented capabilities:** canonical credential extraction with Bearer-first precedence, X-API-Key compatibility, query compatibility, malformed/conflict rejection, proxy Authorization compatibility, transport-independent Phase 10E validation, persisted Request History transport metadata, Bearer-default API Testers, updated portal/OpenAPI/developer documentation, and clean endpoint copying without secret query URLs. Application credentials remain separate from published-key transport.

**Verification:** Static: source structure, migration version, transport paths, docs, and safe-secret patterns audited. Runtime: Deferred; the development environment does not provide an executable WordPress/PHP runtime.

**Known limitation:** query-string authentication remains supported for compatibility and should be deprecated after runtime/staging certification. No runtime PASS is claimed.

**Live transport observations:** Bearer, X-API-Key, and query compatibility requests each returned HTTP 200 against the same current hashed key identity; usage remained on that identity. Request History now renders the persisted transport separately, including X-API-Key as a human-readable label while retaining canonical x_api_key internally; live confirmation remains pending. A subsequent unmatched-brace regression in the Request History template was corrected without changing transport behavior. The copy-button helper is now protected against repeated partial inclusion and has one canonical definition. This observation does not certify the full runtime suite.

### Phase 10G — Response & Schema Runtime Hardening

**Status:** Implemented / Statically Verified / Runtime Deferred  
**Implemented capabilities:** runtime validation reuses canonical endpoint response contracts, selects status-specific JSON contracts, supports bounded nested object/array/type/required-field checks, preserves no-schema APIs, supports off/log-observe/enforce rollout, records safe bounded validation metadata in Request History, and uses a centralized registry-backed enforcement error. Runtime certification remains deferred pending manual WordPress verification.

## Organizations, Ownership, Membership, and Invitations

**Status:** Implemented / Statically Verified  
**Purpose:** Support organization-owned APIs and role-controlled collaboration.

**Implemented capabilities:** organization creation/update/archive; membership roles and statuses; permission resolution with administrator override; ownership metadata/transfer helpers; organization APIs; invitation create/accept/decline/revoke/expiry paths; activity events; organization dashboard views and `org_view` subviews.

**Canonical implementation:** `modules/helpers/organizations.php` (`APIPlatform_Organization_Service`, `APIPlatform_Organization_Membership_Service`, `APIPlatform_Organization_Permissions`, `APIPlatform_Ownership_Service`, invitation service) and `modules/frontend/classes/class-organizations.php`.

**Verification:** Static: source audited. Runtime: Formal certification deferred.

## Organization Seats and Plan Entitlements

**Status:** Implemented / Statically Verified  
**Purpose:** Enforce owner-derived organization plan limits for members/seats and APIs.

**Implemented capabilities:** canonical plan definitions/limits; owner-derived effective organization plan; organization member/API usage; FAPI-603/FAPI-604 enforcement; valid pending invitation reservation; acceptance without double counting; reservation release on revoked, declined, or expired invitations; UI usage wording.

**Canonical implementation:** `modules/armember/class-membership-panel.php`, `APIPlatform_Organization_Seats`, and `modules/helpers/organization-plans.php`.

**Verification:** Static: source audited. Runtime: Deferred. Manual development observations reported separately below.

## Plan Simulation

**Status:** Implemented / Statically Verified  
**Purpose:** Administrator-only entitlement testing without changing actual membership.

**Implemented capabilities:** option/user-meta gated tool; selected simulated plan; actual versus effective plan accessors; organization-owner scoping; nonce-protected admin controls and publisher notices.

**Canonical implementation:** `modules/helpers/plan-simulation.php` and `APIPlatform_Membership_Panel::get_effective_user_plan()`.

**Verification:** Static: source audited. Runtime: Deferred.

## FAPI Error Registry and Frontend Notices

**Status:** Implemented / Statically Verified  
**Purpose:** Provide stable application error identifiers and safe structured error context.

**Implemented capabilities:** built-in/filtered registry definitions; lookup by code/key; FAPI ID, title/message/resolution/HTTP metadata; normalized `WP_Error`; organization action PRG notices; shared alert rendering.

**Canonical implementation:** `modules/helpers/error-codes.php`, `modules/frontend/classes/class-organizations.php`, and `modules/frontend/views/shared/alert.php`.

**Verification:** Static: source audited. Runtime: Deferred.

## Applications and Application/API Access

**Status:** Implemented / Statically Verified  
**Purpose:** Store and manage applications, application keys, access, approval, and events.

**Implemented capabilities:** application service tables and operations; application UI pages; application keys/access/events; organization-aware application context where services permit it.

**Canonical implementation:** `modules/helpers/applications.php`, `modules/frontend/classes/class-applications.php`, and application tables in `modules/helpers/database.php`.

**Known limitation:** applications are not yet the planned reusable user-automation connector engine.

## Documentation, Schemas, Models, OpenAPI, SDK, Versions, and Lifecycle

**Status:** Implemented / Statically Verified  
**Purpose:** Support API documentation and structured API evolution.

**Implemented capabilities:** documentation workspace and optional documentation post type; endpoint schema service and examples; reusable model/schema operations in supporting source; OpenAPI service; SDK generators; documentation manager/API detail UI; versions/publishing/lifecycle-related publisher/portal flows.

**Canonical implementation:** `modules/helpers/documentation-workspace.php`, `endpoint-schema.php`, `openapi.php`, `sdk-generator.php`, `lifecycle.php`, `modules/developer-portal/`, and `class-publisher-pages.php`.

**Verification:** Static: source audited. Runtime: Deferred.

## Developer Portal and Frontend Dashboard

**Status:** Implemented / Statically Verified  
**Purpose:** Provide public developer routes and authenticated dashboard screens.

**Implemented capabilities:** Developer Portal rewrite routes for portal home, directory, API detail/docs/test, category/tag, getting-started, and dashboard; frontend layout shortcodes; dashboard routing through `apipage`; sidebar/publisher screens; managed route-prefix support.

**Canonical implementation:** `modules/developer-portal/`, `modules/frontend/classes/class-layout.php`, `class-publisher-pages.php`, and `modules/helpers/routes.php`.

The existing Organizations and Applications renderers are reachable through published WordPress wrapper pages containing `[api_fulllayout_page page="organizations" size="full"]` and `[api_fulllayout_page page="applications" size="full"]`. The prior 404 root cause was missing wrappers, not missing renderers. API Platform → Settings → Frontend Pages now checks the allowlisted wrapper definitions and offers explicit create-only actions for missing pages. Formal runtime certification remains deferred.

## External Authentication and Connected Accounts

**Status:** Implemented / Statically Verified  
**Purpose:** Modular external sign-in and identity linking.

**Implemented capabilities:** provider interface/registry; Google provider source; login/account-linking distinction; expiring state with PKCE verifier; auth callback dispatch; identity persistence; provider settings; audit events; token-like profile-field redaction.

**Canonical implementation:** `modules/auth/` classes, particularly `APIPlatform_Auth_Providers`, `APIPlatform_Auth_Controller`, `APIPlatform_Auth_State`, and `APIPlatform_External_Identity_Service`.

**Verification:** Static: source audited. Runtime: no successful Google OAuth execution evidence is recorded here.

## Membership, Pricing, Data, Admin, Templates, and Current Admin Automations

**Status:** Implemented / Statically Verified  
**Purpose:** Provide membership display, pricing, service/data helpers, administration, templates, and current admin automation controls.

**Implemented capabilities:** ARMember plan display/integration; billing/credits/usage helpers; data services/router; backend menus/settings/users/alerts/bans/debug/keys/logs; template builder; an `automations` module containing `APIPlatform_Admin_Automations`, with no duplicate per-request schema initializer.

**Known limitation:** source supports an admin automation module, but no general user-owned Trigger → Conditions → Actions automation engine is documented as implemented. That future feature remains in `Planned.md`.

## Database Installation and Migrations

**Status:** Implemented / Statically Verified  
**Purpose:** Create platform tables during plugin activation.

**Implemented capabilities:** activation calls `apiplatform_install_database(true)` when available; normal requests use its stored database-version guard and only run `dbDelta()` on a missing/outdated version; database version `1.8.5` also invokes the idempotent API-key plaintext-to-hash migration; installer defines tables for platform logging, transactions/rules, applications, documentation, external identities, organizations, invitations, and organization access/events.

**Canonical implementation:** `api-platform2.php` and `modules/helpers/database.php`.

## Manual Live Evidence (Not Formal Certification)

Development observations supplied for this project indicate:

- An organization gateway URL executed an organization API.
- Plan Simulation changed Admin Access to simulated Free/Pro entitlements.
- Pro seat usage displayed `1 / 3`.
- Creating a valid pending invitation changed usage from `1 / 3` to `2 / 3`.
- Revoking that invitation changed usage from `2 / 3` back to `1 / 3`.
- Returning simulation to Admin restored unlimited entitlement behavior.

These are limited manual observations, not a full runtime suite or Phase 10 certification result. Formal Phase 10C runtime certification remains **Runtime Deferred** because no executable WordPress/PHP runtime was available for that certification work.

## Documentation Lifecycle

At phase start, read `Source.md`, this inventory, and `Planned.md`, then inspect actual relevant source. At phase completion, reconcile all three documents with source. Add actual completed capability, source location, verification, and limits here; retain unfinished future work in `Planned.md`; update `Source.md` when architecture changes. Static verification must never be relabeled runtime verification.

For partial implementation, record the implemented foundation here and leave unfinished portions in `Planned.md`. For documentation drift, source wins: inspect source, correct documentation, and report the drift rather than changing correct production behavior.

## Phase 10G — Request History observability hardening

**Status:** Implementation COMPLETE / Static verification COMPLETE / Runtime certification DEFERRED

**Runtime environment:** NOT ENABLED. Production runtime verification: NOT PERFORMED.

Request History now captures bounded request headers, query parameters, request bodies, user agent, response headers, and final response bodies through the centralized `APIPlatform_Log_Sanitizer` before persistence. Explicit authentication and secret fields are redacted recursively, unsupported or malformed payloads produce safe metadata, and truncation is marked. Existing auth transport and response-contract metadata remain canonical. Captured values are rendered and exported from sanitized storage; no live runtime certification has been performed.




The Request History capture/redaction path has been manually live-verified: request headers are captured, Authorization/X-API-Key/cookie/query `api_key` values are redacted, user agent and response headers are captured, actual response JSON is retained, clean endpoints are shown, and the canonical request ID is preserved. Response-contract runtime validation remains deferred pending the explicit no-schema, valid, observe, enforce, required-property, nested, array, model, and status-specific live sequence.

The enforce-mode debugging fix separates an empty/no-request-contract GET from genuine request validation failures. Such GETs now proceed to runtime under Enforce, allowing response mismatches to produce registry error FAPI-516/HTTP 502. Real request-schema violations remain 4xx failures with structured validation metadata. Response-contract runtime certification remains deferred.

The Phase 10G enforce status-propagation bug was fixed: gateway WP_Error status extraction now preserves registry-backed HTTP 502 responses, and Request History persists the canonical response error code/message when available. Request validation, authentication, and generic unclassified 500 failures retain their existing behavior. Runtime certification remains deferred.

The Phase 10G example3 pre-auth regression was corrected. Non-array request-body metadata is normalized safely, string false flags remain disabled, request validation uses the exact gateway-resolved schema version, and empty GET contracts remain not applicable. Safe WP_DEBUG schema-resolution and exception diagnostics were added; pre-auth rows now use `Not attempted`, and status-based storage diagnostics distinguish failed requests from successful database writes. Runtime certification remains deferred.

The Phase 10G pre-auth fatal caused by a `$this` call inside the static `APIPlatform_Route_Resolver` context was corrected to the existing static route diagnostic method. No schema, request-validation, response-validation, or authentication semantics changed.

## Phase 10H — Production security and operational hardening foundation

**Status:** Confirmed-risk fixes COMPLETE / Static verification COMPLETE / Runtime certification DEFERRED

The Phase 10H audit removed one-time API-key reveal transient-token names from WP_DEBUG diagnostics. Response caching is now privacy-partitioned: only authenticated application requests with stable application and application-key IDs may read/write cache entries, and the key includes both non-secret IDs. Legacy/published-key responses are intentionally not cached. Provider-secret encryption, proxy DNS-rebinding protection, query-auth retirement, and full staging certification remain documented open/deferred work in `PRODUCTION_READINESS.md`.

No production runtime verification or PHP CLI lint was performed in this environment.


## Release Candidate prerequisite — Request History retention hardening

**Status:** Implementation COMPLETE / Static verification COMPLETE / Runtime certification DEFERRED

Request History now uses the global option `apiplatform_request_history_retention_days` as a platform safety maximum (default 365; allowlist 7, 14, 30, 60, 90, 180, 365). Plan-specific values are canonical `request_history_days` entitlements in `APIPlatform_Membership_Panel`: Free 7, Pro 30, Premium 90, Admin 365. The existing administrator Settings → Request History tab displays those definitions and the maximum, with nonce-protected settings and an explicit POST-only action to delete rows currently expired under each API plan. A single daily WP-Cron hook performs bounded cleanup (500-row batches, at most 1,000 rows per run) against only the canonical logs table and its indexed `created_at` column. Usage counters and unrelated data are not deleted; analytics and exports naturally cover rows that remain within plan retention windows. Scheduled/manual deletion uses actual owner plans; Plan Simulation is available only when explicitly requested for diagnostics and does not trigger destructive cleanup. Runtime certification remains deferred.



## Temporary RC tooling — plan-aware retention fixtures

While `WP_DEBUG` is enabled, administrators can use the temporary Request History Retention Test Utility on Settings → Request History. It inserts marked `retention_test_` rows for real APIs, displays actual owner-plan/effective-retention resolution, and verifies results only after the existing cleanup action runs. It is not a permanent product feature and is intentionally hidden when `WP_DEBUG` is false.

The temporary retention utility render fatal was fixed by normalizing its per-admin fixture manifest before iteration. Empty/scalar user-meta values now render as an empty state instead of being accessed as arrays; production retention logic was unchanged.

The plan-aware retention cleanup starvation bug was fixed. Bounded batches now advance with a `(created_at, id)` keyset cursor, so an ineligible candidate batch cannot be reselected indefinitely while later expired rows wait. WP_DEBUG diagnostics for `retention_test_` rows record candidate selection, timestamps, eligibility, and delete outcome without normal request data.

The retention certification diagnostics were hardened without changing policy or cleanup semantics. Cleanup now emits unconditional WP_DEBUG start, cutoff, batch, and end markers with explicit `manual`/`cron` source labels; the manual action logs receipt/invocation/result; synthetic fixture creation and selected-row deletion outcomes are logged with safe metadata only. Runtime certification remains deferred.
