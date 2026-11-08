# Upstream

| | |
|---|---|
| Repository | https://github.com/kiwiz/gkeepapi |
| Ported version | 0.17.1 |
| Ported commit | 1a94b25c18a7abfdc23d1412091129cd63652877 |

| gpsoauth version | 3.0.0 |
| gpsoauth commit | ba19f443442ade9bd6d52a179c8dbf50bc861b1e |

Keep in sync with `src/Upstream.php`. `tools/upstream-check.sh` (run weekly by `.github/workflows/upstream-watch.yml`)
compares these values with upstream and reports relevant changes.

## Versioning

This package has its own semantic version (`0.1.0`, `0.1.1`, ...). The upstream version it is ported from is stated
here, in the README and in the changelog. The schema `<upstream>-php.<n>` is **not** used:
Composer/Packagist reject the suffix `-php.1` as an invalid version.

## File mapping (Python -> PHP)

| Upstream | PHP |
|---|---|
| `src/gkeepapi/__init__.py` | _planned: `Keep`, `Api\*`, `Auth\*`_ |
| `src/gkeepapi/node.py` (enums) | `src/Node/*.php` enums (done) |
| `src/gkeepapi/node.py` (classes) | `src/Node/*.php` (done; `List` -> `ListNode`, module `from_json` -> `NodeFactory::fromJson`) |
| `test/test_nodes.py` | `tests/Node/*Test.php` (done) |
| `test/data/keep-00` | `tests/data/keep-00.json` (done) |
| `src/gkeepapi/__init__.py` (`APIAuth`) | `src/Auth/APIAuth.php` (done) |
| gpsoauth 3.0.0 `__init__.py`, `google.py`, `util.py` | `src/Auth/GpsOAuth.php`, `src/Auth/GoogleKey.php` (done) |
| `src/gkeepapi/__init__.py` (`API`, `KeepAPI`, `MediaAPI`, `RemindersAPI`) | `src/Api/*.php` (done) |
| `src/gkeepapi/__init__.py` (`Keep`) | `src/Keep.php`, `src/Pattern.php` (done) |
| `test/test_client.py` | `tests/KeepTest.php` (done, extended) |
| `src/gkeepapi/exception.py` | `src/Exception/*` (done) |

## Known deviations

- Python `Enum` classes are PHP string-backed `enum`s with identical case names and wire values.
- `APIException.code` is exposed via `getCode()`; `BrowserLoginRequiredException` has a fixed message (upstream sets none).
- `List` is named `ListNode` (`list` is a reserved word in PHP).
- Python properties become `getX()`/`setX()`, `isX()` for booleans (`dirty` -> `isDirty()`, `trashed` -> `isTrashed()`).
  `load()`/`save()` keep their names; internal `_load` is the protected `doLoad`.
- Datetimes are `DateTimeImmutable` (UTC). `NodeTimestamps` formats with microseconds like upstream.
- `NodeCollaborators::load()` takes the collaborator list and the share requests as two arguments, as upstream.
- Unknown annotation types inside `NodeAnnotations`/`Context` are skipped (upstream crashes with `AttributeError`/`None`).
- Python's module loggers are replaced by a PSR-3 logger set via `Gkeepapi\Log::setLogger()` (silent by default).
  The upstream `DEBUG` flag and `_find_discrepancies` are not ported.
- `MergeException` gets a fixed message with the node id instead of the raw dict.
- `NodeImage::getUrl()` throws `BadMethodCallException` (upstream: `NotImplementedError`).
- `Keep` takes the PSR-18 client and PSR-17 factories in its constructor; `re.Pattern` is replaced by
  `Gkeepapi\Pattern` (a PCRE pattern with delimiters).
- `Keep::find()` returns a list instead of a generator. `all()` only returns `TopLevelNode`s.
- Without an explicit `$deviceId`, an id derived from the host name is used (upstream: the MAC address). Pass a stable
  id of your own and keep using it.
- Reminders are not synced, exactly like upstream (`_sync_reminders` is commented out there); `RemindersAPI` exists
  but `Keep` does not call it. `_clean`/`DEBUG` are not ported.
- Inconsistent server data raises `ParseException`/`SyncException` (nodes without `id`, unknown parent, missing
  `toVersion`) where upstream fails with `KeyError`/`AttributeError`. A missing `truncated` counts as `false`.
- `RecordingHttpClient`-style mocks are used in tests instead of patching `requests`.

## Upstream quirks kept on purpose

- `TopLevelNode::save()` writes collaborators as `collaborators`, `load()` reads `roleInfo` (so they do not round-trip).
- Labels loaded from a node are unresolved placeholders; `NodeLabels::save()` stamps them as deleted "now".
- gpsoauth is ported into this package (`Gkeepapi\Auth\GpsOAuth`) instead of being a dependency; the HTTP client is an
  injected PSR-18 client. gpsoauth's custom TLS setup (no ALPN, session tickets allowed, urllib3 ciphers) cannot be
  expressed through PSR-18: the injected client must be configured for it (see README).
- RSA-OAEP uses ext-openssl; the key is converted to PEM by hand (`GoogleKey::toPem()`).
- `APIAuth::refresh()` fails with `LoginException` when `Auth` is missing (upstream checks `Auth`/`Token` but then
  reads `Auth`, raising `KeyError` if only `Token` is present).
- `APIAuth` raises `LoginException` with the `Error` (and `ErrorDetail`) text only, never with tokens.
- `API::send()` takes `(url, method, json)` instead of Python `**req_kwargs`; `new \stdClass()` as `json` sends `{}`.
- Rate limiting (`429`) is retried with the upstream backoff (2s doubling up to 60s) but at most
  `API::RATE_LIMIT_RETRIES` (10) times; upstream retries forever.
- Responses that are not JSON raise `APIException` (upstream: the HTTP library's JSON error).
- The `User-Agent` identifies this package (`x-gkeepapi-php`) instead of `x-gkeepapi/<version>`.
- `APIException` additionally exposes the decoded error object as `$error`; its message is the server's
  `error.message` (upstream passes the dict).
- `MediaAPI::get()` relies on the `Location` header of the redirect; the injected PSR-18 client must not follow
  redirects. It rejects blobs without server ids before sending (upstream: `TypeError`).
- `Context::save()` emits `{}` (empty object) for an empty context, as Python's dict does, instead of `[]`.
- `NodeDrawing::getDrawingInfo()` was added (upstream reads the private `_drawing_info`).
- `Keep::dump()`/`restore()` go through `save(false)`/`load()` and therefore lose collaborators (see above).
- `Keep::sync()` clears the dirty flags while building the request; if the request fails, local changes are no longer
  marked dirty and are not retried.
- Deleting a node on the server marks its parent dirty (`remove()` touches the parent), so the parent is uploaded on
  the next sync.
