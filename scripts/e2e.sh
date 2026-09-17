#!/usr/bin/env bash
#
# Runs the browser suite against a real, served WooCommerce store.
#
# The PHP integration suite boots WordPress in process and never speaks HTTP.
# The browser suite does, so this script provisions the store (if needed),
# serves it on a free port, runs the tests and shuts the server down again.
#
# Requires: wp-cli, PHP with pdo_sqlite, and Playwright's browser installed
# once via `npx playwright install chromium`.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR"

WP_DIR="${BTPW_WP_ROOT:-$PLUGIN_DIR/.wp-integration}"
WP_BIN="$(command -v wp || true)"
[[ -n "$WP_BIN" ]] || { echo "✖ wp-cli not found on PATH." >&2; exit 1; }
wp() { php -d memory_limit=1024M "$WP_BIN" "$@" --path="$WP_DIR"; }

if [[ ! -d "$WP_DIR" ]]; then
    echo "› No environment yet, provisioning it first"
    ./scripts/integration-env.sh
fi

# A fixed port collides with whatever else the machine happens to be serving,
# and the failure looks like a broken test rather than a busy port.
PORT="${BTPW_E2E_PORT:-}"
if [[ -z "$PORT" ]]; then
    for candidate in $(seq 8901 8950); do
        if ! lsof -nP -iTCP:"$candidate" -sTCP:LISTEN >/dev/null 2>&1; then
            PORT="$candidate"
            break
        fi
    done
fi
[[ -n "$PORT" ]] || { echo "✖ No free port in 8901-8950." >&2; exit 1; }

BASE="http://127.0.0.1:$PORT"
echo "› Serving the store on $BASE"
wp option update siteurl "$BASE" --quiet
wp option update home "$BASE" --quiet

php -d memory_limit=1024M "$WP_BIN" server --host=127.0.0.1 --port="$PORT" --path="$WP_DIR" \
    >/tmp/btpw-e2e-server.log 2>&1 &
SERVER_PID=$!
cleanup() { kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

for _ in $(seq 1 20); do
    curl -sf -o /dev/null "$BASE/" && break
    sleep 1
done
curl -sf -o /dev/null "$BASE/" || { echo "✖ Server did not come up:"; cat /tmp/btpw-e2e-server.log; exit 1; }

PRODUCT_ID="$(wp post list --post_type=product --format=ids --posts_per_page=1 2>/dev/null | tr -d '[:space:]')"
GATEWAY_TITLE="${BTPW_E2E_GATEWAY_TITLE:-$(wp eval 'echo get_option("woocommerce_stripe_bank_transfer_settings")["title"] ?? "Bank Transfer";' 2>/dev/null | tail -1)}"

echo "› Running the browser suite (product $PRODUCT_ID, gateway \"$GATEWAY_TITLE\")"
BTPW_E2E_URL="$BASE" \
BTPW_E2E_PRODUCT_ID="$PRODUCT_ID" \
BTPW_E2E_GATEWAY_TITLE="$GATEWAY_TITLE" \
    ./vendor/bin/pest tests/Browser "$@"
