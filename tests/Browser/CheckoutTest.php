<?php

declare(strict_types=1);

/**
 * End-to-end browser tests (Pest 4 browser plugin).
 *
 * These cover the one path no other suite reaches: what the customer actually
 * sees. That matters most for BlocksSupport, because WooCommerce's default
 * checkout is block-based and a classic gateway is simply invisible there
 * without it - a failure mode that every PHP test in this repo would miss.
 *
 * NOT part of the default `composer test` run: they need a running store and
 * Playwright, installed once via `npx playwright install chromium`.
 *
 *   composer test:integration:setup     # provisions the store
 *   composer test:e2e                   # serves it and runs this file
 *
 * Nothing here may assume an English store. WooCommerce page slugs are
 * translated and merchant-editable - a German shop serves /kasse/ rather than
 * /checkout/, and the gateway shows "Banküberweisung" rather than "Bank
 * Transfer". Every locale-dependent value is therefore injected, and the
 * assertions lean on the IBAN, which no translation touches.
 *
 *   BTPW_E2E_URL            http://127.0.0.1:8903   (required)
 *   BTPW_E2E_CHECKOUT_PATH  /kasse/                 (default /checkout/)
 *   BTPW_E2E_GATEWAY_TITLE  Banküberweisung         (default Bank Transfer)
 *   BTPW_E2E_PRODUCT_ID     28                      (default 28)
 */
$baseUrl = getenv('BTPW_E2E_URL') ?: null;
$checkoutPath = getenv('BTPW_E2E_CHECKOUT_PATH') ?: '/checkout/';
$gatewayTitle = getenv('BTPW_E2E_GATEWAY_TITLE') ?: 'Bank Transfer';
$productId = getenv('BTPW_E2E_PRODUCT_ID') ?: '28';

$base = rtrim((string) $baseUrl, '/');
$checkoutUrl = $base.'/'.ltrim($checkoutPath, '/');
$addToCartUrl = $base.'/?add-to-cart='.$productId;
$skipReason = 'Set BTPW_E2E_URL to a running WooCommerce store to run browser e2e tests.';

// Placing a real order calls Stripe, so that test needs a test key. The gateway
// itself does not check for one in is_available(), so the checkout test above
// still runs without it - which is the part CI can cover unattended.
$hasStripeKey = getenv('BTPW_STRIPE_TEST_KEY') !== false || file_exists(__DIR__.'/../../.stripe-test-key');
$noKeyReason = 'Set BTPW_STRIPE_TEST_KEY (or write .stripe-test-key) to place a real order.';

/**
 * Fills the block checkout's billing form and returns the page.
 *
 * The country has to be selected before the address fields exist: the block
 * checkout renders locale-specific fields (postcode, city) only once a country
 * is chosen, so filling them first silently does nothing.
 */
function btpw_fill_checkout(string $addToCartUrl, string $checkoutUrl)
{
    return visit($addToCartUrl)
        ->navigate($checkoutUrl)
        // fill() waits for the element on its own, but the country has to be
        // selected before the address fields exist at all: the block checkout
        // renders locale-specific fields (postcode, city) only once a country
        // is chosen, so filling them first silently does nothing.
        ->fill('#email', 'kunde@example.test')
        ->select('#billing-country', 'DE')
        ->wait(2)
        ->fill('#billing-first_name', 'Erika')
        ->fill('#billing-last_name', 'Mustermann')
        ->fill('#billing-address_1', 'Musterstr. 1')
        ->fill('#billing-postcode', '10115')
        ->fill('#billing-city', 'Berlin')
        ->wait(2);
}

it('offers the bank transfer method in the block checkout', function () use ($addToCartUrl, $checkoutUrl, $gatewayTitle): void {
    btpw_fill_checkout($addToCartUrl, $checkoutUrl)
        ->assertSee($gatewayTitle);
})->skip($baseUrl === null, $skipReason);

it('shows virtual bank account instructions after placing an order', function () use ($addToCartUrl, $checkoutUrl, $gatewayTitle): void {
    // Placing the order calls Stripe for real, so give it room before asserting.
    btpw_fill_checkout($addToCartUrl, $checkoutUrl)
        ->click($gatewayTitle)
        ->click('.wc-block-components-checkout-place-order-button')
        ->wait(15)
        // Assert on IBAN and BIC rather than on the surrounding prose: Stripe
        // issues a fresh virtual account per order, and every label around it
        // is translatable while these two are not.
        ->assertSee('IBAN')
        ->assertSee('BIC');
})->skip($baseUrl === null, $skipReason)->skip(! $hasStripeKey, $noKeyReason);
