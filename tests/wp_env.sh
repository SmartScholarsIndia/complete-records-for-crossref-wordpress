#!/bin/bash
# wp_env.sh — a real WordPress site in a scratch folder for the tests: WordPress (WP_SRC, default /srv/wp-test/wordpress,
# a checkout of WordPress's own GitHub mirror at a release tag) on SQLite (WordPress's SQLite Database Integration plugin,
# SQLITE_SRC), installed by WordPress's own installer, with the plugin as it ships (build/complete-records-for-crossref)
# activated. External HTTP is blocked (WP_HTTP_BLOCK_EXTERNAL) except 127.0.0.1, where the stand-in for Crossref runs.
#
#   bash tests/wp_env.sh SITE_DIR PORT STAND_IN_PORT SCHEMA_COPIES       prints nothing on success; the site is in SITE_DIR
#
# The admin's password is made here for this scratch site only and kept in SITE_DIR/.admin-pass (never printed).
set -euo pipefail
SITE=$1; PORT=$2; SPORT=$3; SCHEMAS=$4
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(dirname "$HERE")
WP_SRC=${WP_SRC:-/srv/wp-test/wordpress}
SQLITE_SRC=${SQLITE_SRC:-/srv/wp-test/sqlite/packages/plugin-sqlite-database-integration}
[ -f "$WP_SRC/wp-includes/version.php" ] || { echo "no WordPress at $WP_SRC"; exit 1; }
[ -f "$SQLITE_SRC/db.copy" ] || { echo "no SQLite integration at $SQLITE_SRC"; exit 1; }
[ ! -e "$SITE" ] || { echo "$SITE already exists"; exit 1; }
php "$ROOT/tools/build.php" --quiet
mkdir -p "$SITE"
cp -a "$WP_SRC/." "$SITE/"
rm -rf "$SITE/.git"
mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/mu-plugins" "$SITE/wp-content/database"
cp -aL "$SQLITE_SRC" "$SITE/wp-content/plugins/sqlite-database-integration"   # -L: the plugin links its driver in from the repository's other packages
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$SQLITE_SRC/db.copy" > "$SITE/wp-content/db.php"
cp -a "$ROOT/build/complete-records-for-crossref" "$SITE/wp-content/plugins/"
salt() { python3 -c 'import secrets,string; a=string.ascii_letters+string.digits+"!@#%^&*()-_[]{}<>~+=,.;:/?|"; print("".join(secrets.choice(a) for _ in range(64)))'; }
{
  echo "<?php"
  echo "define('DB_NAME', 'wordpress'); define('DB_USER', ''); define('DB_PASSWORD', ''); define('DB_HOST', 'localhost');"
  echo "define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');"
  echo "define('DB_DIR', __DIR__ . '/wp-content/database/'); define('DB_FILE', '.ht.sqlite');"
  for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
    printf "define('%s', '%s');\n" "$k" "$(salt | sed "s/'/x/g")"
  done
  echo "\$table_prefix = 'wp_';"
  # a name, not an address: Crossmark names the journal's host as its domain (an IP is not one). *.localhost is the
  # loopback for Chromium and curl without any change to the machine
  echo "define('WP_HOME', 'http://journal.localhost:$PORT'); define('WP_SITEURL', 'http://journal.localhost:$PORT');"
  echo "define('WP_DEBUG', true); define('WP_DEBUG_LOG', __DIR__ . '/debug.log'); define('WP_DEBUG_DISPLAY', false);"
  echo "define('WP_HTTP_BLOCK_EXTERNAL', true); define('WP_ACCESSIBLE_HOSTS', '127.0.0.1');"
  echo "define('AUTOMATIC_UPDATER_DISABLED', true); define('WP_AUTO_UPDATE_CORE', false); define('DISABLE_WP_CRON', true);"
  echo "define('CCRWP_TEST_STAND_IN', 'http://127.0.0.1:$SPORT'); define('CCRWP_TEST_SCHEMAS', '$SCHEMAS');"
  echo "if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');"
  echo "require_once ABSPATH . 'wp-settings.php';"
} > "$SITE/wp-config.php"
# the test's own wiring: Crossref's addresses → the stand-in, the schema files → the copies (nothing fetched)
cat > "$SITE/wp-content/mu-plugins/ccrwp-test-stand-in.php" <<'PHP'
<?php
// Tests only: Crossref's deposit service and REST API are the stand-in on 127.0.0.1; Crossref's schema files are read
// from copies of them, never fetched.
add_filter('ccrwp_crossref_base', function ($base, $server) { return CCRWP_TEST_STAND_IN . '/' . $server; }, 10, 2);
add_filter('ccrwp_crossref_api', function () { return CCRWP_TEST_STAND_IN . '/api'; });
add_filter('ccrwp_schema_dir', function () { return CCRWP_TEST_SCHEMAS; });
add_filter('ccrwp_schema_fetch', function () { return null; });
// the scratch site cannot reach WordPress.org: its update checks are answered "nothing new" instead of failing
$ccrwpNoUpdates = function () { return (object) ['last_checked' => time(), 'version_checked' => get_bloginfo('version'), 'updates' => [], 'response' => [], 'translations' => [], 'checked' => []]; };
foreach (['update_core', 'update_plugins', 'update_themes'] as $t) add_filter('pre_site_transient_' . $t, $ccrwpNoUpdates);
PHP
python3 -c 'import secrets; print(secrets.token_urlsafe(18))' > "$SITE/.admin-pass"; chmod 600 "$SITE/.admin-pass"
cat > "$SITE/.install.php" <<'PHP'
<?php
define('WP_INSTALLING', true);
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', '__return_false');   // the scratch site sends no "new site" mail
$r = wp_install('Example Journal of Testing', 'admin', 'admin@example.org', false, '', trim(file_get_contents(__DIR__ . '/.admin-pass')));
if (empty($r['user_id'])) { fwrite(STDERR, "install failed\n"); exit(1); }
PHP
php "$SITE/.install.php" >/dev/null
cat > "$SITE/.activate.php" <<'PHP'
<?php
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$e = activate_plugin('complete-records-for-crossref/complete-records-for-crossref.php');
if (is_wp_error($e)) { fwrite(STDERR, 'activation failed: ' . $e->get_error_message() . "\n"); exit(1); }
echo get_bloginfo('version');
PHP
php "$SITE/.activate.php" > "$SITE/.wp-version"
rm -f "$SITE/.install.php" "$SITE/.activate.php"
# WordPress's own fatal-error handler can end a failed run with exit 0: the result is checked, not the exit code
[ -s "$SITE/wp-content/database/.ht.sqlite" ] && grep -Eq '^[0-9]+\.[0-9]' "$SITE/.wp-version" \
    || { echo "WordPress was not installed in $SITE:"; tail -5 "$SITE/debug.log" 2>/dev/null; exit 1; }
