# FreedomAPI Planned Features

**Status:** Future roadmap only. Nothing in this file is production functionality until implementation is completed and verified. Repository source code is authoritative.

For current architecture see [Source.md](Source.md). For existing capabilities see [Implemented.md](Implemented.md).

## Instructions for Future Codex Sessions

Read this file before implementing a planned feature, then re-read relevant actual source. This document does not override source. Reuse canonical services; do not create parallel plan, ownership, permission, error, gateway, or authentication systems. Update status honestly, and never claim runtime PASS without actual execution.

## User Automations

**Status:** Planned  
**Priority:** High

### Purpose and architecture

Automations are a future core feature for **all** plans: Free, Pro, Premium, and Admin. Plans may limit count, runs, advanced capabilities, history retention, connectors, and concurrency, but may not make baseline automation access paid-only. Future entitlement keys (`automations`, `automation_runs_monthly`, `automation_history_days`, `automation_connectors`, `automation_concurrent_runs`) are **TBD** and must be defined in `modules/armember/class-membership-panel.php`.

An automation is: `Trigger → Conditions → Actions`.

Example: Store API emits `order.created` → `order.total > 100` → Discord sends a message, Inventory updates stock, and FreedomAPI records an event.

Personal automations belong to a user and can work with personal APIs, authorized applications, external webhooks, and permitted integrations. Organizations may have shared automations; role permissions control view/create/edit/delete/enable/run/history/retry, while plan entitlement independently controls capacity.

### Planned behavior

Potential triggers: API request/response/error, API/version publishing, application events, webhook receipt, schedules, organization/member/invitation events, external connector events, and manual triggers. Conditions use normalized event data and may compare values/status/plan/role/API/version. Actions may send webhooks/notifications/external API calls or invoke canonical FreedomAPI operations for API metadata/runtime data/schema/model/version/lifecycle/docs/SDK/application events and other workflows.

Automation actions must use: `automation → canonical service → ownership → permission → plan entitlement → validation → action → activity/log`. They must never bypass authorization by writing arbitrary post meta or database rows. Run history must record identifiers, timing, status, action results, FAPI errors, retries, and request IDs without secrets. Retry policies must be bounded.

Applications may eventually be reusable connector definitions (for example Discord or Store triggers/actions), while automations are configured workflows. Marketplace/distribution visibility (Public, Unlisted, Private) is future-only.

**Not implemented yet.** The current admin automations module and the `/automations` WordPress wrapper page are infrastructure, not evidence of this general user automation engine. Its existing rules table is maintained by the canonical database-version migration path, not by a future workflow engine.

## FreedomAPI CLI

**Status:** Planned Later  
**Priority:** Medium

Future concepts include `fapi login`, `whoami`, organization/API list/create/pull/push, automation list/create/enable/disable, and version publishing. Local configuration may use `freedomapi.yaml`.

CLI authentication must never reuse public/published gateway API keys. It must be opt-in, disabled by default, separate, scoped, revocable, user-specific, plan-limited, and auditable. Future management endpoints must be separate from `/gateway/...` execution endpoints.

`effective CLI permission = plan-allowed CLI capability ∩ CLI credential scope ∩ actual user/organization authorization`

All three must allow an action. CLI plan capabilities must come from `class-membership-panel.php`; no independent CLI plan map.

**Not implemented yet.**

## Custom Authentication Providers

**Status:** Planned  
**Priority:** Medium

Possible providers: GitHub, Discord, Microsoft, generic OpenID Connect, generic OAuth 2.0, Steam-style, and custom providers. Extend the existing provider registry and normalized identity architecture; do not create a second auth system.

## Sign in with FreedomAPI

**Status:** Planned Later  
**Priority:** TBD

FreedomAPI may become an OAuth 2.0/OpenID Connect provider for third parties, with potential authorize, token, userinfo, revoke, introspect, discovery, and JWKS endpoints. This is separate from external sign-in into FreedomAPI.

## Developer Error Reference

**Status:** Planned  
**Priority:** Medium

Generate portal reference material from `APIPlatform_Error_Codes::all()`—FAPI code, key, title, message, HTTP status, resolution, and category. Do not duplicate definitions manually.

## Per-API Organization Access

**Status:** Planned  
**Priority:** Medium

Organization seats remain organization-wide; a member consumes one seat regardless of API count. Future per-API access decides which organization APIs a member may manage and must not create additional seats. `apiplatform_organization_api_access` may be a foundation but is not assumed authoritative.

## Billing Provider Integration

**Status:** Planned  
**Priority:** Medium

Potential providers include Stripe, other providers, and manual enterprise provisioning. Providers may determine subscription state; FreedomAPI's canonical entitlement system determines capabilities. Payment code must not become a plan-definition source; `class-membership-panel.php` remains canonical.

## Production Hardening

**Status:** Planned  
**Priority:** High

Phase 10E established hashed published-API-key records, safely migrates recoverable plaintext historical material, and promotes recoverable compatibility matches during validation. Phase 10F implemented canonical Bearer-first transport extraction, conflict/malformed rejection, Request History transport capture, and secure Bearer-default tester/docs. The first successful runtime promotion is logged as `legacy_promoted`; later requests are `current`. Static audit note: no custom CORS/Access-Control response policy was found in the inspected modules; preserve the existing server/browser boundary and do not add wildcard credential headers. Noisy diagnostic families such as [FEATURE SYSTEM] and [DOCS CPT] remain inventoried for later standardization.

Request History transport visibility still requires live UI confirmation after the persisted-field display fix. Phase 10G response-contract runtime validation remains pending the explicit live verification sequence; strict-default enforcement, query-auth removal, and broader staging certification remain planned.

Remaining work includes retiring the explicitly retained fallback compatibility path after production migration certification, deprecating query-string key transport, adding published-key creator/last-used audit metadata if required, strengthening response schema validation and gateway cache invalidation, securing provider secrets at rest, disabling or network-isolating publisher-configurable proxy runtimes for v1, reducing legacy `post_author` fallbacks, cleaning duplicate/dead loading architecture, standardizing debug logging, reviewing proxy DNS-rebinding protections, and adding integration/staging/migration certification.

## Runtime Certification

**Status:** Deferred  
**Priority:** High

Static verification is not runtime verification. Runtime certification requires an executable WordPress/PHP environment and tests must remain deferred—not PASS—until executed.

## Documentation Lifecycle

### Phase start

Read `Source.md`, `Implemented.md`, and this roadmap; inspect relevant source; classify work as new feature, extension, bug fix, hardening, or documentation; do not create parallel canonical systems.

### Phase completion and partial implementation

Reconcile all three documents with resulting source. Move only completed functionality to `Implemented.md`; retain unfinished portions here, even when a foundation exists. Update `Source.md` with actual architecture. If results differ from a plan, document actual behavior rather than preserving obsolete intent. Source wins over documentation.

### Drift and runtime rule

Correct documentation when it contradicts source. Never move speculative work into `Implemented.md`, and never convert static or deferred checks to runtime PASS without execution.

Phase 10G observability runtime acceptance remains pending live WordPress verification. Broader production diagnostic cleanup and later Phase 10H hardening remain planned.


## Phase 10H — Production security and operational hardening

**Status:** Audit foundation implemented; operational and runtime certification remain planned/deferred.

The confirmed cache-isolation and transient-token diagnostic fixes are implemented. Remaining planned work is tracked in `PRODUCTION_READINESS.md`: provider secret encryption/key lifecycle, runtime/staging verification of plan-aware Request History retention, proxy DNS-rebinding controls, query-auth retirement after migration, and staging runtime certification.





### Temporary retention utility removal

After runtime certification, remove the temporary `APIPlatform_Request_History_Retention_Test_Utility` helper, its loader entry, its render call, and its admin action hook. Do not remove the production plan-aware retention helper, cron hook, platform maximum setting, or normal Request History logging.
