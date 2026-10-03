#!/bin/bash
# run_tests.sh — the WordPress plugin's whole test: a real WordPress (wp_env.sh) on SQLite, the plugin as it ships, the
# stand-in for Crossref, then
#   1. tests/test_wp_plugin.php — settings, the record, the file, Crossref's schema, deposit, result, read-back;
#   2. the article page as a reader's browser gets it from WordPress (PHP's own web server): the DOI link, the citation
#      tags, Crossmark's tag, button and script;
#   3. with SHOTS=folder: the screens in Chromium (tests/browser_test.py) — the settings, the article's Crossref box, the
#      records, one record's check, Deposit, Crossref's result, the read-back, the article page — with screenshots.
#
#   bash tests/run_tests.sh                    (CR_SCHEMA_CACHE: copies of Crossref's schemas; made when not given)
set -u
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(dirname "$HERE")
W=$(mktemp -d /tmp/ccrwp-test-XXXXXX); SPID=''; WPID=''
cleanup() { [ -n "$SPID" ] && kill "$SPID" 2>/dev/null; [ -n "$WPID" ] && kill "$WPID" 2>/dev/null; rm -rf "$W"; }
trap cleanup EXIT
P=0; F=0
ck() { if [ "$2" = "$3" ]; then P=$((P+1)); echo "  ok   $1"; else F=$((F+1)); echo "  FAIL $1"; echo "       got:  $2"; echo "       want: $3"; fi; }
has() { if printf '%s' "$2" | grep -q -- "$3"; then P=$((P+1)); echo "  ok   $1"; else F=$((F+1)); echo "  FAIL $1 (no \"$3\")"; fi; }
port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'; }

SCHEMAS=${CR_SCHEMA_CACHE:-}
if [ -z "$SCHEMAS" ]; then
    SC="$ROOT/tests/schema_copies.sh"; [ -f "$SC" ] || SC="$(dirname "$ROOT")/ojs-plugin/tests/schema_copies.sh"
    SCHEMAS="$W/schemas"; bash "$SC" "$SCHEMAS" >/dev/null || { echo "the schema copies could not be made"; exit 1; }
fi
WPORT=$(port); SPORT=$(port)
bash "$HERE/wp_env.sh" "$W/site" "$WPORT" "$SPORT" "$SCHEMAS" || { echo "WordPress could not be set up"; exit 1; }
python3 -c 'import secrets; print(secrets.token_urlsafe(16))' > "$W/site/.standin-pass"
python3 "$HERE/stand_in_crossref.py" "$SPORT" 'example-user/role' "$W/site/.standin-pass" "$W/standin.log" & SPID=$!
for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$SPORT/" && break; sleep 0.1; done
echo "WordPress $(cat "$W/site/.wp-version") on SQLite, PHP $(php -r 'echo PHP_VERSION;'), the plugin as built"

echo; echo "1. inside WordPress"
php "$HERE/test_wp_plugin.php" "$W/site" "$W/standin.log" | sed 's/^/  /'; RC=${PIPESTATUS[0]}
ck 'tests/test_wp_plugin.php: every check passed' "$RC" 0
ID=$(cat "$W/site/.test-post-id" 2>/dev/null)

echo; echo "2. the article page, from WordPress's own web server"
( cd "$W/site" && PHP_CLI_SERVER_WORKERS=6 exec php -S "127.0.0.1:$WPORT" -t "$W/site" > "$W/web.log" 2>&1 ) & WPID=$!
for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$WPORT/wp-login.php" && break; sleep 0.2; done
HTML=$(curl -s -m 60 "http://journal.localhost:$WPORT/?p=$ID")
DOI="10.5555/ejtp.2026.$ID"
has 'the DOI as a link at the end of the article' "$HTML" "<p class=\"ccrwp-doi\">DOI: <a href=\"https://doi.org/$DOI\">https://doi.org/$DOI</a></p>"
has 'citation_doi for indexers' "$HTML" "<meta name=\"citation_doi\" content=\"$DOI\" />"
has 'citation_author, family name first' "$HTML" '<meta name="citation_author" content="Carberry, Josiah" />'
has 'citation_journal_title and the ISSN' "$HTML" '<meta name="citation_issn" content="1234-5679" />'
has 'the tag Crossmark reads the DOI from' "$HTML" "<meta name=\"dc.identifier\" content=\"doi:$DOI\" />"
has 'Crossref'"'"'s Crossmark button, held at 150 pixels' "$HTML" 'width:150px !important;min-width:0 !important'
has 'Crossref'"'"'s widget script' "$HTML" 'crossmark-cdn.crossref.org/widget/v2.0/widget.js'
ck 'the DOI printed once' "$(printf '%s' "$HTML" | grep -o 'class="ccrwp-doi"' | wc -l)" 1
ck 'no PHP error from the web server' "$(grep -ciE 'fatal|warning|deprecated' "$W/site/debug.log" 2>/dev/null || echo 0)" 0

if [ -n "${SHOTS:-}" ]; then
    echo; echo "3. the screens, in Chromium"
    mkdir -p "$SHOTS"
    python3 "$HERE/browser_test.py" "http://journal.localhost:$WPORT" "$W/site/.admin-pass" "$SHOTS" "$ID" | sed 's/^/  /'; RC=${PIPESTATUS[0]}
    ck 'tests/browser_test.py: every check passed' "$RC" 0
    ck 'no PHP error from the web server after the screens' "$(grep -ciE 'fatal|warning|deprecated' "$W/site/debug.log" 2>/dev/null || echo 0)" 0
fi
[ -s "$W/site/debug.log" ] && { echo "  WordPress's debug.log:"; tail -20 "$W/site/debug.log" | sed 's/^/    /'; }
echo; echo "$((P + F)) checks, $F failed$([ $F = 0 ] && echo ' — ALL OK')"
[ $F = 0 ]
