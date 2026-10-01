<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\OfficialStripeGatewayAdapter;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\PaymentPluginsStripeAdapter;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\WpSwingsStripeAdapter;

describe('OfficialStripeGatewayAdapter credentials', function (): void {
    it('reads live and test secret keys from woocommerce_stripe_settings', function (): void {
        btpw_test_set_option('woocommerce_stripe_settings', [
            'secret_key' => 'sk_live_official',
            'publishable_key' => 'pk_live_official',
            'test_secret_key' => 'sk_test_official',
            'test_publishable_key' => 'pk_test_official',
        ]);

        $adapter = new OfficialStripeGatewayAdapter;

        expect($adapter->getCredentials(false)['secret_key'])->toBe('sk_live_official')
            ->and($adapter->getCredentials(true)['secret_key'])->toBe('sk_test_official')
            ->and($adapter->getCredentials(false)['source'])->toBe('woocommerce-gateway-stripe');
    });

    it('returns false when the option is empty', function (): void {
        expect((new OfficialStripeGatewayAdapter)->getCredentials(false))->toBeFalse();
    });
});

describe('PaymentPluginsStripeAdapter credentials', function (): void {
    it('reads mode-suffixed keys from woocommerce_stripe_api_settings', function (): void {
        btpw_test_set_option('woocommerce_stripe_api_settings', [
            'secret_key_live' => 'sk_live_pp',
            'secret_key_test' => 'sk_test_pp',
            'publishable_key_live' => 'pk_live_pp',
            'publishable_key_test' => 'pk_test_pp',
        ]);

        $adapter = new PaymentPluginsStripeAdapter;

        expect($adapter->getCredentials(false)['secret_key'])->toBe('sk_live_pp')
            ->and($adapter->getCredentials(true)['secret_key'])->toBe('sk_test_pp')
            ->and($adapter->getCredentials(true)['source'])->toBe('woo-stripe-payment');
    });

    it('returns false when the option is empty', function (): void {
        expect((new PaymentPluginsStripeAdapter)->getCredentials(true))->toBeFalse();
    });
});

describe('WpSwingsStripeAdapter credentials', function (): void {
    it('reads eh_stripe prefixed keys from woocommerce_eh_stripe_pay_settings', function (): void {
        btpw_test_set_option('woocommerce_eh_stripe_pay_settings', [
            'eh_stripe_live_secret_key' => 'sk_live_wps',
            'eh_stripe_test_secret_key' => 'sk_test_wps',
            'eh_stripe_live_publishable_key' => 'pk_live_wps',
            'eh_stripe_test_publishable_key' => 'pk_test_wps',
        ]);

        $adapter = new WpSwingsStripeAdapter;

        expect($adapter->getCredentials(false)['secret_key'])->toBe('sk_live_wps')
            ->and($adapter->getCredentials(true)['secret_key'])->toBe('sk_test_wps')
            ->and($adapter->getCredentials(false)['source'])->toBe('eh-stripe');
    });

    it('returns false when the option is empty', function (): void {
        expect((new WpSwingsStripeAdapter)->getCredentials(false))->toBeFalse();
    });
});
