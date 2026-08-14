<?php

declare(strict_types=1);

/**
 * Drives the real REST route with real Stripe signatures.
 *
 * Reconciliation is what this plugin is for, and none of it had ever been
 * exercised end to end: the unit tests call dispatch() directly, skipping the
 * route registration, the signature verification and the request plumbing —
 * which is precisely where a webhook integration breaks.
 *
 * No Stripe key is needed; the signature scheme is HMAC-SHA256 over
 * "{timestamp}.{payload}", so a correctly signed request can be built locally.
 */

use ZirkelDesign\BankTransfersForWooCommerce\Webhook\WebhookHandler;

const BTPW_TEST_WEBHOOK_SECRET = 'whsec_integration_test_secret';

beforeEach(function (): void {
    $settings = (array) get_option('woocommerce_stripe_bank_transfer_settings', []);
    $settings['webhook_secret'] = BTPW_TEST_WEBHOOK_SECRET;
    $settings['enabled'] = 'yes';
    update_option('woocommerce_stripe_bank_transfer_settings', $settings);

    // The gateway list is cached per request; make sure the handler can find it.
    WC()->payment_gateways()->init();
});

/**
 * Sign a payload the way Stripe does, so constructEvent() accepts it.
 */
function btpw_stripe_signature(string $payload, ?int $timestamp = null, string $secret = BTPW_TEST_WEBHOOK_SECRET): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return 't='.$timestamp.',v1='.$signature;
}

/**
 * POST an event through the real REST route.
 *
 * @param  array<string, mixed>  $event
 */
function btpw_post_webhook(array $event, ?string $signature = null): WP_REST_Response|WP_Error
{
    $payload = (string) wp_json_encode($event);

    $request = new WP_REST_Request('POST', '/'.WebhookHandler::ROUTE_NAMESPACE.'/webhook');
    $request->set_body($payload);
    $request->set_header('content-type', 'application/json');
    $request->set_header('stripe-signature', $signature ?? btpw_stripe_signature($payload));

    return rest_get_server()->dispatch($request);
}

function btpw_awaiting_order(string $intentId): WC_Order
{
    $order = wc_create_order();
    $order->set_currency('EUR');
    $order->set_billing_email('ada@example.test');
    $item = new WC_Order_Item_Fee;
    $item->set_name('Webhook fixture');
    $item->set_total('100.00');
    $order->add_item($item);
    $order->set_payment_method('stripe_bank_transfer');
    $order->calculate_totals();
    $order->update_meta_data('_stripe_payment_intent_id', $intentId);
    $order->update_status('awaiting-transfer');
    $order->save();

    return $order;
}

/**
 * @return array<string, mixed>
 */
function btpw_intent_event(string $type, string $intentId, int $orderId, int $amount = 10000): array
{
    return [
        'id' => 'evt_test_'.substr(md5($intentId.$type), 0, 12),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => [
            'id' => $intentId,
            'object' => 'payment_intent',
            'amount' => $amount,
            'amount_received' => $amount,
            'currency' => 'eur',
            'metadata' => ['order_id' => (string) $orderId],
        ]],
    ];
}

describe('Webhook route registration', function (): void {
    it('registers the endpoint WordPress will actually serve', function (): void {
        $routes = rest_get_server()->get_routes();

        expect($routes)->toHaveKey('/'.WebhookHandler::ROUTE_NAMESPACE.'/webhook');
    });
});

describe('Webhook signature verification', function (): void {
    it('rejects a request with no signature header', function (): void {
        $order = btpw_awaiting_order('pi_nosig');

        try {
            $response = btpw_post_webhook(btpw_intent_event('payment_intent.succeeded', 'pi_nosig', $order->get_id()), '');

            expect($response->get_status())->toBe(400)
                ->and(wc_get_order($order->get_id())->get_status())->toBe('awaiting-transfer');
        } finally {
            $order->delete(true);
        }
    });

    it('rejects a forged signature', function (): void {
        $order = btpw_awaiting_order('pi_forged');
        $event = btpw_intent_event('payment_intent.succeeded', 'pi_forged', $order->get_id());

        try {
            $forged = btpw_stripe_signature((string) wp_json_encode($event), null, 'whsec_wrong_secret');
            $response = btpw_post_webhook($event, $forged);

            // The order must not be paid by an unsigned or wrongly signed request.
            expect($response->get_status())->toBe(400)
                ->and(wc_get_order($order->get_id())->get_status())->toBe('awaiting-transfer');
        } finally {
            $order->delete(true);
        }
    });

    it('rejects a signature outside Stripe\'s tolerance window', function (): void {
        $order = btpw_awaiting_order('pi_stale');
        $event = btpw_intent_event('payment_intent.succeeded', 'pi_stale', $order->get_id());

        try {
            $stale = btpw_stripe_signature((string) wp_json_encode($event), time() - 86400);
            $response = btpw_post_webhook($event, $stale);

            expect($response->get_status())->toBe(400)
                ->and(wc_get_order($order->get_id())->get_status())->toBe('awaiting-transfer');
        } finally {
            $order->delete(true);
        }
    });
});

describe('Webhook reconciliation', function (): void {
    it('marks the order paid on payment_intent.succeeded', function (): void {
        $order = btpw_awaiting_order('pi_ok');

        try {
            $response = btpw_post_webhook(btpw_intent_event('payment_intent.succeeded', 'pi_ok', $order->get_id()));
            $updated = wc_get_order($order->get_id());

            expect($response->get_status())->toBe(200)
                ->and($updated->get_status())->toBeIn(['processing', 'completed'])
                ->and((string) $updated->get_meta('_stripe_payment_intent_status'))->toBe('succeeded');
        } finally {
            $order->delete(true);
        }
    });

    it('resolves the order by stored intent id when metadata is missing', function (): void {
        $order = btpw_awaiting_order('pi_by_meta');
        $event = btpw_intent_event('payment_intent.succeeded', 'pi_by_meta', $order->get_id());
        unset($event['data']['object']['metadata']);

        try {
            btpw_post_webhook($event);

            expect(wc_get_order($order->get_id())->get_status())->toBeIn(['processing', 'completed']);
        } finally {
            $order->delete(true);
        }
    });

    it('records an underpayment without marking the order paid', function (): void {
        $order = btpw_awaiting_order('pi_partial');
        $event = btpw_intent_event('payment_intent.partially_funded', 'pi_partial', $order->get_id());
        $event['data']['object']['amount_received'] = 4000;

        try {
            btpw_post_webhook($event);
            $updated = wc_get_order($order->get_id());

            expect($updated->get_status())->toBe('awaiting-transfer')
                ->and((string) $updated->get_meta('_btpw_amount_received'))->toBe('4000');
        } finally {
            $order->delete(true);
        }
    });

    it('is idempotent when Stripe retries the same event', function (): void {
        $order = btpw_awaiting_order('pi_retry');
        $event = btpw_intent_event('payment_intent.succeeded', 'pi_retry', $order->get_id());

        try {
            btpw_post_webhook($event);
            $first = wc_get_order($order->get_id())->get_status();

            // Stripe retries until it gets a 2xx; a repeat must not double-process.
            $second = btpw_post_webhook($event);

            expect($second->get_status())->toBe(200)
                ->and(wc_get_order($order->get_id())->get_status())->toBe($first);
        } finally {
            $order->delete(true);
        }
    });

    it('fails the order on payment_intent.payment_failed', function (): void {
        $order = btpw_awaiting_order('pi_failed');

        try {
            btpw_post_webhook(btpw_intent_event('payment_intent.payment_failed', 'pi_failed', $order->get_id()));

            expect(wc_get_order($order->get_id())->get_status())->toBe('failed');
        } finally {
            $order->delete(true);
        }
    });
});
