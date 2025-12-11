<?php

use WC_Stripe_Plugin_Integration;

describe('Stripe Plugin Integration', function () {

    it('detects no plugin when none is active', function () {
        $plugin = WC_Stripe_Plugin_Integration::detect_stripe_plugin();
        expect($plugin)->toBeFalse();
    });

    it('can clear cache', function () {
        WC_Stripe_Plugin_Integration::clear_cache();
        expect(true)->toBeTrue();
    });

});

describe('Stripe Credentials', function () {

    it('returns false when no plugin is detected', function () {
        $credentials = WC_Stripe_Plugin_Integration::get_stripe_credentials();
        expect($credentials)->toBeFalse();
    });

});
