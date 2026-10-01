<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Payment\GiroCode;

describe('GiroCode::payload', function (): void {
    it('builds a well-formed EPC069-12 payload', function (): void {
        $payload = GiroCode::payload('Muster GmbH', 'DE89370400440532013000', 'COBADEFFXXX', 123.45, 'Order 4711');

        $lines = explode("\n", (string) $payload);

        expect($lines[0])->toBe('BCD')
            ->and($lines[1])->toBe('002')
            ->and($lines[2])->toBe('1')
            ->and($lines[3])->toBe('SCT')
            ->and($lines[4])->toBe('COBADEFFXXX')
            ->and($lines[5])->toBe('Muster GmbH')
            ->and($lines[6])->toBe('DE89370400440532013000')
            ->and($lines[7])->toBe('EUR123.45')
            ->and($lines[10])->toBe('Order 4711');
    });

    it('normalises IBAN spacing and BIC case', function (): void {
        $payload = GiroCode::payload('Shop', 'de89 3704 0044 0532 0130 00', 'cobadeffxxx', 10.0, 'ref');
        $lines = explode("\n", (string) $payload);

        expect($lines[4])->toBe('COBADEFFXXX')
            ->and($lines[6])->toBe('DE89370400440532013000')
            ->and($lines[7])->toBe('EUR10.00');
    });

    it('allows an empty BIC (valid within the EU)', function (): void {
        $payload = GiroCode::payload('Shop', 'DE89370400440532013000', '', 10.0, 'ref');
        $lines = explode("\n", (string) $payload);

        expect($lines[4])->toBe('')
            ->and($payload)->not->toBeNull();
    });

    it('returns null for a missing name or IBAN', function (): void {
        expect(GiroCode::payload('', 'DE89370400440532013000', 'BIC', 10.0, 'ref'))->toBeNull()
            ->and(GiroCode::payload('Shop', '', 'BIC', 10.0, 'ref'))->toBeNull();
    });

    it('returns null for an out-of-range amount', function (): void {
        expect(GiroCode::payload('Shop', 'DE89370400440532013000', 'BIC', 0.0, 'ref'))->toBeNull()
            ->and(GiroCode::payload('Shop', 'DE89370400440532013000', 'BIC', 1_000_000_000.0, 'ref'))->toBeNull();
    });

    it('truncates an over-long beneficiary name to 70 chars', function (): void {
        $payload = GiroCode::payload(str_repeat('A', 100), 'DE89370400440532013000', 'BIC', 10.0, 'ref');
        $lines = explode("\n", (string) $payload);

        expect(strlen($lines[5]))->toBe(70);
    });
});
