# FreedomAPI Production Readiness

## Readiness summary

Phase 10H security and operational hardening has been statically audited. Two confirmed risks were remediated: one-time API-key reveal diagnostics no longer log transient token names, and response caching no longer shares authenticated legacy/published-key responses across identities. The implementation is not certified production-ready yet: the executable WordPress/PHP runtime is not enabled in this development environment, so live runtime certification remains deferred.

Status:

- Implementation: Phase 10G behavior preserved; Phase 10H confirmed-risk fixes complete.
- Static verification: Complete for the reviewed files and invariants listed below.
- Runtime certification: Deferred.
- Runtime environment: Not enabled.
- Production runtime verification: Not performed.

## Findings ledger

| Severity | Area | Status | Finding / action |
|---|---|---|---|
| HIGH | Runtime certification | DEFERRED | No executable WordPress/PHP runtime is available here. Run the full staging matrix before release. |
| HIGH | Provider secrets at rest | OPEN | OAuth client secrets are stored in WordPress options; encryption-at-rest/key-management is not implemented. Disable external provider login for v1 unless secrets are accepted as an explicitly managed operational risk. |
| RESOLVED | Log retention | IMPLEMENTED | Plan-aware retention from canonical membership entitlements, a global safety maximum, daily bounded cleanup, and administrator-only purge now limit growth to each API policy. Runtime verification remains deferred. |
| HIGH | Proxy SSRF / DNS rebinding | OPEN | Publishers can configure external proxy URLs. HTTPS, host allowlisting, private/reserved-IP checks, no redirects, and bounded timeout/response size are present, but DNS can change between validation and request. Disable proxy/external runtimes for v1 unless network-layer egress controls are deployed. |
| MEDIUM | Query authentication | COMPATIBILITY | Query API keys remain supported for compatibility. Prefer Authorization Bearer or X-API-Key and plan removal only after consumers migrate. |
| LOW | Development diagnostics | IMPROVED | Normal diagnostics remain WP_DEBUG-gated and use safe metadata. Remaining development-only settings/debug messages should stay disabled in production. |

## Security controls verified statically

- Canonical FAPI error registry covers authentication, authorization, validation, entitlement, upstream, and platform responses; gateway status propagation remains explicit.
- Client-facing internal errors are generic; WP_DEBUG diagnostics contain only sanitized class/message/source metadata, IDs, and status context. Keys, headers, cookies, and bodies are not logged.
- API-key extraction supports header-first authentication and compatibility query transport. Request History persists transport type, never the credential.
- One-time API-key reveal diagnostics retain only safe context and existence state; transient token names are not logged.
- Provider callback/controller paths redact token-like fields in audit events; provider client secrets are never rendered back into forms.
- Organization authorization and plan/seat decisions use the canonical membership/ownership services. Seat usage is active members plus valid pending invitations.
- Plan Simulation is administrator-only, feature-gated, stores only a selected plan ID, and does not mutate membership, capabilities, ownership, or billing state.
- Database installation uses the canonical idempotent installer/dbDelta path and current schema version 1.8.5; it is not run on every request.
- SQL reviewed in the touched security paths uses prepared values or allowlisted identifiers. Output is escaped in reviewed admin/frontend views.
- Nonces and capability checks are present on reviewed admin mutations; organization mutations enforce ownership/role authorization.
- Runtime proxy restricts targets to HTTPS, rejects credentials/localhost/.local/private or reserved IPs, disables redirects, bounds timeout to 15 seconds, and limits response size to 1 MB. DNS rebinding remains an operational finding above.
- Request/response capture is sanitized and bounded. Historical records without transport remain “Not recorded”.
- Response validation supports Off/Observe/Enforce semantics; Enforce mismatch is represented by FAPI-516/HTTP 502. Request-body validation honors enabled=false and normalizes false-like values.
- CORS is not configured as wildcard-with-credentials in the reviewed code. Production should still set an explicit allowed-origin policy at the web-server edge.
- Authenticated response cache privacy is hardened: cache reads/writes are allowed only for application authentication with positive application and application-key IDs; legacy/published-key responses are not cached. Application cache keys include both IDs and no secret.

## Production checklist

Before release, complete all items in staging with real WordPress/PHP:

- [ ] Enable HTTPS, secure cookies, HSTS and server-level security headers.
- [ ] Set `WP_DEBUG` and display errors off in production; retain centralized redacted logs.
- [ ] Confirm PHP, WordPress, ARMember, database, and TLS versions are supported and patched.
- [ ] Create a least-privilege deployment/database backup account and test restore.
- [ ] Verify all nine wrapper pages/routes and empty/error states on a clean site.
- [ ] Run the negative authentication/authorization/rate-limit/validation matrix.
- [ ] Run response-contract Observe and Enforce tests, including FAPI-516/HTTP 502.
- [ ] Verify organization IDOR cases and seat reservations for active members plus valid pending invitations.
- [ ] Verify migration from the prior schema and clean install idempotence.
- [ ] Confirm cache is disabled for legacy/published-key requests and private responses are never shared.
- [x] Implement bounded Request History retention and purge controls; [ ] complete staging retention/export/access-review procedures.
- [ ] Validate proxy egress controls and DNS-rebinding protection at the network layer.
- [ ] Review CORS, reverse-proxy forwarding, timeouts, body limits, and upstream allowlists.
- [ ] Confirm backups, support exports, and telemetry contain no credentials or raw bodies.
- [ ] Obtain independent runtime certification sign-off.

## Credential rotation checklist

- [ ] Inventory WordPress salts, database credentials, provider client secrets, API keys, application keys, webhook/signing secrets, and proxy credentials.
- [ ] Rotate provider client secrets and revoke superseded credentials.
- [ ] Regenerate application/API keys and verify old keys fail with the registry status.
- [ ] Rotate salts only through a planned maintenance window; invalidate sessions as intended.
- [ ] Remove credentials from CI logs, backups, support exports, and local debug files.
- [ ] Record owner, issue date, expiry, storage location, and next rotation date for each secret.

## Clean install and upgrade checklist

- [ ] Install on an empty WordPress database and verify all required tables/indexes are created.
- [ ] Re-run activation/installer and verify no duplicate schema or destructive changes occur.
- [ ] Upgrade from the previous deployed schema through 1.8.5 and verify idempotence.
- [ ] Verify existing APIs, versions, organizations, memberships, invitations, keys, applications, logs, and settings remain readable.
- [ ] Verify cache, schema, ownership, and plan-setting writes do not leave stale authorization/contract state.
- [ ] Verify rollback/restore procedure from a tested backup.

## Negative test matrix

Run in staging and record the exact HTTP/FAPI result:

- [ ] Missing credential: 401 / authentication_failed.
- [ ] Malformed Authorization: 401 / malformed_authorization.
- [ ] Conflicting credentials: 401 / conflicting_credentials.
- [ ] Invalid/revoked/expired key: registry-defined 401/403 result.
- [ ] Unknown route/API/version: 404 registry-defined result.
- [ ] Unauthorized organization/API access: 403.
- [ ] Invalid request parameter: 400 validation_failed.
- [ ] Request body disabled: no body requirement, including Enforce mode.
- [ ] Request body enabled with missing/invalid JSON: 400 / invalid_body.
- [ ] Response mismatch in Enforce: 502 / response_schema_validation_failed / FAPI-516.
- [ ] Rate limit exceeded: 429 / rate_limit_exceeded.
- [ ] Upstream unavailable: 502 / upstream_unavailable.
- [ ] Platform disabled: 503 / platform_disabled.
- [ ] IDOR attempts across users/organizations: denied with no data leakage.
- [ ] Legacy query transport: compatibility behavior and Request History transport visibility.
- [ ] X-API-Key and Bearer transport: canonical transport stored/displayed without secrets.

## Runtime certification status

Runtime certification is deferred because the current development environment does not provide an executable WordPress/PHP runtime. The implementation and static security/architecture checks have been completed, but live runtime behavior has not been independently certified.

When a staging runtime is available, execute the Phase 10G contract/capture/authentication/organization/rate-limit matrix and the Phase 10H checklist above. Do not mark those tests PASS based on static inspection.

## Focused launch decisions

- Provider secrets: **DISABLE FEATURE FOR V1** unless external login is required and secrets are managed under an approved operational control. Plaintext WordPress-option storage creates backup/database disclosure impact.
- Request History retention: **IMPLEMENTED** with canonical plan periods (7/30/90/365 days), an allowlisted global safety maximum (365-day default), daily bounded WP-Cron deletion, and an administrator purge action limited to the logs table. Complete the staging acceptance tests before launch.
- Proxy SSRF/DNS rebinding: **DISABLE PROXY/EXTERNAL APIs FOR V1** unless the deployment provides network egress allowlisting and DNS-rebinding protection. Existing URL checks are useful defense-in-depth but are not a complete guarantee.
- Query-string authentication: **KEEP COMPATIBILITY FOR V1**. Bearer and X-API-Key remain preferred; remove query auth only after consumers migrate.

## Deferred work

- Provider-secret encryption or external secret-manager integration with a documented key lifecycle.
- Runtime/staging verification of plan-aware Request History retention and purge controls.
- Network-layer DNS-rebinding protection for proxy targets.
- Query-auth retirement after compatibility consumers migrate.
- Full staging runtime certification and production load/restore testing.






## Temporary retention certification utility

When `WP_DEBUG` is enabled, administrators may use the temporary retention fixture utility on Settings → Request History. It is not available to publishers, normal users, public REST, or gateway requests. Fixtures use real API IDs and the production resolver/cleanup path; remove the utility after certification using the checklist in `Planned.md`.

The temporary retention utility’s scalar fixture-manifest render fatal was corrected. Empty/default settings-page GET rendering is now guarded by explicit manifest normalization; this does not change production retention semantics.

The plan-aware retention cleanup starvation defect was corrected: candidate batches now advance by `(created_at, id)` rather than re-querying the same ineligible rows. The existing 500-row batch and 1,000-row maximum remain unchanged. Runtime retesting of the Admin 300/366-day fixtures is still required.

Retention diagnostic note: the temporary certification path now exposes reliable WP_DEBUG lifecycle markers and safe fixture/deletion metadata so a future WordPress runtime can distinguish action-path, query, eligibility, and database-delete failures. No retention values or production cleanup semantics were changed.
