<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;

describe('BankTransferGateway configuration', function (): void {
    it('keeps a stable gateway id for back-compatibility', function (): void {
        expect(BankTransferGateway::GATEWAY_ID)->toBe('stripe_bank_transfer');
    });

    it('registers the expected settings fields', function (): void {
        $gateway = new BankTransferGateway;

        expect($gateway->form_fields)->toHaveKeys([
            'enabled',
            'title',
            'description',
            'testmode',
            'test_secret_key',
            'live_secret_key',
            'webhook_secret',
            'transfer_type',
            'default_currency',
            'debug_mode',
        ]);
    });
});

describe('BankTransferGateway::build_payment_intent_data', function (): void {
    it('builds a customer_balance PaymentIntent from the order', function (): void {
        $gateway = new BankTransferGateway;
        $gateway->transfer_type = 'eu_bank_account';

        $order = new WC_Order(id: 4711, currency: 'EUR', total: 100.0);

        $data = $gateway->build_payment_intent_data($order);

        expect($data['amount'])->toBe(10000)
            ->and($data['currency'])->toBe('eur')
            ->and($data['payment_method_types'])->toBe(['customer_balance'])
            ->and($data['payment_method_data']['type'])->toBe('customer_balance')
            ->and($data['payment_method_options']['customer_balance']['funding_type'])->toBe('eu_bank_account')
            ->and($data['payment_method_options']['customer_balance']['bank_transfer']['type'])->toBe('eu_bank_account');
    });

    it('attaches the order id and buyer details as metadata', function (): void {
        $gateway = new BankTransferGateway;

        $order = new WC_Order(id: 4711, currency: 'EUR', total: 50.0, email: 'ada@example.test');

        $data = $gateway->build_payment_intent_data($order);

        expect($data['metadata']['order_id'])->toBe(4711)
            ->and($data['metadata']['customer_email'])->toBe('ada@example.test')
            ->and($data['metadata']['customer_name'])->toBe('Ada Lovelace');
    });

    it('describes the order with its number and the site name', function (): void {
        $gateway = new BankTransferGateway;

        $order = new WC_Order(id: 4711, currency: 'EUR', total: 50.0);

        $data = $gateway->build_payment_intent_data($order);

        expect($data['description'])->toBe('Order 4711 from Test Shop');
    });

    it('converts the order total to the smallest currency unit', function (): void {
        $gateway = new BankTransferGateway;

        $order = new WC_Order(id: 1, currency: 'EUR', total: 12.34);

        $data = $gateway->build_payment_intent_data($order);

        expect($data['amount'])->toBe(1234);
    });
});
