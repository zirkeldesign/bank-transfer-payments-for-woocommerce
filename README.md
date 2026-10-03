# Zirkel Virtual IBAN for WooCommerce

Accept **reconciled** bank transfer payments in WooCommerce via Stripe. Each order
receives its own unique virtual bank account (SEPA IBAN, UK Bacs, US ACH, Mexican
SPEI, Japanese Zengin) through Stripe's `customer_balance` funding flow, and a
Stripe webhook marks the order paid automatically — no manual bank-statement
matching.

Built DACH-first: SEPA / EUR is the default.

## Requirements

- PHP 8.3+
- WordPress 6.5+
- WooCommerce 9.0+
- A Stripe account with bank-transfer (customer_balance) enabled for your country/currency

## How it works

1. Customer selects **Bank Transfer** at checkout.
2. The plugin creates a Stripe PaymentIntent (`customer_balance`) and stores the
   returned virtual bank account details on the order.
3. The order is set to **Awaiting Bank Transfer**; instructions are shown on the
   thank-you page and emailed to the customer.
4. When the transfer lands, Stripe fires `payment_intent.succeeded`; the signed
   webhook marks the order paid.

Because Stripe holds the receiving account, the payee shown to the customer is your
Stripe **Business Name** (Dashboard → Settings → Business details), not your shop name.
That is also the name Stripe answers SEPA *Verification of Payee* checks with, mandatory
EU-wide since 9 October 2025, so the instructions surface it first and explain the
name-match notice a customer's bank may show. Reword that note with the `btpw_vop_notice`
filter.

## Stripe credential reuse

If the official WooCommerce Stripe Gateway, Payment Plugins for Stripe
WooCommerce, or WP Swings' Payment Gateway Stripe and WooCommerce Integration is
already configured, this plugin can reuse its API keys. Add support for another
plugin with the `btpw_stripe_plugin_adapters` filter:

```php
add_filter('btpw_stripe_plugin_adapters', function (array $adapters): array {
    $adapters[] = new My_Stripe_Adapter(); // implements StripePluginAdapter
    return $adapters;
});
```

Your gateway's **Test mode** is independent of the host plugin's mode — a test-mode
lookup always returns the host's *test* keys, even while it serves live traffic.

## Development

```bash
composer install
composer test          # Pest 4 unit + feature suite
composer phpstan       # PHPStan level 6
composer format        # Pint (Laravel preset)
composer test:browser  # opt-in Pest browser e2e (needs a live store + BTPW_E2E_URL)
composer dist          # build the scoped, distributable zip
composer pcp           # run WordPress.org Plugin Check on the built zip
```

`composer dist` bundles the Stripe SDK **namespace-scoped** (via Strauss) into
`ZirkelDesign\BankTransfersForWooCommerce\Vendor\Stripe\…` so it never collides
with another Stripe plugin's bundled copy.

## License

GPL-2.0-or-later.
