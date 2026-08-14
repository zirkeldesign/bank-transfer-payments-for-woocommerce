<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Stripe\PluginIntegration;

describe('PluginIntegration detection', function (): void {
    it('detects no Stripe plugin in a clean environment', function (): void {
        expect(PluginIntegration::detectStripePlugin())->toBeFalse();
    });

    it('returns no credentials when nothing is detected', function (): void {
        expect(PluginIntegration::getStripeCredentials(false))->toBeFalse();
    });

    it('caches the credentials lookup', function (): void {
        $first = PluginIntegration::getStripeCredentials(false);
        $second = PluginIntegration::getStripeCredentials(false);

        expect($first)->toBe($second)->toBeFalse();
    });

    it('offers no integration notice without a detected plugin', function (): void {
        expect(PluginIntegration::getIntegrationNotice())->toBeFalse();
    });

    it('returns no Stripe client without credentials', function (): void {
        expect(PluginIntegration::getStripeClient(false))->toBeFalse();
    });
});

describe('PluginIntegration credential preference', function (): void {
    it('does not reuse external credentials when the merchant set a live key', function (): void {
        $gateway = new stdClass;
        $gateway->testmode = false;
        $gateway->test_secret_key = '';
        $gateway->live_secret_key = 'sk_live_merchant';

        expect(PluginIntegration::shouldUseExistingCredentials($gateway))->toBeFalse();
    });

    it('does not reuse external credentials when the merchant set a test key', function (): void {
        $gateway = new stdClass;
        $gateway->testmode = true;
        $gateway->test_secret_key = 'sk_test_merchant';
        $gateway->live_secret_key = '';

        expect(PluginIntegration::shouldUseExistingCredentials($gateway))->toBeFalse();
    });

    it('does not reuse when no key is set and no plugin is present', function (): void {
        $gateway = new stdClass;
        $gateway->testmode = true;
        $gateway->test_secret_key = '';
        $gateway->live_secret_key = '';

        expect(PluginIntegration::shouldUseExistingCredentials($gateway))->toBeFalse();
    });
});

describe('PluginIntegration customer id resolution', function (): void {
    it('falls back to the generic user meta key', function (): void {
        btpw_test_set_option('user_meta_7__stripe_customer_id', 'cus_generic');

        expect(PluginIntegration::getStripeCustomerId(7, false))->toBe('cus_generic');
    });

    it('returns false when the user has no stored customer id', function (): void {
        expect(PluginIntegration::getStripeCustomerId(999, false))->toBeFalse();
    });
});
