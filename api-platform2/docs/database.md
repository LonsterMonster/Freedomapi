# Database Architecture

The canonical installer is `modules/helpers/database.php`. It defines `APIPLATFORM_DB_VERSION` as `1.8.5`, uses WordPress `dbDelta`, and creates tables with the WordPress prefix. Table names below are logical names; production names include the configured prefix.

## Core tables

| Table | Responsibility and important indexed columns |
| --- | --- |
| `apiplatform_logs` | Request History: user/API IDs, endpoint/method/IP/status/timing, application/access IDs, auth type/transport, sanitized request/response captures, validation metadata, request ID, `created_at`. Indexes include API/user/auth/transport/validation/request ID/created_at. |
| `apiplatform_transactions` | User/API amounts, type, description, timestamp; indexed user/API/date. |
| `apiplatform_rules` | Automation rule trigger/condition/action data; indexed condition/action/date. |
| `apiplatform_portal_apis` | Public registry row keyed uniquely by `api_id` and `portal_slug`, with visibility/category/updated/published timestamps. |
| `apiplatform_lifecycle_events` | API lifecycle transitions, actor, previous/new state, event/date indexes. |

## Applications

`apiplatform_developer_applications` stores application owner, name/slug, environment/status, URLs, usage dates. `apiplatform_application_keys` stores application key hash/prefix/mask, status, expiry/revocation dates and application/owner indexes. `apiplatform_application_api_access` uniquely maps an application to an API and stores access status/grant/revocation data. `apiplatform_application_events` stores actor, application/API/key event history.

## Documentation and identity

`apiplatform_documentation_snapshots` stores API/version snapshot UID, state, serialized snapshot, publisher, publication/archive dates. `apiplatform_documentation_releases` stores release title/summary/date/type, breaking-change flag, migration notes, entries, and authors. `apiplatform_documentation_events` stores snapshot/release-related audit events. `apiplatform_external_identities` uniquely maps provider/provider-user identity to a WordPress user and stores normalized profile metadata and login timestamps.

## Organizations

`apiplatform_organizations` stores name/slug, description/logo/site, status, compatibility plan fields, creator, and timestamps. `apiplatform_organization_members` uniquely maps organization/user and stores role/status/join/suspension metadata. `apiplatform_organization_invitations` stores organization/email/role, hashed token, pending/accepted/revoked status, expiry, inviter/acceptance timestamps. `apiplatform_organization_api_access` uniquely maps API/user access within an organization and stores permissions. `apiplatform_organization_events` stores actor, subject, event, metadata, and timestamp.

## WordPress posts and metadata

`user_api` is the primary API post type. API configuration, ownership (`apiplatform_owner_type`, `apiplatform_owner_id`, creator), runtime, status, portal settings, keys, schema, and compatibility fields are stored in post meta as implemented by the feature services. WordPress users provide membership identity; user meta stores selected organization context and plan-simulation state. The optional `documentation` post type is registered by the documentation system when enabled.

## Keys and sensitive storage

Published API-key records are stored in API post meta as hashed `api_keys` records with masks/prefixes and lifecycle metadata; application keys are hashed in their custom table. The database should never be treated as a place for plaintext production credentials. Request/response capture is sanitized before insertion into `apiplatform_logs`.

## Installer, migrations, and relationships

Activation calls `apiplatform_install_database(true)`. Auto-heal runs on `init` when the stored version is behind. Migrations/backfills are idempotent and completion markers advance only after required writes succeed; schema version and migration options are WordPress options. Tables use logical foreign-key relationships through IDs, but the installer relies on existing WordPress/dbDelta behavior rather than destructive foreign-key rewrites.

Do not truncate or drop tables as a troubleshooting step. Back up and test restore before upgrades.
