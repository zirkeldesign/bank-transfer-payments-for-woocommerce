<?php

declare(strict_types=1);

/**
 * Checks the customer actually receives the bank details by email.
 *
 * The thank-you page is not enough on its own: customers close the tab, pay
 * days later, or forward the details to whoever does the banking. If the email
 * omits the IBAN the order is effectively unpayable.
 *
 * Outgoing mail is intercepted, never sent.
 */

/**
 * Capture wp_mail() instead of sending, returning the captured messages.
 *
 * @return array<int, array{to: mixed, subject: string, message: string}>
 */
function btpw_capture_mail(callable $action): array
{
    $captured = [];

    $filter = static function ($short, $atts) use (&$captured) {
        $captured[] = [
            'to' => $atts['to'] ?? '',
            'subject' => (string) ($atts['subject'] ?? ''),
            'message' => (string) ($atts['message'] ?? ''),
        ];

        return true; // Pretend it sent; nothing leaves the machine.
    };

    add_filter('pre_wp_mail', $filter, 10, 2);

    try {
        $action();
    } finally {
        remove_filter('pre_wp_mail', $filter, 10);
    }

    return $captured;
}

function btpw_order_awaiting_with_details(): WC_Order
{
    $order = wc_create_order();
    $order->set_currency('EUR');
    $order->set_billing_first_name('Ada');
    $order->set_billing_email('ada@example.test');
    $item = new WC_Order_Item_Fee;
    $item->set_name('Email fixture');
    $item->set_total('249.00');
    $order->add_item($item);
    $order->set_payment_method('stripe_bank_transfer');
    $order->calculate_totals();
    $order->update_meta_data('_stripe_payment_intent_id', 'pi_email_fixture');
    $order->update_meta_data('_stripe_bank_transfer_details', (string) wp_json_encode([
        'currency' => 'eur',
        'financial_addresses' => [[
            'type' => 'iban',
            'iban' => [
                'iban' => 'DE89370400440532013000',
                'bic' => 'COBADEFFXXX',
                'account_holder_name' => 'Test Shop',
            ],
        ]],
    ]));
    $order->save();

    return $order;
}

describe('Customer email', function (): void {
    it('sends a customer email when the order moves to awaiting transfer', function (): void {
        $order = btpw_order_awaiting_with_details();

        try {
            $mails = btpw_capture_mail(function () use ($order): void {
                $order->update_status('awaiting-transfer');
            });

            $toCustomer = array_filter($mails, static fn (array $m): bool => str_contains((string) $m['to'], 'ada@example.test'));

            expect($toCustomer)->not->toBeEmpty();
        } finally {
            $order->delete(true);
        }
    });

    it('includes the IBAN in the customer email', function (): void {
        $order = btpw_order_awaiting_with_details();

        try {
            $mails = btpw_capture_mail(function () use ($order): void {
                $order->update_status('awaiting-transfer');
            });

            $bodies = implode("\n", array_column($mails, 'message'));

            expect($bodies)->toContain('DE89370400440532013000')
                ->and($bodies)->toContain('COBADEFFXXX');
        } finally {
            $order->delete(true);
        }
    });
});
