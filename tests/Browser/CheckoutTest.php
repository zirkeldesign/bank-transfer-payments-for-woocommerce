<?php

declare(strict_types=1);

/**
 * End-to-end browser tests (Pest 5 browser plugin).
 *
 * These are NOT part of the default `composer test` run — they require a live
 * WooCommerce store with this plugin active and a Stripe TEST key configured,
 * plus Playwright browsers installed once via `npx playwright install`.
 *
 * Run against a store:  BTPW_E2E_URL="https://staging.example.com" composer test:browser
 */
$baseUrl = getenv('BTPW_E2E_URL') ?: null;

it('offers the bank transfer method at checkout', function () use ($baseUrl): void {
    $page = visit($baseUrl.'/checkout/');

    $page->assertSee('Bank Transfer');
})->skip($baseUrl === null, 'Set BTPW_E2E_URL to a running WooCommerce store to run browser e2e tests.');

it('shows virtual bank account instructions after placing an order', function () use ($baseUrl): void {
    // Placeholder for the full flow: add product → checkout → select Bank
    // Transfer → place order → assert the thank-you page renders IBAN/BIC.
    $page = visit($baseUrl.'/checkout/');

    $page->assertSee('Bank Transfer');
})->skip($baseUrl === null, 'Set BTPW_E2E_URL to a running WooCommerce store to run browser e2e tests.');
