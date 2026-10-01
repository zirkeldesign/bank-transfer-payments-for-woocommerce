<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\ClientFactory;
use ZirkelDesign\BankTransfersForWooCommerce\Subscriptions\SubscriptionSupport;

function btpw_configured_gateway(string $intentId = 'pi_test'): BankTransferGateway
{
    btpw_test_set_gateway_setting('testmode', 'yes');
    btpw_test_set_gateway_setting('test_secret_key', 'sk_test_x');
    ClientFactory::override(fn (): object => btpw_fake_stripe_client($intentId));

    return new BankTransferGateway;
}

describe('BankTransferGateway::create_bank_transfer_for_order', function (): void {
    it('creates the intent, stores meta and sets the awaiting-transfer status', function (): void {
        $gateway = btpw_configured_gateway('pi_order');
        $order = new WC_Order(id: 501);

        $intent = $gateway->create_bank_transfer_for_order($order);

        expect($intent->id)->toBe('pi_order')
            ->and($order->get_meta('_stripe_payment_intent_id'))->toBe('pi_order')
            ->and($order->get_meta('_stripe_bank_transfer_details'))->not->toBe('')
            ->and($order->status)->toBe('wc-awaiting-transfer')
            ->and($order->saved)->toBeTrue();
    });

    it('throws when no Stripe client is configured', function (): void {
        $gateway = new BankTransferGateway;
        $order = new WC_Order(id: 1);

        $gateway->create_bank_transfer_for_order($order);
    })->throws(RuntimeException::class);

    it('honours a configured awaiting-payment status', function (): void {
        btpw_test_set_gateway_setting('order_status_awaiting', 'on-hold');
        $gateway = btpw_configured_gateway('pi_status');
        $order = new WC_Order(id: 601);

        $gateway->create_bank_transfer_for_order($order);

        expect($order->status)->toBe('wc-on-hold');
    });
});

describe('SubscriptionSupport', function (): void {
    it('declares manual-renewal subscription capabilities (and no auto-charge flag)', function (): void {
        $gateway = btpw_configured_gateway();
        SubscriptionSupport::attach($gateway);

        expect($gateway->supports)->toContain('subscriptions')
            ->and($gateway->supports)->toContain('subscription_cancellation')
            ->and($gateway->supports)->toContain('multiple_subscriptions')
            ->and($gateway->supports)->not->toContain('gateway_scheduled_payments');
    });

    it('issues a fresh virtual account for a renewal order', function (): void {
        $support = SubscriptionSupport::attach(btpw_configured_gateway('pi_renewal'));

        $renewal = new WC_Order(id: 777);
        $support->process_renewal_payment(19.99, $renewal);

        expect($renewal->get_meta('_stripe_payment_intent_id'))->toBe('pi_renewal')
            ->and($renewal->status)->toBe('wc-awaiting-transfer');
    });

    it('records a note on the renewal order when the transfer cannot be created', function (): void {
        $support = SubscriptionSupport::attach(new BankTransferGateway);

        $renewal = new WC_Order(id: 888);
        $support->process_renewal_payment(9.99, $renewal);

        expect($renewal->notes)->not->toBeEmpty();
    });
});
