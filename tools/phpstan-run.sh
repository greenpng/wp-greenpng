#!/bin/sh
# PHPStan runner for this repository's host profile.
#
# The local php is a FrankenPHP build whose argv handling breaks every
# PHPStan child-process spawn path: the TurboProcessRestarter loops
# forever on -d flags (treated as filenames), and parallel workers
# re-exec with the PHPRC ini path as the console command. Neutralizing
# the restart via phpstan.restarted=1 in the PHPRC ini and running
# single-process with --debug is the only stable combination.
#
# --debug prints the analyzed file list to stdout, so errors are
# parsed from raw format (path:line:message) with the file list
# filtered out.
#
# Usage:
#   tools/phpstan-run.sh          analyse; exit 0 clean, 1 on findings
#   tools/phpstan-run.sh --baseline   regenerate phpstan-baseline.neon

set -e

INI=/tmp/gr-php.ini
PHAR="vendor/phpstan/phpstan/phpstan.phar"

if ! grep -q '^phpstan.restarted=1' "$INI" 2>/dev/null; then
    printf 'phpstan.restarted=1\n' >> "$INI"
fi

OUT="$(mktemp)"
trap 'rm -f "$OUT"' EXIT

if [ "$1" = "--baseline" ]; then
    PHPRC="$INI" php "$PHAR" analyse --no-progress --debug \
        --generate-baseline > "$OUT" 2>&1 || true
    grep -E ':[0-9]+:|\[OK\]|Baseline generated' "$OUT" || cat "$OUT"
    exit 0
fi

PHPRC="$INI" php "$PHAR" analyse --no-progress --debug \
    --error-format=raw > "$OUT" 2>&1 || true

ERRORS="$(grep -cE ':[0-9]+:' "$OUT" || true)"
grep -E ':[0-9]+:' "$OUT" || true
grep -E '\[OK\]|No errors' "$OUT" || true

if [ "$ERRORS" -gt 0 ]; then
    echo "phpstan: $ERRORS finding(s)"
    exit 1
fi

echo 'phpstan: clean'
