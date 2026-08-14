<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Stripe\ClientFactory;

describe('ClientFactory', function (): void {
    it('returns null when no secret key is provided', function (): void {
        expect(ClientFactory::make(''))->toBeNull();
    });

    it('pins the Stripe API version the plugin is written against', function (): void {
        expect(ClientFactory::API_VERSION)->toBe('2023-10-16');
    });

    it('honours an injected client factory', function (): void {
        $fake = new stdClass;
        ClientFactory::override(fn (string $key): object => $fake);

        expect(ClientFactory::make('sk_test_123'))->toBe($fake);
    });

    it('receives the secret key in the injected factory', function (): void {
        $captured = null;
        ClientFactory::override(function (string $key) use (&$captured): object {
            $captured = $key;

            return new stdClass;
        });

        ClientFactory::make('sk_live_abc');

        expect($captured)->toBe('sk_live_abc');
    });

    it('reset removes the injected factory', function (): void {
        ClientFactory::override(fn (string $key): object => new stdClass);
        ClientFactory::reset();

        expect(ClientFactory::make(''))->toBeNull();
    });
});
