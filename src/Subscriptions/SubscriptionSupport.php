<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Subscriptions;

use Throwable;
use WC_Order;
use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in WooCommerce Subscriptions support for the bank-transfer gateway.
 *
 * Bank transfers are a push payment and cannot be charged automatically, so
 * this implements the MANUAL-RENEWAL model (as WooCommerce's own BACS gateway
 * does): each renewal issues a fresh virtual bank account that the customer
 * pays, and the webhook completes the renewal order — which advances the
 * subscription to its next period.
 *
 * This layer is inert unless the gateway's "Subscriptions" setting is on AND
 * the WooCommerce Subscriptions extension is active.
 */
final class SubscriptionSupport
{
    /**
     * WooCommerce Subscriptions capability flags. Note the ABSENCE of any
     * automatic-payment flag — renewals are manual by design.
     *
     * @var array<int, string>
     */
    private const SUPPORTS = [
        'subscriptions',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
        'subscription_amount_changes',
        'subscription_date_changes',
        'multiple_subscriptions',
    ];

    public function __construct(private readonly BankTransferGateway $gateway) {}

    public static function attach(BankTransferGateway $gateway): self
    {
        $support = new self($gateway);
        $support->register();

        return $support;
    }

    public function register(): void
    {
        $this->gateway->supports = array_values(array_unique(
            array_merge($this->gateway->supports, self::SUPPORTS)
        ));

        add_action(
            'woocommerce_scheduled_subscription_payment_'.BankTransferGateway::GATEWAY_ID,
            [$this, 'process_renewal_payment'],
            10,
            2
        );
    }

    /**
     * @return array<int, string>
     */
    public function supported_features(): array
    {
        return self::SUPPORTS;
    }

    /**
     * Issue a new virtual bank account for a subscription renewal order.
     *
     * WooCommerce Subscriptions calls this on the renewal schedule with the
     * amount and the renewal order. We do not (and cannot) charge; we set the
     * renewal order awaiting transfer and let the webhook complete it.
     */
    public function process_renewal_payment(float $amount, WC_Order $renewalOrder): void
    {
        try {
            $this->gateway->create_bank_transfer_for_order($renewalOrder);
        } catch (Throwable $e) {
            $renewalOrder->add_order_note(sprintf(
                /* translators: %s: Error message. */
                __('Could not create bank transfer for subscription renewal: %s', 'bank-transfer-payments-for-woocommerce'),
                $e->getMessage()
            ));
        }
    }
}
