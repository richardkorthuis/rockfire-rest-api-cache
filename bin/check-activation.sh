#!/usr/bin/env bash
#
# Release gate: install the built zip into a fresh WordPress site with WP_DEBUG on, activate it,
# serve a REST request three times, then deactivate and uninstall it.
#
# Activation compiles the configuration without a REST server (no routes, pending discovery), so the first
# request's lookup cannot match its route and records no flush epoch; the routes are only compiled during that
# request, and Capture stores nothing for a request whose lookup recorded no epoch (no_lookup). The
# request that rebuilt the snapshot it looked up is refused without recording a failure.
#
# It fails unless:
#   - activation installs the must-use file;
#   - the first request is a BYPASS with reason no_lookup on which the early server (must-use phase) booted and
#     ran its lookup; the refusal records no failure (read right after that request, because the second request's
#     store clears a `capture` failure of the same route);
#   - the second request is a MISS on which the early server booted and ran its lookup, and the third is a HIT
#     served by the early server with the second's body, counted as one hit;
#   - no failure is still recorded once the three requests are served;
#   - uninstall removes the must-use file, the tables, every rfrac_ option and every rfrac_ cron event;
#   - no PHP notice, warning, deprecation or error appears in the WP-CLI output, the HTTP responses,
#     the PHP server log or debug.log, and debug.log is empty (WP_DEBUG_LOG writes every recorded failure to it).
#
# A probe must-use file (zz-rfrac-probe.php, loaded after rfrac-early-cache.php) adds an
# `X-Rfrac-Probe` header: `booted` when the early server booted and ran its lookup, `not-booted`
# otherwise. A hit served by the early server ends the request before the probe runs, so the HIT
# carries no probe header; a hit served by the fallback server would carry `not-booted`.
#
# Usage: bin/check-activation.sh <zip> <work-dir>
#
# Environment:
#   WP_VERSION  WordPress version to install (default 6.6).
#   PHP_BIN     PHP binary (default php).
#   WP_CLI      Path of wp-cli.phar (default <work-dir>/wp-cli.phar; WP-CLI 2.12.0 is downloaded when missing).
#   DB_NAME     Throwaway database, dropped and recreated (default rfrac_release_check; must contain "release_check").
#   DB_USER, DB_PASS, DB_HOST (default root, root, 127.0.0.1).
#   PORT        Port of the PHP built-in server (default 8899).
#   SELF_TEST   `break-mu` corrupts the installed must-use file after activation, to prove the gate
#               fails when the early phase does not serve (the run must then fail).
#
# @package Rockfire_Rest_Api_Cache

set -euo pipefail

ZIP="${1:?Usage: bin/check-activation.sh <zip> <work-dir>}"
WORK="${2:?Usage: bin/check-activation.sh <zip> <work-dir>}"
WP_VERSION="${WP_VERSION:-6.6}"
PHP_BIN="${PHP_BIN:-php}"
DB_NAME="${DB_NAME:-rfrac_release_check}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_HOST="${DB_HOST:-127.0.0.1}"
PORT="${PORT:-8899}"
SELF_TEST="${SELF_TEST:-}"
SLUG=rockfire-rest-api-cache
WP_CLI_VERSION=2.12.0
WP_CLI_SHA512=be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832

case "$DB_NAME" in
	*release_check*) ;;
	*) echo "Refusing to drop database '$DB_NAME': its name must contain 'release_check'." >&2; exit 1 ;;
esac

mkdir -p "$WORK"
WORK="$(cd "$WORK" && (pwd -W 2>/dev/null || pwd))"
SITE="$WORK/site"
LOG="$WORK/output.log"
WP_CLI="${WP_CLI:-$WORK/wp-cli.phar}"
MU_DIR="$SITE/wp-content/mu-plugins"
: > "$LOG"

fail() {
	echo "FAIL: $*" | tee -a "$LOG" >&2
	exit 1
}

if [ ! -f "$WP_CLI" ]; then
	# GitHub release downloads sometimes answer 504 for a while: retry before failing the job.
	curl -sSfL --retry 5 --retry-delay 10 --retry-all-errors -o "$WP_CLI" "https://github.com/wp-cli/wp-cli/releases/download/v$WP_CLI_VERSION/wp-cli-$WP_CLI_VERSION.phar"
fi
"$PHP_BIN" -r 'exit( hash_file( "sha512", $argv[1] ) === $argv[2] ? 0 : 1 );' "$WP_CLI" "$WP_CLI_SHA512" \
	|| fail "$WP_CLI is not WP-CLI $WP_CLI_VERSION (checksum mismatch)."

wp() {
	"$PHP_BIN" "$WP_CLI" --path="$SITE" "$@" 2>&1 | tee -a "$LOG"
	return "${PIPESTATUS[0]}"
}

# The failure Failure_Recorder::last() reports: `none`, or `recorded` followed by the failure as JSON.
last_failure() {
	wp eval 'echo null === ( $rfrac_last = \Rockfire\Rest_Api_Cache\Failure_Recorder::last() ) ? "none" : "recorded " . wp_json_encode( $rfrac_last );' | tail -n 1
}

# Fresh database (throwaway only, see the guard above).
DB_PORT=3306
DB_HOSTNAME="$DB_HOST"
if [[ "$DB_HOST" == *:* ]]; then
	DB_HOSTNAME="${DB_HOST%%:*}"
	DB_PORT="${DB_HOST##*:}"
fi
"$PHP_BIN" -r '
	$db = new mysqli( $argv[1], $argv[2], $argv[3], "", (int) $argv[4] );
	$db->query( "DROP DATABASE IF EXISTS `" . $argv[5] . "`" );
	$db->query( "CREATE DATABASE `" . $argv[5] . "`" ) || exit( 1 );
' "$DB_HOSTNAME" "$DB_USER" "$DB_PASS" "$DB_PORT" "$DB_NAME"

rm -rf "$SITE"
wp core download --version="$WP_VERSION" --force
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --force \
	--extra-php <<PHP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$WORK/debug.log' );
define( 'WP_DEBUG_DISPLAY', true );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );
define( 'RFRAC_HIT_SAMPLING', 1 );
PHP
rm -f "$WORK/debug.log"
wp core install --url="http://127.0.0.1:$PORT" --title="Release check" --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.com --skip-email

rm -rf "$SITE/wp-content/plugins/$SLUG"
"$PHP_BIN" -r '
	$zip = new ZipArchive();
	( true === $zip->open( $argv[1] ) && $zip->extractTo( $argv[2] ) ) || exit( 1 );
' "$ZIP" "$SITE/wp-content/plugins"

wp plugin activate "$SLUG"
wp rfrac status
[ -f "$MU_DIR/rfrac-early-cache.php" ] || fail "The must-use file was not installed on activation."

cat > "$MU_DIR/zz-rfrac-probe.php" <<'PHP'
<?php
// Release gate probe: did the early server of rfrac-early-cache.php boot and run its lookup?
$rfrac_probe_state = '\Rockfire\Rest_Api_Cache\Serving\Request_State';
$rfrac_probe       = class_exists( $rfrac_probe_state, false ) && $rfrac_probe_state::early_booted() && $rfrac_probe_state::lookup_done();
header( 'X-Rfrac-Probe: ' . ( $rfrac_probe ? 'booted' : 'not-booted' ) );
PHP

if [ "break-mu" = "$SELF_TEST" ]; then
	"$PHP_BIN" -r '
		$f = $argv[1];
		file_put_contents( $f, str_replace( "rockfire-rest-api-cache/rockfire-rest-api-cache.php", "rockfire-rest-api-cache/broken.php", file_get_contents( $f ) ) );
	' "$MU_DIR/rfrac-early-cache.php"
	echo "SELF_TEST=break-mu: corrupted the plugin basename in the must-use file." | tee -a "$LOG"
fi

# Serve a REST request three times: the first is refused (no_lookup, see the header) without recording a failure, the second is stored, the third is a cache hit.
"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$SITE" > "$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do
	curl -s -o /dev/null "http://127.0.0.1:$PORT/wp-includes/images/blank.gif" && break
	sleep 0.2
done
URL="http://127.0.0.1:$PORT/?rest_route=/wp/v2/posts"
curl -sS -D "$WORK/first.headers" -o "$WORK/first.body" "$URL"
# Read the recorded failure before the second request: its store clears a `capture` failure of the same route, so a failure the first request recorded would be gone by the end.
FIRST_FAILURE="$(last_failure)"
curl -sS -D "$WORK/second.headers" -o "$WORK/second.body" "$URL"
curl -sS -D "$WORK/third.headers" -o "$WORK/third.body" "$URL"
kill "$SERVER" 2>/dev/null || true
cat "$WORK/first.headers" "$WORK/second.headers" "$WORK/third.headers" "$WORK/first.body" "$WORK/second.body" "$WORK/third.body" >> "$LOG"
grep -qi '^X-Rfrac-Cache: BYPASS' "$WORK/first.headers" && grep -qi '^X-Rfrac-Cache-Reason: no_lookup' "$WORK/first.headers" \
	|| fail "First request was not a BYPASS with reason no_lookup."
grep -qi '^X-Rfrac-Probe: booted' "$WORK/first.headers" || fail "The early server did not boot and run its lookup on the first request."
[ "$FIRST_FAILURE" = none ] || fail "The first request, refused no_lookup on the snapshot it rebuilt, recorded a failure: ${FIRST_FAILURE#recorded }"
grep -qi '^X-Rfrac-Cache: MISS' "$WORK/second.headers" || fail "Second request was not a MISS."
grep -qi '^X-Rfrac-Probe: booted' "$WORK/second.headers" || fail "The early server did not boot and run its lookup on the second request."
grep -qi '^X-Rfrac-Cache: HIT' "$WORK/third.headers" || fail "Third request was not a HIT."
if grep -qi '^X-Rfrac-Probe:' "$WORK/third.headers"; then
	fail "The HIT was not served by the early server (the request reached the probe)."
fi
cmp -s "$WORK/second.body" "$WORK/third.body" || fail "The cached body differs from the generated one."
HITS="$(wp rfrac list --format=json --fields=hits | tail -n 1)"
[ "$HITS" = '[{"hits":"1"}]' ] || [ "$HITS" = '[{"hits":1}]' ] || fail "Expected one recorded hit, got: $HITS"
LAST_FAILURE="$(last_failure)"
[ "$LAST_FAILURE" = none ] || fail "A failure is still recorded after the three requests: ${LAST_FAILURE#recorded }"

rm -f "$MU_DIR/zz-rfrac-probe.php"
wp plugin deactivate "$SLUG"
wp plugin uninstall "$SLUG"
[ ! -f "$MU_DIR/rfrac-early-cache.php" ] || fail "The must-use file is still there after uninstall."
LEFT="$(wp eval '
	global $wpdb;
	$left = array_merge(
		$wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "rfrac_" ) . "%" ) ),
		$wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( "rfrac_" ) . "%", "%" . $wpdb->esc_like( "_rfrac_" ) . "%" ) )
	);
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( str_starts_with( (string) $hook, "rfrac_" ) ) {
				$left[] = "cron:" . $hook;
			}
		}
	}
	echo implode( ",", $left );
' | tail -n 1)"
[ -z "$LEFT" ] || fail "Uninstall left data behind: $LEFT"

STATUS=0
PATTERN='(PHP )?(Notice|Warning|Deprecated|Fatal error|Parse error)(</b>)?( |:)'
if grep -nE "$PATTERN" "$LOG"; then
	echo "PHP notices or errors in the output." >&2
	STATUS=1
fi
if grep -vE '(Development Server .* started|Accepted$|Closing$|\[[0-9]{3}\]: [A-Z]+ )' "$WORK/server.log" | grep -nE "$PATTERN|PHP "; then
	echo "PHP notices or errors in the server log." >&2
	STATUS=1
fi
# WP_DEBUG_LOG also writes every recorded failure to debug.log, so a debug.log with anything in it fails the gate.
touch "$WORK/debug.log"
if [ -s "$WORK/debug.log" ]; then
	echo "debug.log is not empty:" >&2
	cat "$WORK/debug.log" >&2
	STATUS=1
fi
[ "$STATUS" -eq 0 ] && echo "Activation check passed: activate, early no_lookup BYPASS (nothing recorded), early MISS, early HIT (1 hit recorded), deactivate and uninstall (no data left) without notices, empty debug.log."
exit "$STATUS"
