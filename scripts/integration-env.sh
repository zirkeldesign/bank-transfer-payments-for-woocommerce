#!/usr/bin/env bash
#
# Provisions the WordPress install the Integration suite runs against.
#
# SQLite-backed, so it needs no MySQL — only wp-cli, PHP with pdo_sqlite and
# network access for the WordPress/WooCommerce downloads. The install is cached
# in .wp-integration so repeat runs are instant; pass --fresh to rebuild it.
#
# The live Stripe suite additionally needs a Stripe TEST secret key, supplied
# out of band so it never reaches the repository:
#
#   export BTPW_STRIPE_TEST_KEY=sk_test_...      # or write .stripe-test-key
#
# Without it the environment still builds and the Stripe tests skip.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CORE_DIR="${BTPW_CORE_SOURCE:-$(cd "$PLUGIN_DIR/../bank-transfer-payments-for-woocommerce" 2>/dev/null && pwd || true)}"
WP_DIR="${BTPW_WP_ROOT:-$PLUGIN_DIR/.wp-integration}"
WP_VERSION="${BTPW_WP_VERSION:-latest}"
PLUGINS_DIR="$WP_DIR/wp-content/plugins"

# wp-cli's phar can exhaust the default memory limit unpacking core. Resolve the
# binary *before* defining the wrapper — inside it, `command -v wp` would find
# the function itself and recurse.
WP_BIN="$(command -v wp || true)"

if [[ -z "$WP_BIN" ]]; then
    echo "✖ wp-cli not found on PATH." >&2
    exit 1
fi

wp() { php -d memory_limit=1024M "$WP_BIN" "$@"; }

if [[ "${1:-}" == "--fresh" ]]; then
    echo "› Removing $WP_DIR"
    rm -rf "$WP_DIR"
fi

if [[ -f "$WP_DIR/wp-load.php" ]]; then
    echo "✅ WordPress already provisioned at $WP_DIR (use --fresh to rebuild)"
else
    echo "› Downloading WordPress $WP_VERSION"
    wp core download --path="$WP_DIR" --version="$WP_VERSION" --force --quiet

    echo "› Installing the SQLite drop-in"
    curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$WP_DIR/sqlite.zip"
    unzip -q -o "$WP_DIR/sqlite.zip" -d "$PLUGINS_DIR/"
    rm "$WP_DIR/sqlite.zip"
    cp "$PLUGINS_DIR/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"
    php -r '
        [$f, $d] = [$argv[1], $argv[2]];
        $c = file_get_contents($f);
        $c = str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", $d."/wp-content/plugins/sqlite-database-integration", $c);
        $c = str_replace("{SQLITE_PLUGIN}", "sqlite-database-integration/load.php", $c);
        file_put_contents($f, $c);
    ' "$WP_DIR/wp-content/db.php" "$WP_DIR"

    wp config create --path="$WP_DIR" --dbname=wp --dbuser=root --dbpass='' --skip-check --force --quiet
    wp core install --path="$WP_DIR" --url=http://localhost --title="BTPW Core Integration" \
        --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email --quiet
fi

echo "› Installing WooCommerce"
wp plugin install woocommerce --path="$WP_DIR" --activate --force --quiet

# --- the plugin under test --------------------------------------------------
echo "› Linking the plugin under test"
rm -rf "$PLUGINS_DIR/bank-transfer-payments-for-woocommerce"
ln -s "$PLUGIN_DIR" "$PLUGINS_DIR/bank-transfer-payments-for-woocommerce"
wp plugin activate bank-transfer-payments-for-woocommerce --path="$WP_DIR" --quiet || true

echo
echo "✅ Environment ready at $WP_DIR"
wp plugin list --path="$WP_DIR" --fields=name,status --format=table
