# Publisher Guide

This guide covers authenticated publisher workflows. Public consumers should use the [Developer guide](developer-guide.md) and [Developer Portal](developer-portal.md) instead.

## Dashboard and API creation

The authenticated full-layout application is rendered by `APIPlatform_Frontend_Layout` through `[api_fulllayout_page page="dashboard" size="full"]`. The sidebar links to Dashboard, Organizations, Applications, My APIs, Create API, Analytics, Logs, Documentation, Versions, API Settings, Usage, Keys, Profile, Billing, Account Settings, Automations, and administrator Debug where permitted.

Create API collects the API identity and runtime configuration. The resulting API is a `user_api` post. Use the edit/builder screens to configure endpoint methods/paths, parameters, request bodies, response examples/contracts, versions, models, rate limits, cache/runtime settings, and documentation. The exact fields visible depend on the current source modules and enabled features.

## Runtime types

The readiness policy recognizes `internal`, `rest_proxy`, `webhook_proxy`, and `external_http`. Internal runtimes can execute stored responses. Proxy runtimes need a target URL or a registered runtime callback; proxy use also depends on the deployment's egress controls. See [Operations](operations.md) for the V1 operational decision around external proxy targets.

## Endpoint and response design

An endpoint schema can define one or more methods, path, summary/description, query/header/path parameters, an optional JSON request body, response status contracts, examples, and model references. Response validation supports Off, Observe, and Enforce. In Enforce mode a response that fails its selected contract returns the registry-backed FAPI-516 / HTTP 502 response; request validation failures remain client-side 4xx responses.

## Authentication and API keys

New and regenerated published keys are generated as `apk_live_` secrets. The canonical `api_keys` records contain a password hash, mask/prefix, label, enabled/status and lifecycle metadata. The raw secret is shown through a protected one-time UI flow; copy it immediately. The UI does not provide a recoverable plaintext value later.

Validation is hash-first using `password_verify`. Existing recoverable historical records can be promoted to hashes, and the dormant plaintext cleanup migration removes obsolete persistent plaintext while retaining canonical hashes. Key creation/regeneration fails closed if secure persistence fails.

Keys may be disabled, revoked, expired, or denied by API/application scope according to the stored record and access services. After regeneration, the new key should authenticate and the previous key should fail. Do not log keys or include them in Request History.

## Applications

Applications are separate from published API keys. `APIPlatform_Applications_Service` manages application ownership, status, approval, application keys, API access grants, and events. Application credentials use the `fapi_app_...` family and are subject to application access checks. Do not treat an application record as an organization or as a replacement for publisher ownership.

## Organizations and ownership

An API may be personally owned or organization-owned through `APIPlatform_Ownership_Service`. Organization permissions are resolved by `APIPlatform_Organization_Permissions`; roles and capabilities are not duplicated in frontend code. Organization plan entitlements are derived from the creator/active owner membership.

Seat usage is **active members plus valid pending invitations**. A pending, unexpired invitation reserves a seat; acceptance turns that reservation into the active member without double counting. Revoked, declined, or expired invitations release the seat. Seat-limit failures use the centralized FAPI-603 error.

## Plans and usage

`APIPlatform_Membership_Panel` is the canonical plan source: Free, Pro, Premium, and Admin Access. It owns limits, unlimited semantics, retention windows, and seat policy. Plan Simulation is an administrator-only diagnostic feature that changes effective plan calculations without changing ARMember membership, roles, billing, capabilities, or ownership.

Usage pages show request/credit counters and plan limits. Unlimited values are presented as `∞`; ordinary counters are formatted safely. Usage is not the authority for ownership or authorization.

## Publishing and Developer Portal exposure

Publisher Settings controls portal visibility (Private, Unlisted, Public), public slug/title/summary, category/tags, authentication label, version/status, support/legal links, tester flags, and publication metadata. The lifecycle/readiness service checks runtime, endpoint, authentication, version, slug, status, and other prerequisites. Public directory listing requires Public visibility, active status, Public/Deprecated lifecycle, and readiness. Unlisted APIs can be reached through a known link but are omitted from listings. See [Developer Portal](developer-portal.md).

## Documentation Manager and Versions

The authenticated Documentation Manager edits overview, authentication, SDK examples, FAQ, migration text, endpoint/schema material, and versioned snapshots/releases. The public portal consumes the current or selected published snapshot and canonical endpoint schema; it does not create a parallel documentation source. Versions and lifecycle tools control current, beta, deprecated, public, draft, and archived states as implemented.

## Request History and Diagnostics

Logs are available to authorized users through the authenticated Logs page. Sanitized request/response metadata includes method, endpoint, status, timing, request ID, safe headers/query/body, auth classification/transport, and response validation metadata. Credentials, cookies, and secrets are redacted. Retention is plan-aware and bounded; see [Request History](request-history.md).

API Diagnostics presents safe operational metadata and does not expose credentials or raw secret-bearing request data.

## Common publisher workflows

### Publish a documented API

Create API → configure active endpoint/runtime → create key → document parameters/body/responses → verify readiness → set Public visibility → confirm publication → test the gateway and public portal.

### Move an API to an organization

Create/select the organization → confirm role/permission → transfer ownership through the ownership service → verify organization API access and plan/seat entitlement → retest keys and portal visibility.

### Rotate a key

Generate/regenerate → copy the one-time secret → update consumers → test the new key → revoke/retire the old key → verify the old key is rejected.
