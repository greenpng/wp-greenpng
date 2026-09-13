#!/usr/bin/env bash
# Integration arms for the wp-env CI matrix (ADR-0008). Runs against a
# live wp-env site whose plugin directory is a bind-mounted copy of
# plugin/ — which is exactly what makes the uninstall arms safe to run
# for real (the dev-station symlink made that impossible; T2/T6 noted
# CI as the closer, this is it).
#
# Arms: activation + schema, dbDelta idempotency (forced reinstall),
# collect REST contract (401/400/413/200/no-cache), per-IP rate limit,
# deactivate/reactivate lifecycle, uninstall in both data modes.
set -uo pipefail

SITE="${SITE_URL:-http://localhost:8888}"
# The collect endpoint is read from the page-embedded probe data
# (rest_url), because wp-env sites ship with plain permalinks where
# the /wp-json/ pretty path does not resolve.
REST=""
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# The slug-staged copy wp-env mounts (workflows create it). Deleting
# the plugin through WordPress removes the staged copy, never the
# repository source, which restores it between uninstall arms.
STAGED="$REPO_ROOT/.wp-env-plugins/greenpng"

cd "$REPO_ROOT"

PASS=0
FAIL=0
say() { printf '\n== %s\n' "$*"; }
pass() { PASS=$(( PASS + 1 )); printf '   PASS  %s\n' "$*"; }
fail() { FAIL=$(( FAIL + 1 )); printf '   FAIL  %s\n' "$*"; }
check() {
    # check <label> <expected> <actual>
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected [$2], got [$3])"; fi
}
check_ge() {
    if [ "$3" -ge "$2" ] 2>/dev/null; then pass "$1"; else fail "$1 (expected >= $2, got [$3])"; fi
}

cli() {
    # CLI_PREFIX lets non-wp-env environments (the docker-compose MySQL
    # 5.7 floor cell) reuse these arms unchanged.
    if [ -n "${CLI_PREFIX:-}" ]; then
        ${CLI_PREFIX} "$@" 2>/dev/null
    else
        npx wp-env run cli "$@" 2>/dev/null
    fi
}
# Unsilenced variant for the steps whose own diagnostics are the
# evidence (the uninstall arms); failures there must explain themselves.
cli_raw() {
    if [ -n "${CLI_PREFIX:-}" ]; then
        ${CLI_PREFIX} "$@"
    else
        npx wp-env run cli "$@"
    fi
}
q() { cli wp db query "$1" 2>/dev/null; }
first_int() { grep -oE '[0-9]+' | head -1; }

# The 15 plugin tables (Gr_Schema, fixed list).
TABLES="access_rules audit_logs cart_abandonments contact_tags contacts conversions daily_stats dynamic_events events funnel_sessions funnels security_logs sessions tags touchpoints"

table_count() {
    q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%gr_%'" | first_int
}
gr_options() {
    cli wp option list --search='gr_*' --format=count | first_int
}
cron_gr() {
    cli wp cron event list --fields=hook --format=csv | grep -cE '^(gr_|greenpng)'
}
# Forensic variant: on mismatch, print the surviving hooks so a CI log
# carries the evidence instead of a bare count.
cron_dump() {
    cli wp cron event list --fields=hook --format=csv | grep -E '^(gr_|greenpng)' | sed 's/^/     remaining: /' || true
}
check_cron() {
    ACTUAL="$(cron_gr)"
    if [ "$2" = "$ACTUAL" ]; then
        pass "$1"
    else
        fail "$1 (expected [$2], got [$ACTUAL])"
        cron_dump
    fi
}
schema_digest() {
    # SHOW CREATE TABLE text with the volatile AUTO_INCREMENT counter
    # stripped (it moves with traffic; the .frm forensics lesson).
    for t in $TABLES; do
        q "SHOW CREATE TABLE wp_gr_${t}" | sed -E 's/AUTO_INCREMENT=[0-9]+//'
    done | md5sum | cut -d' ' -f1
}
restore_plugin() {
    # The staged directory is the bind mount; replacing its contents
    # host-side is visible in the container immediately (the mount
    # inode stays alive), so no container restart is needed. The chmod
    # repeats because cp -a restores the repository's own permission
    # bits, and the compose CLI container (www-data) must be able to
    # delete files through the mount.
    mkdir -p "$STAGED"
    find "$STAGED" -mindepth 1 -delete
    cp -a "$REPO_ROOT/plugin/." "$STAGED/"
    chmod -R a+rwX "$STAGED"
}

delete_plugin() {
    # Deleting a bind-mounted plugin cannot remove the mount-point
    # directory itself (rmdir on a live bind returns EBUSY), so
    # success is judged by the plugin vanishing from the list, not by
    # wp-cli's exit status. On failure the log gets the full picture:
    # the delete's own output, who the CLI runs as, what survives on
    # disk through the mount, what WordPress still believes, and a
    # direct rm probe — if rm succeeds where delete_plugins() did not,
    # the fault is in WordPress, not the filesystem.
    DELETE_OUT="$(cli_raw wp plugin delete greenpng 2>&1)"; DELETE_CODE=$?
    ACTUAL="$(cli wp plugin list --name=greenpng --field=status)"
    if [ -z "$ACTUAL" ]; then
        pass "plugin gone from the list"
        return 0
    fi
    fail "plugin gone from the list (expected [], got [$ACTUAL])"
    printf '     delete exit=%d output=%s\n' "$DELETE_CODE" "${DELETE_OUT:-<none>}"
    cli_raw sh -c 'id; echo --plugins-dir--; ls -la /var/www/html/wp-content/plugins/; echo --plugin-dir--; ls -la /var/www/html/wp-content/plugins/greenpng/ | head -20' 2>&1 | sed 's/^/     /'
    cli_raw wp plugin list 2>&1 | sed 's/^/     /'
    cli_raw wp option get active_plugins --format=json 2>&1 | sed 's/^/     /'
    cli_raw sh -c 'rm -v /var/www/html/wp-content/plugins/greenpng/greenpng.php; echo rm-exit=$?' 2>&1 | sed 's/^/     /'
    return 1
}

say "ARM 1: activation and schema"
cli wp plugin activate greenpng >/dev/null
check "plugin active after activation" "active" "$(cli wp plugin list --name=greenpng --field=status)"
check_ge "15 greenpng tables exist" 15 "$(table_count)"
check_ge "schema version option written" 1 "$(cli wp option get gr_db_version | first_int)"

say "ARM 2: dbDelta idempotency (forced reinstall)"
BEFORE="$(schema_digest)"
cli wp option delete gr_db_version >/dev/null
cli wp plugin deactivate greenpng >/dev/null
cli wp plugin activate greenpng >/dev/null
check "schema byte-identical after forced reinstall" "$BEFORE" "$(schema_digest)"
check_ge "still 15 tables after reinstall" 15 "$(table_count)"

say "ARM 3: collect REST contract"
PROBE_JSON="$(curl -s "$SITE/" | grep -oE 'window\.GreenPNGProbe=\{[^}]*\}' | head -1)"
REST="$(printf '%s' "$PROBE_JSON" | grep -oE '"url":"[^"]*"' | cut -d'"' -f4 | sed 's|\\\/|/|g')"
TOKEN="$(printf '%s' "$PROBE_JSON" | grep -oE '"token":"[a-f0-9]{32,}"' | cut -d'"' -f4)"
if [ -n "$TOKEN" ]; then pass "daily token embedded on the front page"; else fail "daily token embedded on the front page"; fi
if [ -n "$REST" ]; then pass "collect endpoint read from the probe data"; else fail "collect endpoint read from the probe data"; fi
BODY="$(mktemp)"
HDRS="$(mktemp)"
code() { curl -s -o "$BODY" -w '%{http_code}' -X POST "$REST" -H 'Content-Type: application/json' --data "$1"; }

check "401 when the token is missing" 401 "$(code '{"name":"pageview"}')"
LAST="${TOKEN: -1}"
if [ "$LAST" = "a" ]; then FLIP="${TOKEN%?}b"; else FLIP="${TOKEN%?}a"; fi
check "401 with a forged token" 401 "$(code "{\"token\":\"$FLIP\",\"name\":\"pageview\"}")"
check "400 unknown event name" 400 "$(code "{\"token\":\"$TOKEN\",\"name\":\"gr_bogus\"}")"
check "400 unknown field" 400 "$(code "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"evil\":\"x\"}")"
check "400 bot_score out of range" 400 "$(code "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"bot_score\":999}")"
check "200 happy pageview" 200 "$(code "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"path\":\"/\",\"event_id\":\"ci-1\"}")"
if grep -q '"stored"' "$BODY"; then pass "response carries the stored flag"; else fail "response carries the stored flag"; fi
check "413 oversized body (gate precedes schema)" 413 "$(code "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"evil\":\"$(printf 'x%.0s' $(seq 1 9000))\"}")"
HTTP="$(curl -s -o /dev/null -D "$HDRS" -w '%{http_code}' -X POST "$REST" -H 'Content-Type: application/json' --data "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"path\":\"/\",\"event_id\":\"ci-h\"}")"
check "200 header-sample request" 200 "$HTTP"
if grep -qi '^cache-control:.*no-cache' "$HDRS"; then pass "no-cache header on collect"; else fail "no-cache header on collect"; fi

say "ARM 4: per-IP rate limit"
SAW429=0
for i in $(seq 1 70); do
    C="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$REST" -H 'Content-Type: application/json' --data "{\"token\":\"$TOKEN\",\"name\":\"pageview\",\"path\":\"/\",\"event_id\":\"ci-r${i}\"}")"
    if [ "$C" = "429" ]; then SAW429=1; break; fi
done
check "429 after a burst past the 60/min allowance" 1 "$SAW429"

say "ARM 5: deactivate/reactivate lifecycle"
SETTINGS_BEFORE="$(cli wp option get gr_settings --format=json | md5sum | cut -d' ' -f1)"
check_ge "plugin work scheduled while active" 1 "$(cron_gr)"
cli wp plugin deactivate greenpng >/dev/null
check "plugin inactive" "inactive" "$(cli wp plugin list --name=greenpng --field=status)"
check_ge "tables survive deactivation" 15 "$(table_count)"
check_cron "scheduled work cleared on deactivation" 0
check "settings byte-identical across deactivation" "$SETTINGS_BEFORE" "$(cli wp option get gr_settings --format=json | md5sum | cut -d' ' -f1)"
cli wp plugin activate greenpng >/dev/null
check_ge "schedule restored on reactivation" 1 "$(cron_gr)"
check_ge "still 15 tables after reactivation" 15 "$(table_count)"

say "ARM 6: uninstall, keep-data mode (T2/T6 deferred arm)"
cli wp plugin deactivate greenpng >/dev/null
# wp-cli's plugin delete removes files but never runs the core
# uninstall machinery; the real path is uninstall_plugin(), which is
# what delete_plugins() uses to load and run uninstall.php.
cli wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; uninstall_plugin( "greenpng/greenpng.php" );' >/dev/null
check_ge "tables survive keep-mode uninstall" 15 "$(table_count)"
check_ge "options survive keep-mode uninstall" 2 "$(gr_options)"
delete_plugin

say "ARM 7: uninstall, delete-data mode (T2/T6 deferred arm)"
restore_plugin
cli wp plugin activate greenpng >/dev/null
cli wp option update gr_delete_data_on_uninstall '1' >/dev/null
cli wp plugin deactivate greenpng >/dev/null
cli wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; uninstall_plugin( "greenpng/greenpng.php" );' >/dev/null
check "all tables dropped by delete-mode uninstall" 0 "$(table_count)"
check "all gr_ options removed" 0 "$(gr_options)"
check_cron "no scheduled work lingers" 0
delete_plugin
restore_plugin

say "SUMMARY"
printf '   %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
