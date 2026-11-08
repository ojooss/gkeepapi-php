#!/usr/bin/env bash
# Interactive full integration test against the REAL Google Keep service.
# Manual only - never run in CI. Use a THROWAWAY Google account (see docs/live-check.md).
#
# Credentials are read from the terminal (secrets hidden), passed to the container
# only via `docker compose exec -e` and never written to disk.
set -euo pipefail
cd "$(dirname "$0")/.."

cat <<'TXT'
=== gkeepapi-php integration test (real Google Keep) ===
This creates, changes and deletes notes/labels with a unique test prefix.
Existing notes are not touched, but ONLY use a throwaway account.
TXT

read -r -p "Is this a throwaway Google account? Type 'yes' to continue: " confirm
[ "$confirm" = "yes" ] || { echo "Aborted."; exit 1; }

if [ -z "${GKEEP_EMAIL:-}" ]; then
  read -r -p "Google account e-mail: " GKEEP_EMAIL
fi
[ -n "$GKEEP_EMAIL" ] || { echo "E-mail required." >&2; exit 2; }

DEVICE_FILE=".integration-device-id"
if [ ! -s "$DEVICE_FILE" ]; then
  # A stable, random device id (not a secret); git-ignored.
  head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$DEVICE_FILE"
fi
GKEEP_DEVICE_ID="$(cat "$DEVICE_FILE")"

if [ -z "${GKEEP_MASTER_TOKEN:-}" ]; then
  cat <<'TXT'

Authentication: a master token is required (password login mostly fails with
BadAuthentication/NeedsBrowser). Choose:
  1) I already have a master token
  2) I have the browser 'oauth_token' cookie (https://accounts.google.com/EmbeddedSetup)
TXT
  read -r -p "Choice [1/2]: " mode
  case "$mode" in
    1)
      read -r -s -p "Master token (hidden): " GKEEP_MASTER_TOKEN; echo
      ;;
    2)
      read -r -s -p "oauth_token cookie (hidden): " GKEEP_OAUTH_TOKEN; echo
      # Strip whitespace, CR and quotes that sneak in when pasting from the browser.
      GKEEP_OAUTH_TOKEN="$(printf '%s' "$GKEEP_OAUTH_TOKEN" | tr -d ' \t\r\n"'"'"'')"
      echo "(cookie length: ${#GKEEP_OAUTH_TOKEN} characters)"
      out="$(docker compose exec -T \
        -e GKEEP_EMAIL="$GKEEP_EMAIL" -e GKEEP_OAUTH_TOKEN="$GKEEP_OAUTH_TOKEN" \
        -e GKEEP_DEVICE_ID="$GKEEP_DEVICE_ID" app php tools/master-token.php)" \
        || { echo "Token exchange failed." >&2; exit 1; }
      unset GKEEP_OAUTH_TOKEN
      GKEEP_MASTER_TOKEN="$(printf '%s\n' "$out" | sed -n 's/^GKEEP_MASTER_TOKEN=//p')"
      ;;
    *) echo "Invalid choice." >&2; exit 2 ;;
  esac
fi
[ -n "$GKEEP_MASTER_TOKEN" ] || { echo "No master token." >&2; exit 2; }

echo
docker compose exec -T \
  -e GKEEP_EMAIL="$GKEEP_EMAIL" -e GKEEP_MASTER_TOKEN="$GKEEP_MASTER_TOKEN" \
  -e GKEEP_DEVICE_ID="$GKEEP_DEVICE_ID" app php tools/integration-test.php
