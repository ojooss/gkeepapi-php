# Usage guide

Port of the [gkeepapi](https://github.com/kiwiz/gkeepapi) client. Method names follow the Python library; Python
properties became getters/setters (`note.pinned = True` -> `$note->setPinned(true)`, `note.dirty` ->
`$note->isDirty()`). The full list of differences is in `UPSTREAM.md`.

## Setup

```
composer require ojooss/gkeepapi-php nyholm/psr7
```

The library needs a PSR-18 HTTP client and PSR-17 factories, none is hard-wired. Google's Android login endpoint
expects the TLS handshake of the Python original: **HTTP/1.1 without ALPN**, no redirect following (needed for media
links). `Gkeepapi\Http\CurlHttpClient` (ext-curl) does exactly that and is verified against the live service.

```php
use Gkeepapi\Http\CurlHttpClient;
use Gkeepapi\Keep;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();                       // request, response and stream factory
$keep = new Keep(new CurlHttpClient($factory, $factory), $factory, $factory);
```

Logging uses PSR-3 and is off by default: `\Gkeepapi\Log::setLogger($logger)`. The library never logs tokens, e-mail
addresses or note contents - keep that in mind for your own logger too.

## Authentication

You need a **master token** (`aas_et/...`). It gives full access to the Google account: treat it like a password and
use a separate account while experimenting. How to obtain one is described in `docs/live-check.md`
(`composer master-token`).

```php
$keep->authenticate('user@gmail.com', $masterToken, deviceId: $deviceId);
```

Pass a **stable** `$deviceId` (16 hex characters) and keep using it. Without it, an id derived from the host name is
used. `authenticate()` loads the account and syncs. Errors: `LoginException` (e.g. `BadAuthentication`),
`BrowserLoginRequiredException` (Google wants a browser login).

`Keep::login($email, $password)` still exists but is discouraged and often blocked by Google.

### Authentication in production

There is no official way to sign in: the library speaks the unofficial Keep protocol. The master token is obtained
**once** and then reused indefinitely (it stays valid until the password is changed or access is revoked).

1. **Browser flow (recommended):** sign in at <https://accounts.google.com/EmbeddedSetup> in a private window, copy
   the single-use `oauth_token` cookie and exchange it with `composer master-token` (steps in `docs/live-check.md`).
   Store the resulting `GKEEP_MASTER_TOKEN` and `GKEEP_DEVICE_ID` in a secret store (environment variable, vault,
   Docker/Kubernetes secret).
2. **`Keep::login($email, $password)`:** works for some accounts only (typically with an app password, i.e. 2FA
   enabled); many end in `NeedsBrowser` or `BadAuthentication`. If it works, store the result of
   `$keep->getMasterToken()` and do not log in with the password again.
3. The official Google Keep API exists for Google Workspace organisations only (admin approval) and is a different
   API that this library does not support.

Operating advice:

- Use a **dedicated account** for the application and share the notes with it. The master token grants full access to
  the whole Google account.
- Never put the token in the repository, logs, exception messages or error trackers.
- Always pass the same `$deviceId`; a new id on every run looks like a new device to Google.
- Persist the sync state with `dump()` and pass it back as `state:` to avoid a full sync on every start (see
  "Saving state between runs").
- The protocol is unofficial: Google may change it or block the account without notice. Plan for outages, and use
  `tools/upstream-check.sh` to watch for changes in the Python reference library.

## Notes and lists

```php
use Gkeepapi\Node\ColorValue;

$note = $keep->createNote('Todo', 'Eat breakfast');
$note->setPinned(true);
$note->setColor(ColorValue::Red);

$list = $keep->createList('Groceries', [['milk', false], ['eggs', true]]);
$item = $list->add('bread');             // appended
$item->setChecked(true);
$sub = $item->add('whole grain');        // indented under $item

$keep->sync();                           // uploads everything that changed
```

Lists are `Gkeepapi\Node\ListNode` (`List` is a reserved word in PHP). `getItems()`, `getChecked()` and
`getUnchecked()` return items in display order; `sortItems()` re-sorts in place.

Other useful calls: `$note->setTitle()`, `setArchived()`, `trash()`, `untrash()`, `delete()`, `getUrl()`,
`$note->labels->add($label)`, `$note->collaborators->add($email)`, `$note->annotations->getLinks()`,
`$note->getBlobs()`, `getImages()`, `getDrawings()`, `getAudio()`.

## Finding notes

```php
use Gkeepapi\Pattern;

$keep->get($id);                                             // local or server id, or null
$keep->all();                                                // all top-level nodes
$keep->find('shopping');                                     // substring of title or text
$keep->find(new Pattern('/^todo/i'));                        // regular expression (PCRE, with delimiters)
$keep->find(pinned: true, archived: false);
$keep->find(colors: [ColorValue::Red, ColorValue::Blue]);
$keep->find(labels: [$label]);                               // any of the labels; [] = notes without labels
$keep->find(func: fn ($n) => $n instanceof \Gkeepapi\Node\ListNode);
$keep->find(trashed: true);                                  // trashed only (default: not trashed; null: both)
```

## Labels

```php
$label = $keep->createLabel('Work');          // LabelException if it exists (case-insensitive)
$label = $keep->findLabel('work', create: true);
$note->labels->add($label);
$keep->deleteLabel($label->id);
$keep->sync();
```

## Sync model

Nothing is sent until `$keep->sync()`. It uploads all dirty nodes and labels, then pulls changes page by page until
the server says it is done. `$keep->sync(resync: true)` discards local state and reloads everything.
Exceptions: `ResyncRequiredException`, `UpgradeRecommendedException`, `SyncException`, `APIException`
(`getCode()` is the HTTP/API error code, `$error` the decoded error object).

Reminders are not synced (same as upstream). `Gkeepapi\Api\RemindersAPI` is available as a low-level client.

## Saving state between runs

```php
file_put_contents('state.json', json_encode($keep->dump(), JSON_THROW_ON_ERROR));

$state = json_decode(file_get_contents('state.json'), true, flags: JSON_THROW_ON_ERROR);
$keep->authenticate($email, $masterToken, state: $state, deviceId: $deviceId);   // restores, then syncs
```

`state.json` contains your note contents - store it like the data it is. Collaborators are not preserved by
`dump()`/`restore()` (inherited from upstream).

## Media

```php
foreach ($note->getBlobs() as $blob) {
    echo $keep->getMediaLink($blob), "\n";     // time-limited URL from the Location header
}
```

## Low-level APIs

`Gkeepapi\Auth\GpsOAuth` (login against `android.clients.google.com`), `Gkeepapi\Auth\APIAuth` (token manager),
`Gkeepapi\Api\KeepAPI`, `MediaAPI`, `RemindersAPI` mirror the Python classes. Prefer `Keep` unless you know why not.

## Testing your own code

Never call Google from automated tests. Inject a PSR-18 mock client that returns recorded or hand-written JSON;
`tests/Http/RecordingHttpClient.php` and `tests/KeepTestCase.php` in this repository show the pattern.
