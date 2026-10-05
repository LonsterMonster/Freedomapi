# Administration and WordPress Setup

This guide is for site administrators deploying FreedomAPI V1. It describes configuration, not a substitute for WordPress, ARMember, hosting, or backup procedures.

## Required environment

- WordPress with rewrite/permalink support and a MySQL-compatible database.
- A supported PHP version and patched WordPress/ARMember installation.
- HTTPS for login, keys, gateway traffic, and secure cookies.
- Working filesystem permissions for the plugin, uploads, and temporary SDK generation.
- A reliable WordPress Cron trigger or an external scheduler that invokes WP-Cron.

## Installation and activation

Install the plugin directory containing `api-platform2.php`, activate it, and verify the activation path completes without PHP errors. Activation invokes `apiplatform_install_database(true)`. The installer uses `dbDelta`, creates the current tables, runs idempotent migrations/backfills, and stores database readiness/version markers. The current schema version is `1.8.5`.

The installer also runs through the auto-heal path when the stored database version is behind the source version. Do not delete tables or manually edit migration markers as a first response to an error.

## Required wrapper pages and shortcodes

Authenticated standalone pages use `[api_fulllayout_page page="IDENTIFIER" size="full"]`. Confirm the published pages in **API Platform → Settings → Frontend Pages**:

| Page slug | Identifier |
| --- | --- |
| organizations | organizations |
| applications | applications |
| my-apis | my-apis |
| logs | logs |
| documentation-manager | documentation-manager |
| versions | versions |
| publisher-settings | publisher-settings |
| docs | docs |
| automations | automations |

The administrator checker reports missing, draft, trashed, wrong shortcode, wrong identifier, or duplicate/conflict states. It does not silently repair pages. Route-managed Dashboard, Developers, and Docs pages are maintained by `APIPlatform_Routes::sync_managed_pages()`.

## Permalinks and rewrites

Save or refresh WordPress **Settings → Permalinks** after activation or route-prefix changes. FreedomAPI registers gateway, dashboard, developer, docs, and auth prefixes. The Developer Portal separately registers `/developers` rewrite rules and versioned route flushing. A 404 on a known-good route is first diagnosed as a permalink/prefix/page problem before changing application code.

## Gateway and runtime configuration

Review the configured gateway prefix (default `gateway`), API runtime type, endpoint schemas, authentication mode, rate limits, cache settings, and response-validation mode. Internal runtimes can use stored responses. Proxy runtimes require a configured target/callback and network egress controls. Do not enable external proxy APIs in production without HTTPS, allowlisting, private-IP/DNS-rebinding controls, bounded timeouts, and response limits.

## Membership and organizations

ARMember supplies actual membership plans. The FreedomAPI membership panel is the canonical plan/limit source. Administrators can use Plan Simulation only when the feature is enabled and for diagnostics; it does not change membership or billing. Organization administration includes ownership, members/roles, invitations, API access, plan entitlement, and the seat rule active members plus valid pending invitations.

## Request History retention

Settings → Request History controls the platform maximum using an allowlist of 7, 14, 30, 60, 90, 180, and 365 days (default 365). Plan windows remain Free 7, Pro 30, Premium 90, Admin 365. Daily cleanup runs from `apiplatform_request_history_retention_cleanup`; the administrator can run the nonce-protected manual purge. Cleanup is bounded to 500-row batches and at most 1,000 rows per run. WP-Cron depends on site traffic; an external scheduler may trigger WordPress Cron according to the hosting design.

The retention certification utility is development-only and requires `WP_DEBUG`, `manage_options`, POST, and nonces. It must not be treated as a production control.

## Production debug and logging

Set `WP_DEBUG` and display errors off in production. Retain centralized redacted operational logs. WP_DEBUG diagnostics are safe metadata only; they must never be used as a replacement for monitoring or a reason to expose request credentials. Verify that backups, support exports, and telemetry contain no API keys, cookies, authorization headers, or raw bodies.

## Optional integrations

ARMember is used for membership integration. External auth currently includes the configured provider modules (including Google where enabled). Chart.js is used by applicable analytics/UI surfaces. SDK generation and proxy runtimes are optional and should be enabled only with their operational controls.
