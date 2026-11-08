# AGENTS.md

## Project

gkeepapi-php is a framework-independent PHP library (Composer package) that
accesses Google Keep notes through the unofficial Keep protocol. It is a port of
the Python library [gkeepapi](https://github.com/kiwiz/gkeepapi) (MIT).

Scope: functional 1:1 port of gkeepapi, including write operations.
Writing to real notes can destroy data, so anything that
talks to Google for real is manual and opt-in, with a throwaway account.

## Environment

No PHP or Composer is installed on the host - everything runs in Docker.

- Never run `php` or `composer` directly on the host. Use
  `docker compose exec app <command>` once the stack is up (or
  `docker compose run --rm app <command>` before it is).
- Never run containers as root against the bind-mounted project directory
  (e.g. `docker compose run -u root ...`) - it leaves root-owned files on the
  host that the host user can't touch. If a command genuinely needs root
  inside the container, `chown -R $(id -u):$(id -g)` the affected paths from
  a throwaway container afterwards.

## Library design

- The package must stay framework-independent. Depend on PSR interfaces
  (PSR-18 `ClientInterface`, PSR-17 factories, PSR-7 messages, PSR-3 logger)
  and let the caller inject implementations. Don't require a concrete HTTP
  client in `require`; one is allowed in `require-dev` only if tests need it.
- Public API is small and explicit: mark internals `@internal` or `final`, and
  document every public class and method.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
- The Python library `gkeepapi` is the reference for protocol details (endpoints,
  request/response shapes, node types). When behaviour is unclear, read its
  source and the installed code under `vendor/` instead of guessing.

## Secrets and privacy

- Real credentials (Google account mail, master token) belong only in
  environment variables (`GKEEP_EMAIL`, `GKEEP_MASTER_TOKEN`) or a git-ignored
  local env file. Never commit them.
- Never write tokens, mail addresses, or note contents to logs, exception
  messages, test fixtures, or docs. Mark such constructor arguments with
  `#[\SensitiveParameter]`.
- Test fixtures must be anonymised recordings or hand-written payloads.

## Everyday workflow

- Start the stack: `docker compose up -d`
- Install/update dependencies: `docker compose exec app composer install` / `composer update`
- Add a dependency: `docker compose exec app composer require <package>`
  (`--dev` for tooling). Don't hand-edit `composer.json` dependency lists.

## Testing

- Every code change must be covered by tests - a feature isn't done until it
  has a test that exercises it the way a caller would (a call to the public
  API), not just "it didn't throw." Run via `docker compose exec app composer test`
  (PHPUnit).
- **Never call Google live from tests or CI.** Use PSR-18 mock clients with
  recorded or hand-written responses. Live checks are manual only, with a
  throwaway account, and are documented rather than automated.
  The manual check is `docker compose exec -e GKEEP_EMAIL -e GKEEP_MASTER_TOKEN app composer live-check`
  (see `docs/live-check.md`). Agents must not run it and never read or print credentials.
  The same applies to the interactive `tools/integration-test.sh` (see `docs/live-check.md`).
- The quality gate is tests, then an automatic Rector fix pass, then
  PHPStan, then PHPCS. Run `docker compose exec app composer quality`
  first. Composer stops the script at the first failing step, so if it
  stops early, run each remaining step individually:
  `composer test`, `composer rector-fix`, `composer stan`, `composer cs`
  (each via `docker compose exec app`).
- `composer rector-fix` applies upgrades instead of just reporting them. It
  runs before PHPStan/PHPCS so those check the code Rector actually leaves
  behind. If Rector changed files, review the diff before committing.
- The gate passes only when tests, PHPStan, and PHPCS all pass. Report
  failures for each check separately; a later passing check does not override
  an earlier failure.
- Run the full quality gate after every code change, before considering it
  done.

## Code style

PHP_CodeSniffer enforces PSR-12 via `phpcs.xml.dist`. Run
`docker compose exec app composer cs` to check and
`docker compose exec app composer cs-fix` to apply PHPCBF fixes.

## Language

Code (variable/class/function/method names, etc.) and code comments are in
English, even though conversations with the user may be in a different
language. Commit messages are also in English.

## Discover, don't guess

Library APIs and the Keep protocol change; your training data may be stale.
Look things up instead of relying on memory:

- Read the installed source and docblocks under `vendor/`.
- For protocol questions, read the Python `gkeepapi` source and its changelog.
- `docker compose exec app composer show` lists installed versions.
