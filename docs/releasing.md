# Releasing

Nothing here is automated: tagging and publishing are deliberate steps by the maintainer.

1. `docker compose exec app composer quality` is green (CI matrix PHP 8.2/8.3/8.4 as well).
2. If the protocol code changed: run the live check (`docs/live-check.md`) against a throwaway account.
3. Make sure `UPSTREAM.md` and `src/Upstream.php` name the ported upstream version and commit
   (`tools/upstream-check.sh` reports whether upstream moved on).
4. Move the `[Unreleased]` notes in `CHANGELOG.md` to a new `## [x.y.z] - YYYY-MM-DD` section.
5. Tag `x.y.z` (own semantic version, see "Versioning" in `UPSTREAM.md`) and push the tag.
6. First release only: submit the repository on packagist.org and enable the GitHub webhook. Later tags are picked
   up automatically.

Do not tag a version as `0.17.1-php.N`: Composer rejects that suffix.
