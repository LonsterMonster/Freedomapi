# FreedomAPI V1 — Project Context and Handoff

## Purpose and status

FreedomAPI is a WordPress plugin for creating, publishing, testing, and operating personal and organization-owned APIs. The current source tree represents the completed V1 production baseline. Application behavior is not changed by this document.

The release-candidate implementation and static production-readiness work are complete. The project history supplied for this baseline records zero confirmed blockers and zero remaining should-fix-before-launch code items. Runtime certification is recorded honestly where it was deferred; static inspection is not a substitute for a live WordPress/PHP test.

## Bootstrap and architecture

- `api-platform2.php` is the plugin entry point (version 3.2). It defines plugin paths/version, loads compatibility helpers and modules, registers the `user_api` post type, and runs the installer on activation.
- `modules/helpers.php` loads cross-cutting helpers before feature modules.
- `modules/modules.php` reads each enabled `modules/*/module.json` and bootstraps modules after `plugins_loaded`.
- `modules/core/` contains core, REST, realtime, and admin analytics bootstrapping.
- `modules/frontend/` contains authenticated frontend layout, pages, shortcodes, and assets.
- `modules/helpers/` contains routing, gateway, database, ownership, organizations, plans, keys, validation, logging, retention, documentation, and related services.
- `modules/armember/` owns canonical membership plan definitions and entitlements.
- `modules/auth/` contains the external-provider registry, Google provider, callback/state, identity linking, and auth settings.
- `modules/developer-portal/` contains public portal routes, publishing, documentation, tester, SEO, and queries.
- `modules/builder/` contains API builder save/migration and response/schema UI.
- `modules/backend/`, `modules/logs/`, and `modules/automations/` contain WordPress-admin surfaces.
- `modules/data/`, `modules/templates/`, and `assets/` contain data services, templates, and shared assets.

Helpers load before modules; the active module path is the manifest loader in `modules/modules.php`. `APIPlatform_Module_Loader` remains as a compatibility class and is not the primary bootstrap path.

## Frontend layout and WordPress pages

`APIPlatform_Frontend_Layout` in `modules/frontend/classes/class-layout.php` renders the `.apiplatform` shell, sidebar, overlay, and `.apiplatform-main`, dispatching the selected page to existing renderers. The standalone wrapper shortcode is `[api_fulllayout_page page="dashboard" size="full"]`.

`size="full"` safely breaks the application out of the narrow WordPress content container without changing the surrounding theme shell. The finalized desktop layout uses the scoped sidebar/main flex layout; the desktop sidebar stretches with the application, uses `#0f1117`, and retains blue active navigation (`#2563eb`). It ends with the FreedomAPI application rather than recoloring the WordPress/Divi footer. Mobile sidebar behavior remains a separate responsive mode.

Canonical wrapper definitions are returned by `APIPlatform_Frontend_Layout::wrapper_page_definitions()` and are checked in API Platform → Settings → Frontend Pages. The page must be published and contain the matching shortcode/identifier; page existence does not grant authorization.

| WordPress page slug | `page` identifier |
| --- | --- |
| `/organizations` | `organizations` |
| `/applications` | `applications` |
| `/my-apis` | `my-apis` |
| `/logs` | `logs` |
| `/documentation-manager` | `documentation-manager` |
| `/versions` | `versions` |
| `/publisher-settings` | `publisher-settings` |
| `/docs` | `docs` |
| `/automations` | `automations` |

Dashboard, create/edit/manage API, test API, documentation, analytics, usage, organization, application, publisher, settings, and profile renderers are registered by their feature classes. Public Developer Portal routes are separate below `/developers/`; gateway routes are separate below the configured gateway prefix.

## Platform Docs Library (authenticated V1 subsystem)

The authenticated FreedomAPI Docs Library is the platform's version-controlled product and operations documentation surface. Its canonical content source is the plugin-root `/docs/` Markdown tree, not WordPress documentation posts. The existing dark Docs Library shell, section navigation, breadcrumbs, responsive layout, and authenticated shortcode/page remain in place.

`APIPlatform_Frontend_Documentation` (registered by `[api_documentation_page]` and reached through the `docs`/`documentation` full-layout page) now asks `APIPlatform_Platform_Documentation` in `modules/helpers/platform-documentation.php` for an allowlisted document index and rendered document. The loader recognizes only the approved Markdown paths under `/docs`, reads them locally, and renders escaped basic Markdown (headings, paragraphs, emphasis, inline code, fenced code, lists, links, tables, blockquotes, horizontal rules, and an in-document table of contents). Document links are converted to the authenticated Docs Library query route (`?doc=`) and external links are protocol allowlisted. Missing or disallowed documents fall back to the normal safe page state; no arbitrary path is read or executed.

Navigation groups and document labels are defined by an explicit source map. Documents marked `public` are available to authenticated Docs Library users; `internal` documents are restricted to administrators. This visibility metadata is an application boundary for the authenticated library and does not publish files through the public Developer Portal. The loader does not import Markdown into WordPress, create posts, or mutate the database.

The prior `documentation` CPT/taxonomy and `APIPlatform_Documentation_Workspace` remain registered for compatibility and continue to hold publisher-created API documentation, endpoint records, snapshots, schema-derived references, and the authenticated Documentation Manager workflow. Those records are not deleted or rewritten by the platform Markdown loader. Publisher-managed API documentation is consumed by the separate public Developer Portal documentation path; the platform Docs Library is not a source for `/developers/` API docs.

Platform Docs Library presentation uses the existing `modules/frontend/views/partials/documentation/documentation-page.php` and `section.php` with Markdown-specific post content/TOC markup, plus scoped rules in `modules/frontend/assets/css/frontend.css`. It is still subject to the authenticated frontend page/shortcode and configured docs route/permalink behavior (`/docs` for the normal route, or the administrator dashboard docs alias); no public rewrite or Developer Portal route is added.

## Developer Portal (public V1 subsystem)

The Developer Portal is FreedomAPI's public discovery and integration surface. It is distinct from the authenticated publisher Dashboard and Documentation Manager: visitors can browse APIs and published documentation without entering the publisher dashboard, while API owners manage listing metadata, lifecycle, schemas, and documentation from authenticated publisher screens.

### Routes and rewrite architecture

`modules/developer-portal/classes/class-developer-portal.php` registers the portal on `init` and handles `template_redirect`. `APIPlatform_Routes::developer_prefix()` supplies the configurable prefix (default `developers`), and the portal registers these rewrite routes:

- `/developers/` — portal home
- `/developers/apis/` — public API directory
- `/developers/apis/{slug}/` — API detail/landing page
- `/developers/apis/{slug}/docs/` — API documentation
- `/developers/apis/{slug}/test/` — interactive public tester when enabled
- `/developers/apis/{slug}/sdk/{language}.zip` — generated SDK download when enabled
- `/developers/categories/{category}/` and `/developers/tags/{tag}/` — filtered directory views
- `/developers/getting-started/` — public integration guide
- `/developers/dashboard/` — authenticated developer workspace

The routes use `apiplatform_portal` query vars and are also recognized from the request path. A versioned route option triggers `flush_rewrite_rules(false)` when the prefix/rule set changes; normal WordPress rewrite/permalink registration must therefore be healthy. `APIPlatform_Routes::sync_managed_pages()` maintains the configured Developers managed-page reference, but the portal itself renders its route response directly rather than requiring an `api_fulllayout_page` shortcode.

Portal responses are wrapped by `APIPlatform_Frontend_Layout::render_document()` in a public header/body/footer shell with no authenticated sidebar. The shell provides Developer Home, Browse APIs, Getting Started, and either a signed-in Developer Dashboard link or WordPress Sign In link.

### Publication and public visibility rules

`APIPlatform_Developer_Portal_Query` reads the portal metadata stored on each `user_api` post and builds a normalized API card. The publisher's Portal Publishing panel (`modules/developer-portal/classes/class-portal-publishing.php`, surfaced from the authenticated API edit/publisher pages) controls Private, Unlisted, and Public visibility, portal slug, title, summary, description, category/tags, authentication label, version/status, support/legal links, tester flags, and publication metadata.

Directory/listing visibility is deliberately stricter than direct detail access. An API must be Public, active, have a Public/Deprecated lifecycle, and pass `APIPlatform_Lifecycle_Policy::readiness()`. Readiness checks include a public title, valid runtime/API slug, unique non-reserved portal slug, active API status, public version label, at least one active endpoint path, configured authentication, and a configured runtime. Private APIs never appear publicly. Unlisted APIs may be opened through a known portal URL but are excluded from directory, search, category, tag, and related-API listings. Draft and archived lifecycle states are not public; deprecated APIs remain discoverable with deprecation metadata. The portal returns an unavailable/404-style state rather than leaking private records when a route is not allowed.

### Discovery and browsing

The home route shows public-directory statistics, featured/popular APIs, recently published APIs, recently updated APIs, category/tag counts, and a quick-start panel. The directory supports sanitized search across API title/slug/summary/description/tags/category, category and tag filters, authentication and public-status filters (Active/Beta/Deprecated), sorting (recent, newest, oldest, alphabetical, popular), and bounded pagination. Directory queries use the portal registry plus `user_api`/post metadata and re-check lifecycle/readiness eligibility before presenting cards. API cards link to detail, documentation, optional tester, support, changelog, and related public APIs.

### Public API detail, documentation, and integration information

The API detail route presents the public title/icon/summary/description, publisher/category/tags, public status and version, authentication type, runtime label, update information, base/gateway endpoint URL, and links to docs, testing, SDKs, support, and changelog. It includes a quick-start code panel with generated cURL, JavaScript `fetch`, PHP, Python, and Node.js examples using the documented endpoint and placeholder credentials.

`APIPlatform_Developer_Portal_Documentation::normalize()` composes the public documentation model from the current or selected `APIPlatform_Documentation_Workspace` snapshot when available, otherwise the canonical frontend documentation metadata and current endpoint schema. It exposes version/release choices, overview/authentication/workspace text, endpoint definitions, models, parameters, request-body examples, response contracts, common errors, rate limits, and generated examples. The docs route renders a searchable table-of-contents and sections for:

- authentication guidance (recommended `X-API-Key`, legacy query example, application-key notes, and safety guidance);
- endpoint method/path/description/version/status/rate-limit metadata;
- parameter name, location, type, required/default/example/description/validation information;
- request-body enabled/content type/example/schema fields;
- response status/description/model tables and example JSON;
- referenced models and their fields/types/required/nullable/enum/reference/description values;
- generated cURL/JavaScript/Node/PHP/Python/C# examples;
- SDK Center, common errors, gateway error reference, rate limits, workspace FAQ/SDK/migration text, releases/changelog, and publisher resources.

The portal's documentation is therefore a public presentation of publisher-managed documentation and the canonical versioned schema; it is not a second schema or documentation store. `APIPlatform_Developer_Portal_Publishing` edits publication/listing metadata, while the authenticated Documentation Manager/workspace and endpoint-schema services supply the snapshots and contract data consumed here.

### Tester, SDKs, and authentication boundaries

The public tester route is available only when the publisher enables `allow_public_tester` and the API passes the public route policy. It requires the visitor to supply an API key for that request; the tester sends a nonce-protected AJAX request through `APIPlatform_Developer_Portal_Tester`, limits/sanitizes documented query parameters and headers/body, blocks credential-like headers, and does not store or echo the supplied key. Responses display status, headers, timing, request ID, size, body, and copyable cURL output. The portal may show an anonymous-testing setting in publisher metadata, but the current tester still requires a supplied key.

SDK links are shown only when `APIPlatform_SDK_Generator_Service` reports the API enabled and the requested language is allowlisted. Downloads are generated from the published canonical schema and recorded as SDK download events; private/unlisted APIs and disabled languages are rejected.

The public home, directory, detail, docs, getting-started, category, tag, and enabled tester routes do not require WordPress login. The Developer Dashboard and favorite toggle do: the dashboard shows the signed-in user's applications, favorites, request totals, SDK activity, and public APIs; favorite mutations require login, a per-API nonce, and a public API card. Portal rendering never grants ownership, API management, organization access, or key-management rights.

### Developer Portal implementation and assets

The subsystem is bootstrapped by `modules/developer-portal/developer-portal.php` and includes:

- `class-developer-portal.php` — rewrites, route dispatch, public rendering, cards, detail/docs/tester/dashboard views;
- `class-portal-query.php` — portal metadata, registry queries, visibility/readiness filtering, cards, directory filters/stats;
- `class-portal-documentation.php` — snapshot/schema normalization, endpoint/response/model data, examples;
- `class-portal-publishing.php` — authenticated publisher settings, validation, readiness, and persistence;
- `class-portal-tester.php` — nonce-protected public AJAX tester and bounded request handling;
- `class-portal-seo.php` — route-aware public metadata;
- `assets/css/developer-portal.css` and `assets/js/developer-portal.js` — shared portal layout, cards, quick-start tabs, copy/toast behavior;
- `assets/css/portal-docs.css` and `assets/js/portal-docs.js` — documentation layout, tabs, search/highlighting, JSON view/download, and table-of-contents behavior;
- `assets/css/portal-tester.css` and `assets/js/portal-tester.js` — interactive tester form, response rendering, key visibility, and copy actions.

These assets are enqueued only on recognized portal routes, with the docs and tester bundles loaded only on their respective routes. The portal uses the existing frontend asset versioning and public layout shell; it does not reuse the authenticated `.apiplatform-sidebar` navigation.

## API creation, publishing, versions, and documentation

The frontend create/edit and builder services save APIs as `user_api` posts with versioned endpoint/schema/model metadata. Publisher pages manage APIs, analytics, documentation, versions, keys/settings, and publishing/lifecycle state. `APIPlatform_Endpoint_Schema_Service` is the canonical versioned endpoint schema source; the same data feeds runtime validation, generated documentation/OpenAPI, tester defaults, and SDK generation. `APIPlatform_Documentation_Workspace`, `APIPlatform_OpenAPI_Service`, and `APIPlatform_SDK_Generator_Service` provide supporting documentation and generated artifacts. Lifecycle policy and version services govern release/version operations.

## Gateway and runtime flow

`modules/helpers/gateway.php` owns rewrite registration, route/API/version resolution, request and response objects, runtime dispatch, response handling, logging integration, and the cache service. A gateway request flows through route matching, endpoint/schema resolution, request validation, authentication, rate/abuse controls, runtime execution, response-contract validation, request logging, and the final response. Gateway machine responses are handled at an early `template_redirect` priority so theme/page-builder callbacks do not corrupt them.

Gateway route prefixes are configurable through `APIPlatform_Routes`; the default gateway prefix is `gateway`. Request validation honors an explicitly disabled request body (`enabled=false`) and normalizes false-like values; real enabled request contracts still reject missing/invalid JSON. Response validation supports Off, Observe, and Enforce modes. Enforce mismatches use canonical FAPI-516/HTTP 502 responses.

## Authentication and API-key security

`modules/helpers/keys.php` is the canonical published API-key helper. New and regenerated keys use `apk_live_` secrets, are displayed only through protected one-time UI flow, and are stored as password hashes with mask/prefix/label/status/timestamps and compatible metadata. Raw credentials are not displayed or logged. Validation is hash-first; recoverable historical raw records can be promoted in place while preserving status/expiry/scope metadata. The versioned dormant-plaintext cleanup migration removes obsolete persistent plaintext while preserving canonical hashes and remains retry-safe.

Credential transport extraction is centralized in `apiplatform_extract_presented_api_key()`, with precedence Bearer, X-API-Key, then query compatibility. Transport (`authorization_bearer`, `x_api_key`, `query`) is separate from auth classification (`current`, `legacy_promoted`, `legacy`, `application`, `public`). Request History stores and displays transport type only. Application credentials (`fapi_app_...`) are separate records managed by `APIPlatform_Applications_Service`.

Key creation and regeneration fail closed if secure persistence fails; no raw-key fallback write is used. Database version `1.8.5` includes the idempotent legacy-key migration.

## Ownership, organizations, plans, and seats

`APIPlatform_Ownership_Service` in `modules/helpers/organizations.php` owns personal-versus-organization API ownership and access. `post_author` remains only as compatibility fallback for older personal APIs. `APIPlatform_Organization_Permissions` resolves organization roles/capabilities, with administrator override; membership role mappings remain in the organization membership service.

`APIPlatform_Membership_Panel` in `modules/armember/class-membership-panel.php` is the sole source of plan definitions, labels, limits, unlimited semantics, and seat policy. Current plans are Free, Pro, Premium, and Admin Access. `APIPlatform_Organization_Plans` is a compatibility resolver that delegates to the membership panel. Effective organization plan is derived from the creator-owner or active owner membership.

The canonical seat policy is active members plus valid pending invitations. `APIPlatform_Organization_Seats` counts active members and pending, unexpired invitations; acceptance converts the reservation into an active member without double counting; revoked, declined, and expired invitations release the seat. Entitlement checks use this count and return centralized FAPI-603 seat-limit errors when appropriate.

Plan Simulation is implemented in `modules/helpers/plan-simulation.php`. It is administrator-only, feature-gated, nonce-protected, and stores only an enabled flag and selected plan in user meta. It changes effective diagnostics/entitlements only; it does not alter ARMember membership, roles, capabilities, billing, ownership, or destructive retention policy unless explicitly requested by a diagnostic flow.

## Request History and retention

Request History is rendered by `modules/frontend/classes/class-request-history.php` and its views. Centralized `APIPlatform_Log_Sanitizer` sanitizes and bounds request headers/query/body, user agent, response headers/body, and validation metadata before the single logs-table write. Sensitive headers, cookies, API keys, authorization, and explicit secret fields are redacted; malformed/unsupported payloads receive safe metadata. Missing user agents are recorded as `Not provided`; historical rows without transport remain `Not recorded`.

`APIPlatform_Request_History_Retention` in `modules/helpers/request-history-retention.php` uses the global option `apiplatform_request_history_retention_days` as a platform safety maximum (default 365; allowlist 7/14/30/60/90/180/365). Plan windows are canonical membership entitlements: Free 7 days, Pro 30, Premium 90, Admin 365. Cleanup runs from the daily `apiplatform_request_history_retention_cleanup` WP-Cron hook and administrator-only manual purge. It uses bounded 500-row batches, up to 1,000 rows per run, against the canonical prefixed `apiplatform_logs` table and indexed `created_at`; it uses owner-derived actual plans and does not delete APIs, usage, organizations, applications, or keys. WordPress Cron depends on site traffic.

The development-only retention certification utility is `modules/helpers/request-history-retention-test.php`. It is gated by `WP_DEBUG`, `manage_options`, POST, nonces, and the `retention_test_` synthetic prefix. It is hidden when `WP_DEBUG` is false and is not a production feature. Its fixture/diagnostic work does not change production retention semantics.

## Database installer and migrations

`modules/helpers/database.php` defines the canonical installer and idempotent migrations at database version `1.8.5`. It creates the prefixed logs, transaction/rules, portal/lifecycle, applications/application keys/access/events, documentation snapshots/releases/events, external identity, organization/member/invitation/access/event, and related tables through the existing installer/dbDelta path. Migration completion markers are advanced only after required writes succeed; failed writes remain retryable. Activation invokes installation, and the stored version mismatch path performs auto-heal without schema changes on every request.

## Applications, automations, usage, and diagnostics

`APIPlatform_Applications_Service` manages application records, ownership/access, keys, approval, and events. Usage services aggregate request/credit counters and the Usage frontend presents bounded numeric values with unlimited plans represented as `∞`. Automations are managed by the current WordPress-admin automation module and do not independently install schema.

API Diagnostics V1 is an administrator/user-facing diagnostics surface built from safe API/runtime metadata. It does not expose keys, headers, cookies, bodies, or secrets. Request History observability and response-contract metadata remain based on sanitized stored data.

## Logging, debug, and external dependencies

Normal diagnostic detail is gated by `WP_DEBUG` and safe metadata; production should have `WP_DEBUG` and display errors disabled while retaining centralized redacted logs. The plugin relies on WordPress, PHP, MySQL-compatible `$wpdb`, ARMember for membership integration, and the configured web server/rewrite/permalink system. Chart.js is loaded externally for applicable analytics/UI charts. External authentication currently includes the Google provider with expiring one-time state/PKCE and redacted audit data. Provider secret encryption and network-layer proxy DNS-rebinding protection remain operational decisions documented in `PRODUCTION_READINESS.md`; they are not silently represented as completed V1 code.

## Production baseline and handoff

Recorded release-candidate evidence includes: zero confirmed blockers; zero remaining should-fix-before-launch code items; a 12/12 deployment checklist; Request History retention boundary certification across Free, Pro, Premium, and Admin/platform maximum behavior; batching/manual cleanup verification; API Diagnostics runtime testing; hashed-key authentication and regeneration testing (new key succeeds, previous key rejected); dormant plaintext cleanup; HTTPS, required wrapper pages, permalinks, database/system health, retention scheduling, authenticated gateway requests, WP_DEBUG production behavior, and final frontend layout/contrast corrections.

Runtime certification statements remain tied to their recorded evidence. Where the development environment did not provide executable WordPress/PHP, the project records runtime certification as deferred rather than claiming a pass. A future staging run should execute the negative authentication/authorization/rate-limit/validation matrix, response-contract Observe/Enforce matrix, organization IDOR/seat matrix, clean install/upgrade/restore checks, cache privacy checks, proxy/network controls, backups, and final independent sign-off.

Recommended staging/update workflow: deploy a reviewed artifact to staging, verify PHP/WordPress/ARMember/TLS/database support, refresh permalinks, run the relevant checklist and backup/restore test, inspect sanitized logs, then promote the exact tested artifact. Keep credentials, production `.env` values, keys, nonces, cookies, and certificates out of source, context files, logs, and support exports.

## Source-of-truth rules for future work

Read `Source.md`, `Implemented.md`, `Planned.md`, and this file before changing the project. Source code is authoritative. Do not add duplicate plan maps, ownership/permission matrices, gateway/auth systems, error registries, schema sources, or retention policies. Keep completed V1 behavior separate from future work in `Planned.md`, and distinguish static verification from live runtime certification in all reports.
