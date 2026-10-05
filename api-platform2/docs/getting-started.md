# Getting Started with FreedomAPI V1

## What is FreedomAPI?

FreedomAPI is a WordPress-based platform for creating, publishing, testing, and operating APIs. A publisher creates an API and its endpoints, chooses a runtime, documents the contract, configures authentication, and can expose a public listing through the Developer Portal. Consumers discover public APIs, read documentation, test with their own key, and call the gateway.

## Core concepts

| Concept | Meaning |
| --- | --- |
| API | A publisher-owned `user_api` record with runtime, endpoints, versions, keys, and documentation. |
| Endpoint | A method/path contract with parameters, optional JSON body, response contracts, and runtime behavior. |
| Publisher | The personal owner or organization authorized to configure an API. |
| Consumer | A developer using a published API through its documented gateway endpoint. |
| Gateway | The FreedomAPI request pipeline that resolves, authenticates, validates, executes, logs, and responds. |
| Developer Portal | The public `/developers` discovery and documentation site. |
| Application | A separately managed consumer integration with its own application key and API access grants. |
| Organization | A team ownership boundary with members, roles, invitations, and owner-derived plan entitlements. |

## First steps for a publisher

1. Install and activate the plugin in a supported WordPress site with HTTPS and a working database.
2. Confirm the required wrapper pages in **API Platform → Settings → Frontend Pages**. See [Administration](administration.md).
3. Open the authenticated Dashboard and create an API.
4. Configure at least one active endpoint and a runtime response or proxy target.
5. Create an API key and copy the secret from its one-time display.
6. Call the gateway with `X-API-Key: YOUR_API_KEY` or the documented Bearer form.
7. Add endpoint and response documentation, then use Publisher Settings to choose visibility and publish.
8. Verify the public listing at `/developers/apis/{slug}` if the readiness checks pass.

## Consumer first request

From a public API detail or documentation page, copy the endpoint URL and use the documented method. Recommended header form:

```text
X-API-Key: YOUR_API_KEY
Accept: application/json
```

Query-string authentication remains a compatibility transport, but headers are preferred. Never put a real key in source control, screenshots, URLs, or support messages.

## Where to go next

- Publishers: [Publisher guide](publisher-guide.md)
- API consumers: [Developer guide](developer-guide.md)
- Administrators: [Administration](administration.md)
- Maintainers: [Architecture](architecture.md)
