#!/usr/bin/env bash
# Bumps the version in the three places that must stay in sync (docs/02
# §5): the plugin header, the GR_VERSION constant, and the readme Stable
# tag. Fails loudly before editing when the target is malformed or already
# current, and re-verifies all three edits afterwards so a half-bumped tree
# never ships. The POT is regenerated at release time rather than patched.
#
# Usage: tools/bump-version.sh <x.y.z>

set -euo pipefail

cd "$(dirname "$0")/.."

new="${1:-}"
if [[ ! "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "usage: tools/bump-version.sh <x.y.z>" >&2
    exit 1
fi

current="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' plugin/greenpng.php | head -n 1)"
if [[ -z "$current" ]]; then
    echo "bump-version: cannot read current Version from plugin/greenpng.php" >&2
    exit 1
fi
if [[ "$current" == "$new" ]]; then
    echo "bump-version: already at ${new}" >&2
    exit 1
fi

# perl over sed -i so the script behaves identically on BSD and GNU.
NEW="$new" perl -pi -e 's/^(\s*\*\s*Version:\s+)\d+\.\d+\.\d+$/$1$ENV{NEW}/' plugin/greenpng.php
NEW="$new" perl -pi -e "s/^(\s*define\( 'GR_VERSION', ')\d+\.\d+\.\d+(' \);)/\$1\$ENV{NEW}\$2/" plugin/greenpng.php
NEW="$new" perl -pi -e 's/^(Stable tag: )\d+\.\d+\.\d+$/$1$ENV{NEW}/' plugin/readme.txt

if ! grep -qE "^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+${new}$" plugin/greenpng.php; then
    echo "bump-version: header Version was not updated" >&2
    exit 1
fi
if ! grep -qF "define( 'GR_VERSION', '${new}' );" plugin/greenpng.php; then
    echo "bump-version: GR_VERSION was not updated" >&2
    exit 1
fi
if ! grep -qE "^Stable tag: ${new}$" plugin/readme.txt; then
    echo "bump-version: readme Stable tag was not updated" >&2
    exit 1
fi
if grep -qF "define( 'GR_VERSION', '${current}' );" plugin/greenpng.php; then
    echo "bump-version: old version ${current} still present" >&2
    exit 1
fi

echo "bumped ${current} -> ${new} (header, GR_VERSION, Stable tag)"
echo "reminder: regenerate plugin/languages/greenpng.pot before release"
