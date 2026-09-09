#!/usr/bin/env bash
# Builds the WordPress.org upload package from plugin/ (docs/02 §5). The
# repository keeps every dev-only tree (vendor/, tests/, docs/, tools/)
# outside plugin/, so staging is a straight copy with defensive exclusions;
# dist/ is gitignored and zips are never committed (AGENTS.md §7).
#
# Usage: tools/build-zip.sh [--strict]
#   --strict  fail when bundled data files or their NOTICE are absent
#             (release mode, enforced fully at T8; without it this only warns
#             because the data files arrive with the geoip and UA-engine
#             phases).

set -euo pipefail

cd "$(dirname "$0")/.."
repo_root="$(pwd)"

version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+)$/\1/p' plugin/greenpng.php | head -n 1)"
if [[ -z "$version" ]]; then
    echo "build-zip: cannot read Version from plugin/greenpng.php" >&2
    exit 1
fi

strict=0
if [[ "${1:-}" == "--strict" ]]; then
    strict=1
elif [[ -n "${1:-}" ]]; then
    echo "usage: tools/build-zip.sh [--strict]" >&2
    exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
staging="$work/greenpng"
mkdir -p "$staging"

rsync -a \
    --exclude='.git*' \
    --exclude='.DS_Store' \
    --exclude='*.zip' \
    --exclude='node_modules/' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='phpcs*.xml*' \
    --exclude='phpunit.xml*' \
    plugin/ "$staging/"

# Bundled data files (DB-IP Lite, crawler rules) and their NOTICE are
# release requirements (docs/08 §8); until the geoip and UA-engine phases
# land them, this check only warns unless --strict is passed.
missing=()
if [[ -z "$(find "$staging" -type f -iname 'NOTICE*' | head -n 1)" ]]; then
    missing+=( 'NOTICE file' )
fi
if [[ -z "$(find "$staging" -type d -name 'data' | head -n 1)" ]]; then
    missing+=( 'bundled data directory' )
fi

if (( ${#missing[@]} > 0 )); then
    if (( strict )); then
        printf 'build-zip: --strict: missing %s\n' "${missing[*]}" >&2
        exit 1
    fi
    printf 'build-zip: warning: not yet bundled: %s (enforced by --strict / T8)\n' "${missing[*]}" >&2
fi

mkdir -p dist
zip_path="dist/greenpng-${version}.zip"
rm -f "$zip_path"
( cd "$work" && zip -qr "${repo_root}/${zip_path}" greenpng )

if ! unzip -Z1 "$zip_path" | grep -q '^greenpng/greenpng\.php$'; then
    echo "build-zip: package is missing greenpng/greenpng.php" >&2
    exit 1
fi

file_count="$(unzip -Z1 "$zip_path" | grep -v '/$' | wc -l | tr -d ' ')"
echo "built ${zip_path} (${file_count} files)"
unzip -Z1 "$zip_path"
