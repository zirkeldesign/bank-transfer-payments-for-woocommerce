#!/usr/bin/env bash
#
# Replaces the Stripe TEST key everywhere it is stored locally.
#
# The key is read without echo and never passed as an argument, so it stays out
# of the terminal, the shell history and `ps`. Nothing here prints it back; the
# verification below reports length and prefix only.
#
# Create the new key first at
#   https://dashboard.stripe.com/test/apikeys
# and revoke the old one there afterwards - this script cannot do that for you.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KEY_FILE="$ROOT/.stripe-test-key"
WP_BIN="$(command -v wp || true)"
[[ -n "$WP_BIN" ]] || { echo "✖ wp-cli not found on PATH." >&2; exit 1; }

read -rsp "New Stripe TEST secret key (input hidden): " NEW_KEY
echo

[[ -n "$NEW_KEY" ]] || { echo "✖ Nothing entered." >&2; exit 1; }

# Refuse anything that is not a test key. A live key here would reach a test
# shop that places real orders against it.
[[ "$NEW_KEY" == sk_test_* ]] || { echo "✖ That is not an sk_test_ key. Refusing." >&2; exit 1; }

umask 077
printf '%s' "$NEW_KEY" > "$KEY_FILE"
echo "✓ $(basename "$KEY_FILE") updated ($(wc -c < "$KEY_FILE" | tr -d ' ') bytes, mode $(stat -f '%Lp' "$KEY_FILE"))"

# The integration environments keep the key in wp_options in plain text, which
# is where it leaks from most easily - a stray `wp option get` prints it.
for env in \
    "$ROOT/.wp-integration" \
    "$ROOT/../zirkel-iban-for-woocommerce-pro/.wp-integration"
do
    [[ -d "$env" ]] || continue
    BTPW_NEW_KEY="$NEW_KEY" php -d memory_limit=1024M "$WP_BIN" eval '
        $s = (array) get_option("woocommerce_stripe_bank_transfer_settings", []);
        $s["test_secret_key"] = (string) getenv("BTPW_NEW_KEY");
        update_option("woocommerce_stripe_bank_transfer_settings", $s);
    ' --path="$env" --quiet 2>/dev/null
    echo "✓ $(basename "$(dirname "$env")")/.wp-integration updated"
done

unset NEW_KEY BTPW_NEW_KEY

echo
echo "Now revoke the old key at https://dashboard.stripe.com/test/apikeys"
echo "Then: composer test:integration   (proves the new key actually works)"
