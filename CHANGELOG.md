# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-17

First release.

### Added

- `Client`: the native `tricore` wire protocol over TCP or TLS, with handshake
  feature negotiation and password authentication.
- SQL: `query` and `execute` with `?` placeholders bound **by the server**, plus
  one-request scripts and session transactions (`begin`, `commit`, `rollback`,
  `transaction`).
- Exact parameters: `Decimal` for decimal columns, `Bytes` for BLOBs,
  `DateTimeInterface` and backed enums.
- Cache (keys, lists, sets, hashes, streams), documents with filters and
  aggregation pipelines, vectors, graphs, LLM context export and the admin reads.
- Typed exceptions carrying the server's own error code and, for a redirect, a
  leader hint.
- TLS and mutual TLS through `TlsOptions`.
- No runtime dependencies beyond `ext-json`.

### Security

- A call that needs a capability the server did not grant — server-side
  parameters, session transactions — throws before anything is sent, instead of
  falling back to a weaker behaviour.
- A float that can no longer hold its integer exactly is refused rather than
  sent rounded.
- A frame's declared length is checked against the protocol's ceiling before a
  payload byte is read, so a wrong or hostile peer cannot make this client
  allocate what it claimed.
- A connection that timed out or lost frame alignment is closed rather than
  reused: a late reply can never be read as the answer to the next request.
- TLS verifies certificates by default, requires TLS 1.2 or later, and errors
  name a file's path, never its contents.

[Unreleased]: https://github.com/trinesh14/tricoredb-sdk-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/trinesh14/tricoredb-sdk-php/releases/tag/v0.1.0
