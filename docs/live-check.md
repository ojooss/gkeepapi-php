# Live check against the real Google Keep service

The automated tests never talk to Google. This manual check verifies what mocks cannot: that Google accepts the
login and sync requests this library builds. It is **never** part of CI or `composer quality`.

## Rules

- Use a **throwaway Google account** with a few dummy notes. Never your real account.
- Credentials live only in environment variables of your shell, never in files in this repository.
- The script prints counts and status only - no e-mail address, token or note content. Keep it that way when you
  paste results into issues.
- The write step creates one new note (`gkeepapi-php live check`) and deletes it again. It does not touch existing
  notes. It only runs with `GKEEP_LIVE_WRITE=1`.

## Prerequisites

You need a master token (`aas_et/...`) for the throwaway account. The library does not obtain one on its own.
The most reliable way is the browser flow (the same as in the upstream gkeepapi/gpsoauth documentation):

1. Open <https://accounts.google.com/EmbeddedSetup> in a private browser window and sign in with the throwaway
   account. Confirm with "I agree" when asked.
2. Open the developer tools (F12) -> Application/Storage -> Cookies -> `accounts.google.com`, and copy the value of
   the `oauth_token` cookie (starts with `oauth2_4/`). It is single use and short lived.
3. Exchange it in your own terminal (it prints the master token, so do not run this anywhere it gets logged):

```
export GKEEP_EMAIL='throwaway@gmail.com'
export GKEEP_OAUTH_TOKEN='oauth2_4/...'
docker compose exec -e GKEEP_EMAIL -e GKEEP_OAUTH_TOKEN app composer master-token
```

It prints `GKEEP_DEVICE_ID=...` and `GKEEP_MASTER_TOKEN=...`. Export both and reuse the same device id in every run.
Alternatively `Keep::login()` with the account password works for some accounts but often ends in `NeedsBrowser`
or `BadAuthentication`.

## Run

The tool uses `src/Http/CurlHttpClient.php`, a small PSR-18 client on ext-curl that sends HTTP/1.1 **without ALPN**
(what Google expects from gpsoauth) and does not follow redirects (needed by `MediaAPI`).

```
export GKEEP_EMAIL='throwaway@example.com'
export GKEEP_MASTER_TOKEN='aas_et/...'
export GKEEP_DEVICE_ID='0123456789ab'      # optional but recommended: keep it stable between runs

docker compose exec -e GKEEP_EMAIL -e GKEEP_MASTER_TOKEN -e GKEEP_DEVICE_ID app composer live-check
```

Read-only first. When that works, optionally:

```
docker compose exec -e GKEEP_EMAIL -e GKEEP_MASTER_TOKEN -e GKEEP_DEVICE_ID -e GKEEP_LIVE_WRITE=1 \
    app composer live-check
```

`-e NAME` without a value forwards the variable from your shell, so the secret does not appear in the command line.

## Expected output

```
1. Authenticate and sync (read-only) ...
   OK: <n> notes, <m> lists (<t> trashed), <l> labels
2. Dump / restore round trip (offline) ...
   OK
```

With `GKEEP_LIVE_WRITE=1` additionally steps 3 (create, sync, delete) and 4 (full resync).

## If it fails

| Output | Likely cause |
|---|---|
| `LoginException: BadAuthentication` | wrong/expired master token or e-mail, or Google rejected the TLS handshake (403) |
| `BrowserLoginRequiredException` | Google requires a browser login for this account |
| `LoginException: Auth request failed` / `HTTP request failed: ...` | network or TLS problem; the cURL error is shown |
| `APIException` with code 401 after refreshes | the master token does not grant the Keep scopes |
| `APIException` with another code | Google answered with an error object; the code is in the message |
| `ParseException` / `SyncException` | the service sent data the model does not handle yet - save an **anonymised** payload as a fixture and open an issue |

If `BadAuthentication` appears with a valid token, the handshake is the first suspect (ALPN / HTTP2). Compare with
the same account using the Python library from the same machine to rule out the account.

## Recording the result

Note date, library commit, PHP/cURL/OpenSSL versions and which steps passed in your own notes. Do not record account details.

## Full integration test (interactive)

`tools/integration-test.sh` asks for the account e-mail and a master token (or the browser
`oauth_token` cookie, which it exchanges via `tools/master-token.php`), reads secrets hidden, passes them to
the container only via `docker compose exec -e` and writes nothing to disk except a random, non-secret device id
(`.integration-device-id`, git-ignored). It requires typing `yes` to confirm a throwaway account.

Scenarios (`tools/IntegrationTest.php`): create a note and a list (checked/indented items), verify with a fresh
client, update/archive, label create/attach/find, trash/untrash, dump/restore/resume, full resync, cleanup.
Only objects with a random per-run prefix are changed. Output: PASS/FAIL lines, no secrets or note contents.

    tools/integration-test.sh
