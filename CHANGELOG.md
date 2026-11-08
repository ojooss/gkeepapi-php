# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).
The upstream version each release is ported from is listed in `UPSTREAM.md`.

## [Unreleased]

### Added

- Initial port of [gkeepapi](https://github.com/kiwiz/gkeepapi) 0.17.1 to PHP 8.2+:
  - node model (`Gkeepapi\Node\*`), enums and exceptions,
  - Android login (`Gkeepapi\Auth\*`, port of gpsoauth 3.0.0),
  - low-level API clients (`Gkeepapi\Api\*`) on PSR-18/PSR-17,
  - the high-level `Gkeepapi\Keep` client (authenticate, sync, find, notes, lists, labels, dump/restore, media links),
  - an optional ext-curl PSR-18 client (`Gkeepapi\Http\CurlHttpClient`).
- Manual tools: `tools/live-check.php`, `tools/master-token.php`, interactive `tools/integration-test.sh` (never run in CI).
- Verified once against the live service with a throwaway account (login, sync, create/delete a note).

### Known limitations

- Reminders are not synced (as in upstream); lists, labels, attachments and sharing are covered by mock tests and
  upstream fixtures only, not yet by a live check.
- See "Known deviations" and "Upstream quirks kept on purpose" in `UPSTREAM.md`.
