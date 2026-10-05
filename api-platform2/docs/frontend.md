# Frontend Architecture and Layout Decisions

## Authenticated application

`APIPlatform_Frontend_Layout` renders the `.apiplatform` application shell and dispatches the `page` identifier to authenticated feature renderers. `[api_fulllayout_page page="dashboard" size="full"]` is the canonical standalone wrapper. `size="full"` safely breaks out of the narrow WordPress/Divi content container while preserving the surrounding theme header and footer.

## Sidebar and responsive layout

Desktop `.apiplatform` uses the finalized flex layout: sidebar and main content stretch together; the sidebar background is `#0f1117`, active navigation remains blue `#2563eb`, and the column ends with the FreedomAPI application rather than extending into the normal WordPress footer. The sidebar width, navigation spacing, hover styling, and mobile behavior remain separate. Mobile uses the existing stacked/toggle layout and must not inherit arbitrary desktop heights.

## Typography and contrast

Frontend CSS rules are scoped beneath `.apiplatform`. Dark application sections use readable light headings/text; Settings row `<strong>` and form labels use the established light color. Create API wizard cards are light and explicitly use dark `#0f172a` text for H3 and paragraph content. Wizard H3 also resets `-webkit-text-fill-color` to `#0f172a` because an external theme cascade affected glyph painting. These rules do not globally recolor headings, buttons, inputs, headers, or footers.

## Relevant files

- `modules/frontend/assets/css/frontend.css` — shared application, wrapper, section, settings, form, and full-width rules.
- `modules/frontend/assets/css/dashboard.css` — dashboard/Create API wizard styling and scoped contrast correction.
- `modules/assets/css/sidebar.css` — authenticated sidebar/mobile/desktop layout.
- `modules/frontend/classes/class-layout.php` — shell, wrapper pages, sidebar, public document shell.
- `modules/frontend/classes/class-assets.php` — frontend asset loading/versioning.
- `modules/developer-portal/assets/css/*.css` — public portal-specific styling.

Do not replace scoped selectors with global `body`, `h3`, `p`, theme, or Divi rules. Keep full-layout breakout, sidebar stretch, active blue links, and public portal shell boundaries intact.
