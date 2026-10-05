# Troubleshooting

## API route returns 404

**Likely causes:** stale permalinks, wrong configurable prefix, missing wrapper page, private/unlisted portal policy, missing API slug, or unavailable endpoint.

**Diagnose:** confirm the route prefix in settings, save WordPress permalinks, verify wrapper-page diagnostics, inspect API status/lifecycle/portal slug, and compare the documented URL.

**Safe resolution:** refresh permalinks, correct the allowlisted page/slug, or publish the API when readiness checks pass. Do not delete routes or database tables.

## Authentication fails

**Likely causes:** old regenerated key, malformed Bearer header, wrong X-API-Key value, disabled/expired/revoked key, missing application grant, or organization access denial.

**Diagnose:** generate/copy the current one-time key, test the documented transport, inspect the safe error code/request ID, and check key status/access—not raw secrets.

**Safe resolution:** update the consumer with the new key, revoke compromised keys, grant the intended application/API access, and keep credentials out of URLs/logs.

## API is not visible in Developer Portal

**Likely causes:** Private/Unlisted visibility, inactive/archived/draft lifecycle, missing title/version/active endpoint/runtime/authentication, reserved/non-unique slug, or readiness failure.

**Diagnose:** open authenticated Portal Publishing readiness, confirm Public visibility and Public/Deprecated lifecycle, then check the public route.

**Safe resolution:** complete the listed metadata/readiness requirements and publish. Unlisted APIs intentionally do not appear in listings.

## Request History is empty

**Likely causes:** no calls yet, wrong user/organization view, sanitizer/authorization filtering, a route that never reached the gateway, or retention deletion.

**Diagnose:** make a known gateway call, capture its request ID, check status/auth transport, and confirm you have access to the API/log view.

**Safe resolution:** use the correct owner/organization context and inspect sanitized records. Never weaken redaction to recover a credential.

## Retention is not scheduled

**Likely causes:** WP-Cron disabled or quiet, activation not completed, invalid retention option, or a deployment that did not refresh hooks.

**Diagnose:** inspect the daily `apiplatform_request_history_retention_cleanup` event and WordPress Cron health.

**Safe resolution:** restore a supported Cron trigger, save the Request History setting, or run the authorized manual purge. Do not alter candidate SQL/date logic in production.

## Frontend wrapper page is missing

**Likely causes:** page was never created, is draft/trashed, has the wrong shortcode or identifier, or has a duplicate/conflict.

**Diagnose:** API Platform → Settings → Frontend Pages.

**Safe resolution:** create/publish the exact allowlisted wrapper with `[api_fulllayout_page page="IDENTIFIER" size="full"]`.

## Gateway not responding

**Likely causes:** rewrite issue, disabled API, invalid runtime/proxy target, authentication/access failure, upstream failure, or a PHP/server error.

**Diagnose:** test route/permalink, inspect safe request ID/error, check API runtime/readiness and server logs, and verify HTTPS/egress settings.

**Safe resolution:** correct configuration or restore the tested artifact; do not expose internal exception details to clients.

## Organization API inaccessible

**Likely causes:** ownership transfer incomplete, missing role/capability, missing organization API access, disabled member, expired invitation, or entitlement/seat limit.

**Diagnose:** inspect the canonical owner, organization role/access, plan owner, active members plus valid pending invitations, and the FAPI error.

**Safe resolution:** correct membership/access through organization UI and retry. Do not bypass ownership checks or use `post_author` as organization authority.

## Usage page problems

**Likely causes:** no usage rows, unavailable service data, unlimited plan formatting, or stale frontend assets.

**Diagnose:** confirm plan and API context, inspect safe counters, and hard-refresh versioned assets.

**Safe resolution:** verify the usage service/database state and deployment artifact; do not rewrite counters manually.

## CSS or theme conflict

**Likely causes:** stale cache, Divi/theme selector cascade, or unscoped custom CSS.

**Diagnose:** hard-refresh and inspect the computed selector/property under `.apiplatform` or `.apiplatform-developer-portal`.

**Safe resolution:** update the plugin and keep corrections scoped to the relevant FreedomAPI wrapper/component. Do not change theme core files.

## Cron not executing

**Likely causes:** disabled `DISABLE_WP_CRON`, low traffic, server scheduler misconfiguration, or fatal errors.

**Diagnose:** inspect scheduled event, server cron response, WordPress health, and redacted logs.

**Safe resolution:** configure an approved external scheduler and test on staging before production.
