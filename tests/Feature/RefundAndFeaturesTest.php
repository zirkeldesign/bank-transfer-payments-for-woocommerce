<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;
use ZirkelDesign\BankTransfersForWooCommerce\Support\Features;

describe('Features capability gate', function (): void {
    it('always enables free-core features', function (): void {
        expect(Features::has(Features::GATEWAY))->toBeTrue()
            ->and(Features::has(Features::RECONCILIATION))->toBeTrue()
            ->and(Features::has(Features::GIROCODE))->toBeTrue()
            ->and(Features::has(Features::REFUNDS))->toBeTrue();
    });

    it('disables Pro features without the add-on', function (): void {
        expect(Features::has(Features::SUBSCRIPTIONS))->toBeFalse()
            ->and(Features::has(Features::DUNNING))->toBeFalse()
            ->and(Features::has(Features::RECONCILIATION_DASHBOARD))->toBeFalse()
            ->and(Features::has(Features::EXPORT))->toBeFalse();
    });

    it('reports Pro as inactive by default', function (): void {
        expect(Features::isProActive())->toBeFalse();
    });

    it('exposes a filterable upgrade URL', function (): void {
        expect(Features::upgradeUrl())->toContain('http');
    });
});

describe('BankTransferGateway::process_refund', function (): void {
    it('declares refund support', function (): void {
        $gateway = new BankTransferGateway;

        expect($gateway->supports)->toContain('refunds');
    });

    it('refunds the full amount against the stored payment intent', function (): void {
        $gateway = btpw_configured_gateway();
        $order = new WC_Order(id: 900);
        $order->update_meta_data('_stripe_payment_intent_id', 'pi_refund');
        $GLOBALS['btpw_test_orders'][900] = $order;

        $result = $gateway->process_refund(900);

        expect($result)->toBeTrue()
            ->and($GLOBALS['btpw_test_last_refund']['payment_intent'])->toBe('pi_refund')
            ->and($GLOBALS['btpw_test_last_refund'])->not->toHaveKey('amount');
    });

    it('sends a partial refund amount in the smallest currency unit', function (): void {
        $gateway = btpw_configured_gateway();
        $order = new WC_Order(id: 901);
        $order->update_meta_data('_stripe_payment_intent_id', 'pi_partial');
        $GLOBALS['btpw_test_orders'][901] = $order;

        $gateway->process_refund(901, 12.34);

        expect($GLOBALS['btpw_test_last_refund']['amount'])->toBe(1234);
    });

    it('errors when the order has no Stripe payment', function (): void {
        $gateway = btpw_configured_gateway();
        $order = new WC_Order(id: 902);
        $GLOBALS['btpw_test_orders'][902] = $order;

        expect($gateway->process_refund(902))->toBeInstanceOf(WP_Error::class);
    });

    it('errors when Stripe is not configured', function (): void {
        $gateway = new BankTransferGateway;
        $order = new WC_Order(id: 903);
        $order->update_meta_data('_stripe_payment_intent_id', 'pi_x');
        $GLOBALS['btpw_test_orders'][903] = $order;

        expect($gateway->process_refund(903))->toBeInstanceOf(WP_Error::class);
    });
});
