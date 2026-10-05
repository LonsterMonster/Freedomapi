# API Consumer Guide

This guide is for a developer who discovers a public API in FreedomAPI's Developer Portal.

## Find an API

Open `/developers/`, browse `/developers/apis/`, search by name/category/tag/description, and open an API detail page. The directory shows only eligible public APIs. Private APIs never appear; unlisted APIs require a direct known link.

## Read the detail and docs pages

The detail page provides the public title, summary, publisher/category, status/version, authentication type, endpoint URL, runtime label, links, and a quick-start example. The `/docs/` page presents endpoint methods/paths, parameters, request bodies, response contracts/examples, models, errors, rate limits, version/release information, and code examples. Select a published documentation version where available.

## Authentication

Use the method documented for the API. The preferred header is:

```http
X-API-Key: YOUR_API_KEY
```

Bearer authentication is also supported by the gateway when the API key is supplied in the Authorization Bearer form:

```http
Authorization: Bearer YOUR_API_KEY
```

Query-string `api_key=YOUR_API_KEY` remains a compatibility transport where enabled, but it is less desirable because URLs can leak into logs or history. FreedomAPI records transport type, not the credential. Application credentials are separate and follow the published application instructions.

## Requests

Follow the endpoint's method, path, documented parameter location (`query`, `header`, or path metadata), required flag, type, default, example, and validation rules. Send JSON only where the endpoint schema enables a request body. A disabled body is not required, including in Enforce response-validation mode.

## Responses and errors

Responses are JSON or the format documented by the API. Check the HTTP status and body error object. Common gateway outcomes include 400 invalid request/validation, 401 missing or malformed authentication, 403 disabled/forbidden access, 404 unavailable API/endpoint, 429 rate limit exceeded, 502 upstream or response-contract failure, and 500 internal gateway error. The public docs include a gateway error reference and request ID guidance.

## Rate limits

Rate limits can be plan-, API-, key-, or application-scoped. The docs display the publisher's rate-limit summary and the standard `429 rate_limit_exceeded` result. Respect response guidance and retry after an appropriate delay.

## Code examples

Portal docs generate sanitized examples for cURL, JavaScript/fetch, Node.js, PHP, Python, and C#. Replace only `YOUR_API_KEY` with a securely stored credential. Do not commit or paste real keys.

## Public API tester

When the publisher enables public testing, open `/developers/apis/{slug}/test`. Select a documented endpoint/method, provide your own key, add only allowed documented fields, and send. The tester uses the key for that request, does not store or echo it, blocks credential-like custom headers, bounds payloads, and displays status, headers, timing, request ID, size, body, and copyable cURL. It still requires a supplied API key; the publisher's anonymous-testing metadata does not remove that current requirement.

## SDK downloads

The SDK Center and `/sdk/{language}.zip` links appear only when the publisher has enabled the SDK generator and allowlisted a language. Packages are generated from the published canonical schema. A missing link means SDK generation is disabled or the language is unavailable.

## Versions and runtime relationship

Published documentation snapshots/releases describe a versioned contract. Runtime behavior is enforced by the canonical endpoint schema and gateway, not by display text alone. If a published snapshot and current runtime appear inconsistent, use the version selector, check the endpoint URL/version, and contact the publisher with the request ID.
