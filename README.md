# gkeepapi-php

> **This is an unofficial PHP port of [gkeepapi](https://github.com/kiwiz/gkeepapi) by kiwiz.**
> Ported from: gkeepapi v0.17.1 @ `1a94b25c18a7abfdc23d1412091129cd63652877`, gpsoauth v3.0.0

Unofficial, framework-independent PHP client for Google Keep. *Not supported nor endorsed by Google.*

> Google offers an official [Keep API](https://developers.google.com/keep/api) which may be an option for
> Workspace Enterprise accounts. This library uses the unofficial protocol of the Android app instead.

## Installation

Requires PHP 8.2+, ext-openssl and ext-mbstring.

```
composer require ojooss/gkeepapi-php nyholm/psr7
```

The library depends on the PSR interfaces only: bring any PSR-18 client and PSR-17 factories. Google's login endpoint
needs HTTP/1.1 without ALPN, so the optional ext-curl client `Gkeepapi\Http\CurlHttpClient` is the safe choice.

## Quick start

Port of the upstream example:

```php
use Gkeepapi\Http\CurlHttpClient;
use Gkeepapi\Keep;
use Gkeepapi\Node\ColorValue;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$keep = new Keep(new CurlHttpClient($factory, $factory), $factory, $factory);

// Obtain a master token for your account first (see docs/live-check.md); never commit it.
$keep->authenticate('user@gmail.com', $masterToken, deviceId: $deviceId);

$note = $keep->createNote('Todo', 'Eat breakfast');
$note->setPinned(true);
$note->setColor(ColorValue::Red);
$keep->sync();

foreach ($keep->find('breakfast') as $found) {
    echo $found->getTitle(), "\n";
}
```

Getting the master token for production: obtain it once via the browser flow (`oauth_token` cookie exchanged with
`composer master-token`), keep it and the device id in a secret store, and use a dedicated account. Details in
[Authentication in production](docs/usage.md#authentication-in-production).

More in the [usage guide](docs/usage.md): lists, labels, searching, saving state, media, error handling.

## Differences to the Python library

Method names follow the original. Python properties became getters/setters (`$note->setPinned(true)`), `List` is
`ListNode`, regular expressions are `Gkeepapi\Pattern`, the HTTP client is injected. See `UPSTREAM.md` for the
complete list of deviations and the quirks kept on purpose.

## Disclaimer

Google Keep has no public API for this use. The library uses an unofficial, reverse-engineered protocol. It is not a
Google product, is not affiliated with or endorsed by Google, may break at any time, and using it may violate
Google's terms of service. A master token gives full access to the Google account. Use at your own risk.

## Development

PHP and Composer run only in Docker:

```
docker compose up -d --build
docker compose exec app composer install
docker compose exec app composer quality        # tests, Rector, PHPStan, PHPCS
```

Tests never call Google. The manual live check is described in `docs/live-check.md`; releasing in `docs/releasing.md`.
`tools/upstream-check.sh` (weekly in CI) reports changes in the upstream projects.

## Credits and license

MIT. Copyright of the original gkeepapi: Kai Zhong (kiwiz); gpsoauth: Simon Weber. See `LICENSE`, `CREDITS.md` and
`UPSTREAM.md`.
