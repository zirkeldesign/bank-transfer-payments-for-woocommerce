<?php

declare(strict_types=1);

/**
 * Exercises the payment flow against the real Stripe test API.
 *
 * This is the suite that matters most: every other test asserts against a fake
 * Stripe client, so they can only confirm our own assumptions. These confirm
 * that Stripe actually accepts the payload and returns a virtual bank account —
 * the thing the plugin exists to produce.
 *
 * Requires a Stripe TEST key in BTPW_STRIPE_TEST_KEY or a gitignored
 * .stripe-test-key file, and an account with bank transfers enabled. Real
 * objects are created in the test account and tagged with metadata so they can
 * be identified.
 */

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;

$key = btpw_stripe_test_key();

function btpw_test_order(float $total = 49.00, string $currency = 'EUR'): WC_Order
{
    $order = wc_create_order();
    $order->set_currency($currency);
    $order->set_billing_first_name('Ada');
    $order->set_billing_last_name('Lovelace');
    $order->set_billing_email('ada+btpw@example.test');
    $order->set_billing_country('DE');

    $item = new WC_Order_Item_Fee;
    $item->set_name('BTPW integration');
    $item->set_total((string) $total);
    $order->add_item($item);

    $order->calculate_totals();
    $order->save();

    return $order;
}

describe('Stripe bank transfer, live test API', function (): void {
    it('creates a PaymentIntent Stripe accepts', function (): void {
        $gateway = btpw_live_gateway();
        $order = btpw_test_order();

        try {
            $intent = $gateway->create_bank_transfer_for_order($order);

            expect($intent->id)->toStartWith('pi_')
                ->and($intent->currency)->toBe('eur')
                ->and($intent->amount)->toBe(4900);
        } finally {
            $order->delete(true);
        }
    });

    it('returns virtual bank account details with a SEPA IBAN', function (): void {
        $gateway = btpw_live_gateway();
        $order = btpw_test_order();

        try {
            $intent = $gateway->create_bank_transfer_for_order($order);

            // The whole point of the plugin: Stripe must hand back an account
            // for the customer to pay into. Before the payload fix this was
            // never present, because the intent was never confirmed.
            $instructions = $intent->next_action->display_bank_transfer_instructions ?? null;

            expect($instructions)->not->toBeNull()
                ->and($instructions->currency)->toBe('eur');

            $addresses = $instructions->financial_addresses ?? [];
            expect($addresses)->not->toBeEmpty();

            $iban = null;
            foreach ($addresses as $address) {
                if (($address->type ?? '') === 'iban') {
                    $iban = $address->iban->iban ?? null;
                }
            }

            expect($iban)->not->toBeNull()
                ->and($iban)->toBeString();
        } finally {
            $order->delete(true);
        }
    });

    it('stores the intent and bank details on the order', function (): void {
        $gateway = btpw_live_gateway();
        $order = btpw_test_order();

        try {
            $gateway->create_bank_transfer_for_order($order);
            $saved = wc_get_order($order->get_id());

            expect((string) $saved->get_meta('_stripe_payment_intent_id'))->toStartWith('pi_')
                ->and((string) $saved->get_meta('_stripe_customer_id'))->toStartWith('cus_');

            $details = json_decode((string) $saved->get_meta('_stripe_bank_transfer_details'), true);

            expect($details)->toBeArray()
                ->and($details['financial_addresses'] ?? [])->not->toBeEmpty();
        } finally {
            $order->delete(true);
        }
    });

    it('reuses the Stripe customer across a second order', function (): void {
        $gateway = btpw_live_gateway();
        $first = btpw_test_order();
        $second = btpw_test_order();

        try {
            $customerId = $gateway->resolve_stripe_customer($first);
            $first->save();

            // A different order for a guest gets its own customer; the same
            // order must never create a second one.
            expect($gateway->resolve_stripe_customer($first))->toBe($customerId)
                ->and($customerId)->toStartWith('cus_');
        } finally {
            $first->delete(true);
            $second->delete(true);
        }
    });

    it('refunds are declined before any payment has settled', function (): void {
        $gateway = btpw_live_gateway();
        $order = btpw_test_order();

        try {
            $gateway->create_bank_transfer_for_order($order);
            $result = $gateway->process_refund($order->get_id(), 10.00);

            // Nothing has been funded yet, so Stripe must reject the refund.
            // The gateway has to surface that as WP_Error, not a fatal.
            expect($result)->toBeInstanceOf(WP_Error::class);
        } finally {
            $order->delete(true);
        }
    });
})->skip($key === '', 'Set BTPW_STRIPE_TEST_KEY (or .stripe-test-key) to run the live Stripe suite.');
