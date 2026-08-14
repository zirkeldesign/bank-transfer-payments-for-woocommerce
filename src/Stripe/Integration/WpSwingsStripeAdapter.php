<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Payment Gateway Stripe and WooCommerce Integration by WP Swings
 * (payment-gateway-stripe-and-woocommerce-integration).
 *
 * Ships the EH_Stripe_Payment class / EH_STRIPE_VERSION constant and stores
 * keys in `woocommerce_eh_stripe_pay_settings`, with an `eh_stripe_mode`
 * toggle of 'test' | 'live'.
 */
final class WpSwingsStripeAdapter implements StripePluginAdapter
{
    public function id(): string
    {
        return 'eh-stripe';
    }

    public function label(): string
    {
        /* translators: Third-party plugin name shown in the integration notice. */
        return __('Payment Gateway Stripe and WooCommerce Integration', 'bank-transfer-payments-for-woocommerce');
    }

    public function isActive(): bool
    {
        return class_exists('EH_Stripe_Payment') || defined('EH_STRIPE_VERSION');
    }

    public function getCredentials(bool $testmode): array|false
    {
        $settings = get_option('woocommerce_eh_stripe_pay_settings', []);

        if (empty($settings) || ! is_array($settings)) {
            return false;
        }

        $prefix = $testmode ? 'eh_stripe_test_' : 'eh_stripe_live_';
        $secretKey = (string) ($settings[$prefix.'secret_key'] ?? '');
        $publishableKey = (string) ($settings[$prefix.'publishable_key'] ?? '');

        if ($secretKey === '') {
            return false;
        }

        return [
            'secret_key' => $secretKey,
            'publishable_key' => $publishableKey,
            'source' => $this->id(),
        ];
    }

    public function getCustomerId(int $userId, bool $testmode): string|false
    {
        // WP Swings does not expose a stable public customer-id meta key; fall
        // back to the generic lookup handled by PluginIntegration.
        return false;
    }
}
