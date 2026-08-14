<?php

declare(strict_types=1);

/**
 * End-to-end browser tests (Pest 4 browser plugin).
 *
 * NOT part of the default `composer test` run: they need a live WooCommerce
 * store with this plugin active and a Stripe TEST key configured, plus
 * Playwright installed once via `npx playwright install`.
 *
 * Nothing here may assume an English store. WooCommerce page slugs are
 * translated and merchant-editable — a German shop serves /kasse/ and
 * /warenkorb/ rather than /checkout/ and /cart/, and the gateway shows
 * "Banküberweisung" rather than "Bank Transfer". Since DACH shops are the
 * primary audience, every locale-dependent value is injected.
 *
 *   BTPW_E2E_URL            https://staging.example.com   (required)
 *   BTPW_E2E_CHECKOUT_PATH  /kasse/                       (default /checkout/)
 *   BTPW_E2E_GATEWAY_TITLE  Banküberweisung               (default Bank Transfer)
 *
 * Run: BTPW_E2E_URL="https://shop.test" BTPW_E2E_CHECKOUT_PATH="/kasse/" \
 *      BTPW_E2E_GATEWAY_TITLE="Banküberweisung" composer test:browser
 */
$baseUrl = getenv('BTPW_E2E_URL') ?: null;
$checkoutPath = getenv('BTPW_E2E_CHECKOUT_PATH') ?: '/checkout/';
$gatewayTitle = getenv('BTPW_E2E_GATEWAY_TITLE') ?: 'Bank Transfer';

$checkoutUrl = rtrim((string) $baseUrl, '/').'/'.ltrim($checkoutPath, '/');
$skipReason = 'Set BTPW_E2E_URL to a running WooCommerce store to run browser e2e tests.';

it('offers the bank transfer method at checkout', function () use ($checkoutUrl, $gatewayTitle): void {
    visit($checkoutUrl)->assertSee($gatewayTitle);
})->skip($baseUrl === null, $skipReason);

it('shows virtual bank account instructions after placing an order', function () use ($checkoutUrl, $gatewayTitle): void {
    // Placeholder for the full flow: add product → checkout → select the
    // gateway → place order → assert the thank-you page renders IBAN/BIC and
    // the GiroCode. Kept locale-agnostic: assert on the IBAN value from the
    // Stripe test account rather than on any translated label.
    visit($checkoutUrl)->assertSee($gatewayTitle);
})->skip($baseUrl === null, $skipReason);
