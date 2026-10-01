<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Payment Plugins for Stripe WooCommerce (woo-stripe-payment).
 *
 * Ships the WC_Stripe_Manager class and stores keys in the shared
 * `woocommerce_stripe_api_settings` option, suffixed per mode.
 */
final class PaymentPluginsStripeAdapter implements StripePluginAdapter
{
    public function id(): string
    {
        return 'woo-stripe-payment';
    }

    public function label(): string
    {
        /* translators: Third-party plugin name shown in the integration notice. */
        return __('Payment Plugins for Stripe WooCommerce', 'bank-transfer-payments-for-woocommerce');
    }

    public function isActive(): bool
    {
        return class_exists('WC_Stripe_Manager');
    }

    public function getCredentials(bool $testmode): array|false
    {
        $settings = get_option('woocommerce_stripe_api_settings', []);

        if (empty($settings) || ! is_array($settings)) {
            return false;
        }

        $suffix = $testmode ? 'test' : 'live';
        $secretKey = (string) ($settings['secret_key_'.$suffix] ?? '');
        $publishableKey = (string) ($settings['publishable_key_'.$suffix] ?? '');

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
        global $wpdb;

        $suffix = $testmode ? 'test' : 'live';

        foreach ([$wpdb->prefix.'wc_stripe_customer_'.$suffix, 'wc_stripe_customer_'.$suffix] as $key) {
            $customerId = get_user_meta($userId, $key, true);
            if (! empty($customerId)) {
                return (string) $customerId;
            }
        }

        return false;
    }
}
