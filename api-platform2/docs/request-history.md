# Request History and Retention

## Purpose and access

Request History gives authorized publishers/administrators operational visibility into gateway calls: method, endpoint, status, timing, request ID, masked IP, auth classification/transport, safe request/response metadata, and response-contract validation results. The authenticated Logs page enforces the existing user/API ownership and organization permissions. It is not a public portal feed.

## Storage and sanitization

Records are stored in the prefixed `apiplatform_logs` table. `APIPlatform_Log_Sanitizer` redacts credentials, cookies, Authorization, X-API-Key, secret query/body fields, and sensitive nested values before persistence. Header/query/request/response captures are bounded. Historical rows without transport show `Not recorded`; credentials are never displayed.

## Retention policy

Plan periods are canonical in `APIPlatform_Membership_Panel`: Free 7 days, Pro 30, Premium 90, Admin 365. The option `apiplatform_request_history_retention_days` is a platform safety maximum, default 365, allowlisted to 7/14/30/60/90/180/365. Effective retention is the owner plan window constrained by that maximum. Organization APIs resolve the organization owner/plan; retention does not use Plan Simulation by default.

## Cleanup behavior

`APIPlatform_Request_History_Retention` runs from `apiplatform_request_history_retention_cleanup` on a daily WP-Cron schedule and from a nonce/capability-protected administrator manual purge. Cleanup selects old candidates from `created_at`, evaluates each API's effective owner plan, uses bounded 500-row batches, and processes at most 1,000 rows per run. It deletes only eligible log rows; it does not delete APIs, usage, keys, organizations, applications, or unrelated data. A `(created_at, id)` keyset cursor prevents an ineligible candidate batch from starving later rows.

## No-history behavior

An API with no log rows shows the existing empty state. Rows outside the effective retention period remain available until a later cleanup run. WP-Cron depends on site traffic, so a quiet site may need an external scheduler to trigger WordPress Cron.

## Diagnostics and certification record

API Diagnostics and publisher analytics use safe stored metadata and do not bypass authorization. The V1 project record documents the retention boundary certification across Free, Pro, Premium, and Admin/platform maximum behavior, batching, manual cleanup, and development-only fixture controls. The temporary fixture utility is hidden when `WP_DEBUG` is off and is not a production feature.
