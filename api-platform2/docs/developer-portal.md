# FreedomAPI Developer Portal

The Developer Portal is the public discovery and integration site at `/developers` (configurable through the Developer route prefix). It is separate from the authenticated publisher Dashboard, API Settings, and Documentation Manager.

## Routes

| Route | Purpose |
| --- | --- |
| `/developers/` | Home, statistics, featured/recent APIs, categories, quick start |
| `/developers/apis/` | Searchable/filterable API directory |
| `/developers/apis/{slug}/` | API detail/landing page |
| `/developers/apis/{slug}/docs/` | Documentation and schema presentation |
| `/developers/apis/{slug}/test/` | Public tester when publisher-enabled |
| `/developers/apis/{slug}/sdk/{language}.zip` | SDK download when enabled |
| `/developers/categories/{category}/` | Category listing |
| `/developers/tags/{tag}/` | Tag listing |
| `/developers/getting-started/` | Consumer integration guide |
| `/developers/dashboard/` | Signed-in consumer workspace |

The portal registers WordPress rewrite rules and query vars at `init`; a route-version change flushes rewrite rules. If routes return 404 after installation or a prefix change, refresh WordPress permalinks after confirming the configured Developer prefix.

## Discovery

The directory searches title, slug, summary, description, tags, and category. Filters include category, tag, authentication type, and Active/Beta/Deprecated public status. Sort options include recent, newest, oldest, alphabetical, and popular. Results are paginated and rechecked against public lifecycle/readiness rules.

## Visibility and publishing requirements

Private APIs are not public. Unlisted APIs support a direct known link but are omitted from directory/search/category/tag/related listings. Public directory entries require Public visibility, active runtime status, Public or Deprecated lifecycle, and readiness: public title, valid API/runtime slug, unique non-reserved portal slug, public version label, active endpoint, configured authentication, and configured runtime. Deprecated entries remain visible with deprecation information.

Publishers configure these values from the authenticated Portal Publishing panel. The public portal reads that metadata plus published Documentation Workspace snapshots and canonical endpoint schemas.

## Detail and docs presentation

Detail pages show API identity, summary, publisher/category/tags, status/version, authentication, runtime, endpoint URL, quick-start snippets, related APIs, support, changelog, and links to docs/tester/SDKs. Documentation includes authentication instructions, endpoints, methods, paths, parameters, request bodies, response status/model tables, example JSON, models, common/gateway errors, rate limits, versions/releases, and publisher workspace sections.

## Public testing and SDKs

Testing appears only when `allow_public_tester` is enabled and the route policy allows it. The tester requires the visitor's own API key, uses nonce-protected AJAX, accepts bounded documented inputs, and does not retain credentials. SDK links appear only for enabled/allowlisted languages and are generated from the published schema.

## Public versus authenticated interfaces

Home, directory, detail, docs, getting started, categories, tags, and enabled tester routes are public. The Developer Dashboard requires login and exposes only the current user's applications, favorites, request totals, SDK activity, and public APIs. Favorite mutations require login and a per-API nonce. None of these routes grants API ownership, organization permissions, publisher editing, or key management.

## Implementation reference

The subsystem is bootstrapped by `modules/developer-portal/developer-portal.php`. Main classes are `APIPlatform_Developer_Portal`, `APIPlatform_Developer_Portal_Query`, `APIPlatform_Developer_Portal_Documentation`, `APIPlatform_Developer_Portal_Publishing`, `APIPlatform_Developer_Portal_Tester`, and `APIPlatform_Developer_Portal_SEO`. Assets are `assets/css/developer-portal.css`, `portal-docs.css`, `portal-tester.css` and matching JavaScript files. Public shell rendering is in `APIPlatform_Frontend_Layout::render_document()`.
