<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Official WooCommerce Stripe Gateway (woocommerce-gateway-stripe).
 *
 * Keys live in the `woocommerce_stripe_settings` option; it defines the
 * WC_STRIPE_VERSION constant.
 */
final class OfficialStripeGatewayAdapter implements StripePluginAdapter
{
    public function id(): string
    {
        return 'woocommerce-gateway-stripe';
    }

    public function label(): string
    {
        /* translators: Third-party plugin name shown in the integration notice. */
        return __('WooCommerce Stripe Gateway', 'zirkel-iban-for-woocommerce');
    }

    public function isActive(): bool
    {
        return class_exists('WC_Gateway_Stripe') || class_exists('WC_Stripe') || defined('WC_STRIPE_VERSION');
    }

    public function getCredentials(bool $testmode): array|false
    {
        $settings = get_option('woocommerce_stripe_settings', []);

        if (empty($settings) || ! is_array($settings)) {
            return false;
        }

        if ($testmode) {
            $secretKey = (string) ($settings['test_secret_key'] ?? '');
            $publishableKey = (string) ($settings['test_publishable_key'] ?? '');
        } else {
            $secretKey = (string) ($settings['secret_key'] ?? '');
            $publishableKey = (string) ($settings['publishable_key'] ?? '');
        }

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
        foreach (['_stripe_customer_id', '_wc_stripe_customer_id'] as $key) {
            $customerId = get_user_meta($userId, $key, true);
            if (! empty($customerId)) {
                return (string) $customerId;
            }
        }

        return false;
    }
}
