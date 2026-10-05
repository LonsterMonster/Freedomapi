# FreedomAPI V1 Documentation

This documentation describes the preserved FreedomAPI V1 production baseline. It is split by audience: customer/publisher guides, API-consumer guides, administrator and operations references, and internal maintainer architecture notes.

## Getting Started

- [What FreedomAPI is and core concepts](getting-started.md)
- [Frequently asked questions](glossary.md#frequently-asked-questions)

## Publisher Guide

- [Publisher guide](publisher-guide.md)
- [Organizations, plans, usage, and Request History](request-history.md)
- [Frontend pages and layout](frontend.md)

## API Consumer Guide

- [Consuming a published API](developer-guide.md)
- [Public Developer Portal](developer-portal.md)

## Administration

- [Administration and WordPress setup](administration.md)
- [Security architecture](security/SECURITY_ARCHITECTURE.md)
- [Database architecture](database.md)

## Architecture

- [Internal architecture](architecture.md)
- [Frontend architecture decisions](frontend.md)
- [Request History and retention](request-history.md)

## Operations

- [Production operations and release workflow](operations.md)
- [Troubleshooting](troubleshooting.md)

## Reference

- [Glossary](glossary.md)
- [Project context and handoff](../PROJECT_CONTEXT.md)
- [Implemented V1 inventory](../Implemented.md)
- [Production-readiness record](../PRODUCTION_READINESS.md)

### Documentation boundaries

Customer-facing pages explain supported behavior without exposing implementation internals. Architecture and security pages include file/class names for maintainers. Examples use placeholders such as `YOUR_API_KEY`; no production credentials belong in documentation.

Runtime certification remains distinct from static documentation. Where the project record says runtime certification was deferred, this documentation does not relabel it as a pass.
