# Sovereign integration with your existing FreedomAPI — 0.2.0

This package is based on the actual source in `LonsterMonster/Freedomapi`, commit `e8067ed44a38914621c1be0d6db32004c87d310f`. It uses the current Core gateway callback API, API-key authentication, ownership permissions, endpoint schema service and rate limits. It does not alter FreedomAPI AI. No GitHub commits or production deployment were performed.

## Architecture

- Data-host WordPress: central accounts, consent and personal data.
- Existing FreedomAPI WordPress: native Core gateway plus Sovereign Bridge.
- Participating WordPress: Sovereign Connector with configurable server URLs and platform API key.

The bridge replaces the separate relay plugin from test kit 0.1.0. It is not a new standalone API service. Central OAuth-style identity/consent remains on the data host; the API gateway's existing platform authentication remains on FreedomAPI.

## Requirements

Three publicly reachable HTTPS WordPress staging installations, WordPress 6.5+, PHP 8.1+, OpenSSL, pretty permalinks and Authorization header forwarding. Your WordPress installation must detect HTTPS correctly. Private localhost/internal URLs and invalid certificates are blocked by WordPress safe HTTP requests.

Exclude the user vault, connector page, authorization/callback paths and the dedicated gateway API from all full-page/CDN caches. PHP no-cache headers cannot override a response served before WordPress runs.

## 1. Apply two Core privacy patches on staging

Back up your database and current plugin folder first. Compare `core-patch/source-checksums.json` with your installed files. If your installed source differs, merge `core-patch/changes.patch` into your current code instead of overwriting it.

Using your hosting file manager or SFTP, copy these replacement files from the kit to the same paths in your existing plugin:

- `wp-content/plugins/api-platform2/modules/helpers/gateway.php`
- `wp-content/plugins/api-platform2/modules/helpers/storage.php`

These are replacement source files, not a complete plugin ZIP. Keep the rest of your existing Core plugin. The gateway adds a compatibility marker and bypasses shared cache for marked personal-data APIs. Storage keeps operational status/timing but omits request/response payloads for these APIs. It also suppresses authorization-code exchange payloads and requests carrying a user access token, including failed routing/authentication attempts.

The sensitive flag persists even if the bridge is deactivated. Ordinary APIs keep their existing cache and history behavior. Existing historical log rows are not retroactively changed. Core updates can overwrite these patches: reapply/merge them, and verify staging before reconnecting users. The bridge refuses to register its runtime if the compatibility marker is missing.

## 2. Data site

Upload `sovereign-data-host-0.1.0.zip` from `installable-zips` and activate it on your data WordPress site. Create a page containing `[sovereign_vault]`.

Create normal test-user accounts using WordPress Users → Add New. Public self-registration is a WordPress setting and is not enabled automatically. Users enter data manually; it is private until consent is granted.

## 3. Existing FreedomAPI site

1. Deactivate the older `Sovereign FreedomAPI Relay` if installed. Keep Core and FreedomAPI AI.
2. Upload and activate `sovereign-freedomapi-bridge-0.2.0.zip`.
3. In Core, create a NEW dedicated API for the data connection. Do not reuse an API serving other content.
4. Obtain its `user_api` post ID from the edit URL or WordPress admin.
5. In Settings → Sovereign Bridge, enter that API ID and the data site's HTTPS WordPress root URL.
6. Click Configure this dedicated data API. This explicitly replaces the selected API's endpoint schema with `/token`, `/userinfo`, `/data` GET/POST and `/revoke`, sets the `sovereign` runtime and disables caching.
7. Publish/activate the API and its version using your usual Core controls. The bridge does not publish automatically.
8. Obtain a Core platform API key permitted to execute this dedicated API. Prefer a separate key/application for each participating site, with the appropriate Core access grant. Do not paste a WordPress password in this field.
9. Copy the public gateway URL shown by Core, then append its version label. Example only: `https://www.freedomapi.net/gateway/YOUR-PUBLISHER/personal-data/v1`.

Core allows a configurable gateway prefix; use its actual displayed URL. This kit targets WordPress Core native gateway routes. It does not change or certify your separate Node/PM2 gateway or its `/grok/...` aliases. For the initial test, connect directly to the WordPress Core gateway URL.

Do not apply AI response transformations to this dedicated data API. The bridge fails closed if one is active. Ordinary Core edits may reset the custom runtime or schema; reconfigure the dedicated API afterward. Reuse its existing version and Core lifecycle controls.

## 4. Participating WordPress site

1. Upload and activate `sovereign-wp-connector-0.2.0.zip`.
2. Create a page containing `[sovereign_connect]`.
3. Go to Settings → Sovereign Connector and copy the exact callback URL shown there.
4. On the DATA SITE, Settings → Sovereign Host, register the participating site with that exact callback URL, a descriptive name and purpose. For a first test, allow `identity`, `read:interests`, `write:interests`.
5. Save the generated central Client ID and Client Secret. The secret is displayed once.
6. Configure the connector:
   - Data site root URL: canonical WordPress home URL, e.g. `https://data.example.com/`.
   - FreedomAPI API base: Core's native gateway URL including the version.
   - FreedomAPI platform API key: the Core-issued key permitted for the dedicated data API.
   - Central Client ID and Client Secret: from the data host application registration.
   - Requested scopes: identity + selected read/write scopes.

The platform key and central user token are DIFFERENT credentials. The connector sends the platform key to Core as `Authorization: Bearer ...`, and the central user token as `X-Access-Token`. Core validates the platform key before invoking the bridge; the bridge sends only the USER token to the data host as its bearer credential. The platform key is not forwarded upstream.

## 5. Test end to end

1. Save interests on the central data-site vault page as a normal test user.
2. On the connector page, begin central sign-in, approve only reading interests, and return.
3. Confirm a subscriber account is linked/created on the participating site and only approved data appears.
4. Confirm the call appears in your EXISTING Core request history with status/timing but no personal payloads or credentials.
5. Revoke the grant from the data host and reload the connector. Access must be denied, even if Core caching settings are switched on accidentally.
6. Reconnect and approve both reading and collecting interests. Submit an explicit update; confirm central data and its activity record change.
7. In two tabs, submit an update and then submit an older revision. Confirm the stale update is rejected with HTTP 409.
8. Revoke/disable the Core platform key: requests must fail at Core before reaching the data host.
9. Disable the central application: existing central tokens must fail even when the platform key remains valid.
10. Repeat with a second user to verify account/data isolation.
11. Confirm an ordinary unrelated API still executes and its logs/cache behave as before.

Use the API endpoints through the native public gateway URL; Core's older single-endpoint `/wp-json/platform/v1/api/...` alias cannot distinguish this multi-endpoint flow and the bridge intentionally rejects that alias.

## Identity and consent behavior

Passwords stay on the central data site. Sign-in uses an authorization-code flow with S256 PKCE, exact redirects, a client secret, a browser-bound state cookie and one-use codes. User identity is linked by issuer + subject, never email guessing. Anonymous central sign-in does not access local administrator accounts. Local administrators must sign in locally before explicitly linking.

Read and write/collection permissions are independent. The user selects consent for 7/30/90 days. Reconnecting replaces earlier grants for the same user/application. Tokens expire after at most seven days, so reconnect when necessary; no refresh tokens are implemented. Revoking blocks future API access, not copies already stored by other sites.

This is a custom interoperable flow between these plugins, not a full OpenID Connect provider: no ID tokens, OIDC discovery or third-party OIDC certification.

Collection is explicit user-submitted text. Automatic analytics/purchase/browsing collection, background sync, payments/data sales, public business onboarding and the connection of the ChatGPT-hosted Sovereign dashboard are not included.

## Verification and rollback

40 PHP 8.1 runtime contract checks passed using WordPress doubles and SQLite, including the actual patched Core runtime/cache/logger source. Live WordPress activation, real MySQL/dbDelta, themes, cookies/redirects, application access management, Node gateway routing and CDN behavior are not runtime-certified here.

Rollback: deactivate the bridge and connector. Restore the TWO backed-up Core files if needed. The dedicated API's schema/runtime conversion is separate from the Core patches; remove that dedicated test API or restore its pre-test metadata/database backup. Restore normal cache/history settings only if the API no longer serves personal data. Central grants/data remain until explicitly removed; deactivation does not erase them.

Secrets/tokens are encrypted with a WordPress-salt-derived key on the connector. Salt changes require re-entering keys/secrets and reconnecting. Database administrators can read central personal data; this is not end-to-end encryption.
