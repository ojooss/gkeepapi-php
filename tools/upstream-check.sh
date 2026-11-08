#!/usr/bin/env bash
# Compare the upstream state recorded in UPSTREAM.md with the current upstream repositories.
#
# Needs the GitHub CLI (`gh`) with API access (GH_TOKEN in CI). Read-only: only public GitHub API calls.
#
# Exit codes: 0 = nothing relevant changed, 10 = relevant upstream changes (markdown report on stdout), 1 = error.
set -euo pipefail

file="${1:-UPSTREAM.md}"
repo="kiwiz/gkeepapi"
gps_repo="simon-weber/gpsoauth"

ported_commit="$(grep -E '^\| Ported commit' "$file" | grep -oE '[0-9a-f]{40}' | head -1 || true)"
gps_version="$(grep -E '^\| gpsoauth version' "$file" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
if [ -z "$ported_commit" ] || [ -z "$gps_version" ]; then
    echo "Could not read the ported commit / gpsoauth version from $file" >&2
    exit 1
fi

map_file() {
    case "$1" in
        src/gkeepapi/__init__.py) echo "src/Keep.php, src/Api/*, src/Auth/APIAuth.php" ;;
        src/gkeepapi/node.py) echo "src/Node/*" ;;
        src/gkeepapi/exception.py) echo "src/Exception/*" ;;
        test/test_nodes.py) echo "tests/Node/*" ;;
        test/test_client.py) echo "tests/KeepTest.php" ;;
        test/data/*) echo "tests/data/*" ;;
        *) echo "(no mapping)" ;;
    esac
}

branch="$(gh api "repos/$repo" --jq .default_branch)"
head="$(gh api "repos/$repo/commits/$branch" --jq .sha)"
gps_latest="$(gh api "repos/$gps_repo/tags" --jq '.[0].name')"

report=""
changed=0

if [ "$ported_commit" != "$head" ]; then
    files="$(gh api --paginate "repos/$repo/compare/$ported_commit...$head" --jq '.files[].filename' \
        | grep -E '^(src/gkeepapi/|test/)' | sort -u || true)"
    if [ -n "$files" ]; then
        changed=1
        report+="### gkeepapi ($repo)"$'\n\n'
        report+="Ported commit \`$ported_commit\`, upstream \`$branch\` is at \`$head\`."$'\n'
        report+="[Compare](https://github.com/$repo/compare/$ported_commit...$head)"$'\n\n'
        report+="| Changed upstream file | Port target |"$'\n'"|---|---|"$'\n'
        while IFS= read -r f; do
            report+="| \`$f\` | $(map_file "$f") |"$'\n'
        done <<< "$files"
        report+=$'\n'
    fi
fi

if [ "$gps_latest" != "$gps_version" ]; then
    changed=1
    report+="### gpsoauth ($gps_repo)"$'\n\n'
    report+="Ported version \`$gps_version\`, latest tag is \`$gps_latest\`. "
    report+="[Compare](https://github.com/$gps_repo/compare/$gps_version...$gps_latest)"$'\n\n'
fi

if [ "$changed" -eq 0 ]; then
    echo "Up to date with upstream (gkeepapi $ported_commit, gpsoauth $gps_version)." >&2
    exit 0
fi

cat <<REPORT
The upstream projects changed since the last port. Review the changes, port what is relevant (never translate
authentication/protocol code without review), then update \`UPSTREAM.md\`, \`src/Upstream.php\` and the changelog.

$report
Checklist: port code - port tests/fixtures - \`composer quality\` - update \`UPSTREAM.md\` / \`src/Upstream.php\` -
changelog.
REPORT
exit 10
