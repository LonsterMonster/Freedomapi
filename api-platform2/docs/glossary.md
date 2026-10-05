# FreedomAPI Glossary and FAQ

## Glossary

- **API:** A publishable `user_api` resource with runtime, endpoints, versions, keys, and docs.
- **Publisher:** The personal user or organization authorized to manage an API.
- **Consumer:** A developer integrating with a published API.
- **Endpoint:** A method/path contract and its parameters/body/response schema.
- **Gateway:** The routed FreedomAPI pipeline that authenticates, validates, executes, logs, and responds.
- **Runtime:** The internal, REST proxy, webhook proxy, or external HTTP execution mode.
- **API key:** A secret credential presented through a supported transport and verified against a stored hash.
- **Application:** A consumer integration record with separate application keys and API grants.
- **Organization:** A team ownership boundary with members, roles, invitations, access, and plan entitlements.
- **Owner:** The canonical personal or organization owner resolved by `APIPlatform_Ownership_Service`.
- **Developer Portal:** The public `/developers` API discovery, docs, testing, and SDK surface.
- **Request History:** Authorized, sanitized records of gateway requests/responses and validation metadata.
- **Retention:** The plan-aware period after which eligible Request History rows may be deleted.
- **Diagnostics:** Safe operational information used to understand API/runtime behavior without exposing secrets.
- **Lifecycle:** The API state such as draft, testing, ready, public, deprecated, or archived.
- **Version:** A labeled API/schema/documentation release such as `v1`.
- **Automation:** An admin-managed rule or scheduled operational action.

## Frequently asked questions

### Where do I create a required frontend page?

Use API Platform → Settings → Frontend Pages. Create the published page with the exact `[api_fulllayout_page page="IDENTIFIER" size="full"]` shortcode.

### Can I recover an API key later?

No raw secret is intended to be recoverable after the protected one-time display. Regenerate the key and update consumers.

### Why does an API not appear in the portal?

It may be Private/Unlisted or fail readiness: title, unique slug, active status, public version, active endpoint, authentication, runtime, visibility, or lifecycle.

### Do pending invitations use organization seats?

Yes. Active members plus valid pending invitations are the canonical usage. Acceptance does not double count; revoked/expired invitations release the reservation.

### Does Plan Simulation change billing or membership?

No. It changes effective diagnostic entitlement calculations only and is administrator-only.

### Are query API keys recommended?

No. Query transport remains for compatibility; prefer Authorization Bearer or X-API-Key headers.

### Is the public tester anonymous?

The route can be public, but the current tester requires the visitor to supply an API key for the request. It does not store or echo that key.

### Is FreedomAPI compliance certified?

No certification claim is made. Security controls are documented separately from external compliance and operational assurance.
