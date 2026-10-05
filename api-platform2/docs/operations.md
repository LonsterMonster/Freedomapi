# Production Operations and Release Workflow

## Baseline

The preserved V1 baseline is Git tag `v1.0.0` in the repository. The release commit and later documentation commits are separate; do not move or overwrite the production tag automatically.

## Deployment checklist

1. Test the exact artifact in staging with a backup and restore plan.
2. Verify supported PHP/WordPress/ARMember/database/TLS versions.
3. Enable HTTPS, secure cookies, HSTS/server security headers, and a non-wildcard CORS policy at the edge.
4. Set `WP_DEBUG` and display errors off; retain redacted centralized logs.
5. Activate/update the plugin and verify database readiness/version `1.8.5`.
6. Refresh permalinks and confirm gateway, dashboard, docs, auth, and Developer Portal routes.
7. Verify all nine authenticated wrapper pages and empty/error states.
8. Run authentication, authorization, IDOR, rate-limit, request/response validation, and gateway smoke tests.
9. Verify a new key works, the previous regenerated key fails, and no key appears in logs.
10. Verify Request History, retention scheduling, portal discovery, tester gating, and SDK behavior.

## Cron and retention

Confirm `apiplatform_request_history_retention_cleanup` is scheduled daily and that WP-Cron executes in the hosting environment. On low-traffic sites, configure an approved external scheduler to trigger WordPress Cron rather than changing retention logic.

## Smoke tests

- Gateway: call a known endpoint with `X-API-Key: YOUR_API_KEY`; confirm status, body, request ID, and sanitized history row.
- Authentication: test missing, malformed, invalid, Bearer, X-API-Key, and compatibility query transports.
- Request History: open `/logs`, inspect auth transport and validation fields, and confirm no credentials are shown.
- Developer Portal: open `/developers`, search a public API, open docs, and verify an unavailable private/unready API is not listed.
- Retention: verify settings/cron state and perform the administrator purge only in an approved staging test.

## Backups, updates, rollback

Back up database, uploads, plugin artifact, and configuration; test restore before launch. Deploy the exact staging-tested artifact, record the commit/tag, monitor redacted errors and gateway health, and retain the prior artifact/database backup for rollback. Roll back by restoring the tested prior artifact and database snapshot according to the site's maintenance plan; do not reset production Git history or delete tables as an ad hoc rollback.

## Deferred/operational controls

Provider-secret management, proxy DNS-rebinding/network egress controls, full runtime certification, load/restore testing, and credential rotation remain operational checklist items where noted in `PRODUCTION_READINESS.md`. They must not be silently represented as source-code features.
