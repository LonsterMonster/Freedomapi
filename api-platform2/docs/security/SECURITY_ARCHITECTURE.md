# FreedomAPI V1 Security Architecture

This document describes implemented controls. It is not a compliance certification and does not claim HIPAA, PCI DSS, SOC 2, GDPR, or any other external certification.

## Authentication and key storage

Published API keys are generated as secrets and stored in canonical hash records. New and regenerated keys use `password_hash`/`password_verify` semantics; only a mask/prefix and safe metadata remain available after the protected one-time reveal. The dormant plaintext-key cleanup migration removes obsolete persistent plaintext while preserving equivalent hashes and remains retry-safe. Historical compatibility promotion is bounded and does not log the presented credential.

Credential extraction is centralized with Bearer, X-API-Key, then query compatibility precedence. Basic credentials are not interpreted as published keys. Auth classification and transport are separate; Request History records only transport labels such as `authorization_bearer`, `x_api_key`, or `query`.

Application keys are distinct records and access grants. Regeneration invalidates the previous key according to key status/lifecycle. Key creation and regeneration fail closed when secure persistence fails; the system does not fake success by storing raw secrets.

## Authorization and ownership

`APIPlatform_Ownership_Service` resolves personal/organization ownership. Organization roles and capabilities come from `APIPlatform_Organization_Permissions`; entitlement checks use owner-derived membership plans and the canonical seat calculation. Organization API access, invitations, key management, applications, and publisher mutations use ownership/role/capability checks. Administrators may have the explicit `manage_options` override where the source allows it.

## Request protections

WordPress mutation forms use POST, capability checks, nonces, sanitization, and PRG/redirect patterns where applicable. Organization and publisher actions validate IDs, ownership, role permissions, and stable error codes. Public tester AJAX uses a route-specific nonce, bounded parameters/headers/body, and blocks credential-like headers.

## Data minimization and logging

`APIPlatform_Log_Sanitizer` redacts Authorization, X-API-Key, cookies, secret query/body fields, and nested sensitive payload values before the single log write. Captures are bounded (headers/query, request body, response body) and malformed/unsupported payloads receive safe metadata. UI and exports render stored sanitized values; they never reconstruct credentials. Internal errors expose generic client messages and safe WP_DEBUG metadata only.

## Development-only tools

The retention certification utility requires `WP_DEBUG`, `manage_options`, POST, and nonces and uses only the `retention_test_` prefix. Plan Simulation is administrator-only and feature/nonce gated. Debug diagnostics disappear or reduce when `WP_DEBUG` is false. These gates are not substitutes for production access control.

## HTTPS and operational assumptions

Production must use HTTPS, secure cookies, patched WordPress/PHP/ARMember, server security headers, and redacted centralized logs. Proxy runtimes need network egress controls, HTTPS, target allowlisting, private/reserved-IP protections, no unsafe redirects, bounded timeouts, and response limits. Provider secret encryption and network-layer DNS-rebinding protection remain operational/deferred decisions documented in `PRODUCTION_READINESS.md`.

## Boundaries and non-claims

The controls above reduce credential exposure and enforce application authorization. They do not by themselves certify a regulatory framework, prove a live deployment is configured correctly, or replace staging penetration testing, backup/restore testing, independent review, or incident response procedures.
