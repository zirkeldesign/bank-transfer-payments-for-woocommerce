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
        $gateway->transfer_type = 'eu_bank_transfer';

        $order = new WC_Order(id: 4711, currency: 'EUR', total: 100.0);

        $data = $gateway->build_payment_intent_data($order);

        expect($data['amount'])->toBe(10000)
            ->and($data['currency'])->toBe('eur')
            ->and($data['payment_method_types'])->toBe(['customer_balance'])
            ->and($data['payment_method_data']['type'])->toBe('customer_balance')
            ->and($data['payment_method_options']['customer_balance']['funding_type'])->toBe('bank_transfer')
            ->and($data['payment_method_options']['customer_balance']['bank_transfer']['type'])->toBe('eu_bank_transfer')
            ->and($data['confirm'])->toBeTrue();
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

describe('Stripe customer_balance payload requirements', function (): void {
    it('sends the literal bank_transfer funding type, not the scheme', function (): void {
        $gateway = new BankTransferGateway;
        $data = $gateway->build_payment_intent_data(new WC_Order(id: 1));

        // Stripe rejects a scheme name here; funding_type is always literal.
        expect($data['payment_method_options']['customer_balance']['funding_type'])->toBe('bank_transfer');
    });

    it('confirms the intent so a virtual account is actually issued', function (): void {
        $data = (new BankTransferGateway)->build_payment_intent_data(new WC_Order(id: 1));

        expect($data['confirm'])->toBeTrue();
    });

    it('includes the customer whose cash balance funds the payment', function (): void {
        $data = (new BankTransferGateway)->build_payment_intent_data(new WC_Order(id: 1), 'cus_123');

        expect($data['customer'])->toBe('cus_123');
    });

    it('omits the customer key when none is supplied', function (): void {
        $data = (new BankTransferGateway)->build_payment_intent_data(new WC_Order(id: 1));

        expect($data)->not->toHaveKey('customer');
    });

    it('sets the localised IBAN country for EU transfers', function (): void {
        $gateway = new BankTransferGateway;
        $gateway->transfer_type = 'eu_bank_transfer';

        $data = $gateway->build_payment_intent_data(new WC_Order(id: 1));

        expect($data['payment_method_options']['customer_balance']['bank_transfer']['eu_bank_transfer']['country'])->toBe('DE');
    });

    it('omits the EU country block for non-EU schemes', function (): void {
        $gateway = new BankTransferGateway;
        $gateway->transfer_type = 'us_bank_transfer';

        $data = $gateway->build_payment_intent_data(new WC_Order(id: 1));

        expect($data['payment_method_options']['customer_balance']['bank_transfer'])->not->toHaveKey('eu_bank_transfer');
    });

    it('translates legacy stored transfer types', function (): void {
        expect(BankTransferGateway::normaliseTransferType('eu_bank_account'))->toBe('eu_bank_transfer')
            ->and(BankTransferGateway::normaliseTransferType('us_bank_account'))->toBe('us_bank_transfer')
            ->and(BankTransferGateway::normaliseTransferType('eu_bank_transfer'))->toBe('eu_bank_transfer');
    });
});

describe('Stripe minimum amount', function (): void {
    it('defaults to the 0.50 floor Stripe enforces', function (): void {
        expect((new BankTransferGateway)->minimum_amount())->toBe(0.50);
    });

    it('is available outside a cart context when enabled', function (): void {
        // Admin and REST have no cart; the gateway must not hide itself there.
        btpw_test_set_gateway_setting('enabled', 'yes');

        expect((new BankTransferGateway)->is_available())->toBeTrue();
    });

    it('stays unavailable while disabled', function (): void {
        btpw_test_set_gateway_setting('enabled', 'no');

        expect((new BankTransferGateway)->is_available())->toBeFalse();
    });
});

describe('Stripe financial address rendering', function (): void {
    it('reads the iban type Stripe actually sends, not "sepa"', function (): void {
        $rows = BankTransferGateway::financialAddressRows([
            'type' => 'iban',
            'iban' => ['iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'account_holder_name' => 'Shop'],
        ]);

        expect(implode(' ', $rows))->toContain('DE89370400440532013000')
            ->and(implode(' ', $rows))->toContain('COBADEFFXXX');
    });

    it('reads the aba type Stripe actually sends, not "ach"', function (): void {
        $rows = BankTransferGateway::financialAddressRows([
            'type' => 'aba',
            'aba' => ['account_number' => '000123456789', 'routing_number' => '110000000'],
        ]);

        expect(implode(' ', $rows))->toContain('000123456789')
            ->and(implode(' ', $rows))->toContain('110000000');
    });

    it('supports sort_code, spei and zengin', function (): void {
        expect(BankTransferGateway::financialAddressRows(['type' => 'sort_code', 'sort_code' => ['account_number' => '1', 'sort_code' => '2']]))->not->toBeEmpty()
            ->and(BankTransferGateway::financialAddressRows(['type' => 'spei', 'spei' => ['clabe' => '3']]))->not->toBeEmpty()
            ->and(BankTransferGateway::financialAddressRows(['type' => 'zengin', 'zengin' => ['account_number' => '4']]))->not->toBeEmpty();
    });

    it('returns nothing for an unknown or empty address', function (): void {
        expect(BankTransferGateway::financialAddressRows(['type' => 'sepa', 'sepa' => ['iban' => 'x']]))->toBe([])
            ->and(BankTransferGateway::financialAddressRows(['type' => 'iban']))->toBe([]);
    });

    it('omits empty values rather than printing blank rows', function (): void {
        $rows = BankTransferGateway::financialAddressRows(['type' => 'iban', 'iban' => ['iban' => 'DE1', 'bic' => '']]);

        expect($rows)->toHaveCount(1);
    });

    it('labels each Stripe address type', function (): void {
        expect(BankTransferGateway::financialAddressLabel('iban'))->toContain('SEPA')
            ->and(BankTransferGateway::financialAddressLabel('aba'))->toContain('ACH')
            ->and(BankTransferGateway::financialAddressLabel('zengin'))->toContain('Japanese');
    });
});
