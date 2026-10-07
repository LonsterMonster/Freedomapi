# Sovereign data platform for FreedomAPI

| Install on | Plugin |
| --- | --- |
| Central data WordPress site | `plugins/sovereign-data-host` |
| Existing FreedomAPI site alongside Core | `plugins/sovereign-freedomapi-bridge` |
| Each client WordPress site | `plugins/sovereign-wp-connector` |

Download [the three-plugin bundle](../downloads/FreedomAPI-3-Plugin-Bundle.zip), extract it, and read START-HERE.txt. Upload the three individual plugin ZIPs, not the whole bundle. See [full setup](SETUP.md).

Core in this repository already includes the required cache and logging privacy changes. Install updated Core on staging before enabling the bridge; do not apply the same patch twice. The core-patch directory preserves the patch snapshot for older installations. Existing gateway authentication and rate limits remain in place.

40 PHP 8.1 runtime contract checks passed using WordPress doubles and SQLite. A live three-site WordPress test is still required. The test report and bundle describe the kit before this GitHub commit. This commit does not deploy to WordPress.
