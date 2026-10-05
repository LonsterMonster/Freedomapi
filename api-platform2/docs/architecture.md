# Internal Architecture

This is maintainer documentation for the implemented V1 source. Read `Source.md`, `Implemented.md`, and `PROJECT_CONTEXT.md` before extending a subsystem.

## Bootstrap and modules

`api-platform2.php` defines plugin constants, loads `modules/helpers.php`, loads enabled module manifests through `modules/modules.php`, registers `user_api`, and invokes database installation on activation. Helpers are loaded before modules. Module manifests live in `modules/*/module.json`.

Major areas are `core`, `frontend`, `backend`, `data`, `builder`, `templates`, `armember`, `auth`, `developer-portal`, `logs`, and `automations`. `APIPlatform_Module_Loader` remains a compatibility class; the active path is `apiplatform_load_modules_once()`.

## Frontend and publisher architecture

`APIPlatform_Frontend_Layout` renders the authenticated `.apiplatform` shell and dispatches page identifiers to feature classes. The full-layout shortcode is `[api_fulllayout_page page="..." size="full"]`. Publisher classes render create/edit API, publisher pages, Documentation Manager, Versions, Settings, Usage, Keys, Organizations, Applications, Logs, and other views. Authorization is resolved by canonical ownership/permission services rather than by view visibility.

## Developer Portal

`APIPlatform_Developer_Portal` owns public route registration/dispatch. `APIPlatform_Developer_Portal_Query` normalizes portal metadata, public cards, directory filters, registry queries, and statistics. `APIPlatform_Developer_Portal_Documentation` combines Documentation Workspace snapshots, canonical frontend docs, endpoint schema, models, examples, responses, and errors. Publishing, tester, SEO, and assets are separate classes/files. See [Developer Portal](developer-portal.md).

## Gateway processing

`APIPlatform_Gateway` and its request/response/error classes handle rewrite routing, endpoint/version resolution, request validation, authentication, rate/abuse controls, runtime dispatch, response validation, logging, and final machine responses. Gateway responses bypass normal theme rendering at early `template_redirect`. `APIPlatform_Endpoint_Schema_Service` is the canonical contract source.

## Authentication flow

`apiplatform_extract_presented_api_key()` extracts Bearer, X-API-Key, then compatibility query credentials. Key helpers validate canonical hashes with `password_verify`, classify current/legacy promotion/application/public access, and keep transport separate from auth type. One-time key display and fail-closed persistence are enforced by the key services and UI flows. Application keys are separate custom-table records.

## Ownership and entitlements

`APIPlatform_Ownership_Service` resolves personal versus organization ownership. `APIPlatform_Organization_Permissions` owns role/capability checks. `APIPlatform_Membership_Panel` is the canonical plan map and seat policy. `APIPlatform_Organization_Seats` counts active members plus valid pending invitations. `APIPlatform_Organization_Entitlements` applies owner-derived plan limits. `APIPlatform_Plan_Simulation` is an administrator-only effective-plan diagnostic.

## Logging, Request History, and Diagnostics

`APIPlatform_Log_Sanitizer` bounds and redacts request/response details before the single logs-table write. `class-request-history.php` and its views render stored sanitized records with transport, validation, status, timing, IP masking, and inspection. Retention is implemented by `APIPlatform_Request_History_Retention`; diagnostics consume safe stored metadata and WP_DEBUG-gated operational markers.

## Documentation, versions, and automations

`APIPlatform_Documentation_Workspace` stores normalized documentation snapshots/releases/events. Endpoint schema, OpenAPI, SDK, lifecycle, and builder services consume canonical versioned structures. The automation module provides the current admin automation UI; it does not independently install database schema.

## Scheduled tasks and rewrites

Retention scheduling is the daily `apiplatform_request_history_retention_cleanup` WP-Cron hook. Rewrite registration occurs on `init` for route prefixes and portal routes. Refresh permalinks after deployment or prefix changes.

## External dependencies

The source assumes WordPress APIs (`$wpdb`, posts/meta, options, nonces, Cron, REST), PHP, MySQL-compatible storage, and ARMember membership integration. External provider login, SDK generation, Chart.js, and proxy runtimes are optional and have separate configuration/security boundaries.
