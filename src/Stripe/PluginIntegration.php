<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe;

use Throwable;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\OfficialStripeGatewayAdapter;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\PaymentPluginsStripeAdapter;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\StripePluginAdapter;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration\WpSwingsStripeAdapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Facade over a registry of {@see StripePluginAdapter}s. Detects an installed
 * third-party Stripe plugin and reuses its stored credentials/customer IDs, so
 * the merchant does not have to enter API keys twice.
 *
 * Register support for another plugin via the `btpw_stripe_plugin_adapters`
 * filter (return an array of StripePluginAdapter instances).
 */
final class PluginIntegration
{
    /**
     * @var array<int, StripePluginAdapter>|null
     */
    private static ?array $adapters = null;

    private static bool $activeResolved = false;

    private static ?StripePluginAdapter $activeAdapter = null;

    /**
     * Credentials cache, keyed by mode ('test'|'live') so a test-mode lookup is
     * never served live keys and vice versa.
     *
     * @var array<string, array<string, string>|false>
     */
    private static array $credentialsByMode = [];

    /**
     * The full, filterable adapter registry.
     *
     * @return array<int, StripePluginAdapter>
     */
    public static function adapters(): array
    {
        if (self::$adapters !== null) {
            return self::$adapters;
        }

        $defaults = [
            new PaymentPluginsStripeAdapter,
            new WpSwingsStripeAdapter,
            new OfficialStripeGatewayAdapter,
        ];

        /**
         * Filter the registered Stripe-plugin adapters. Return an array of
         * {@see StripePluginAdapter} instances.
         *
         * @param  array<int, StripePluginAdapter>  $defaults
         */
        $adapters = apply_filters('btpw_stripe_plugin_adapters', $defaults);

        return self::$adapters = array_values($adapters);
    }

    /**
     * The first active adapter, if any.
     */
    public static function activeAdapter(): ?StripePluginAdapter
    {
        if (self::$activeResolved) {
            return self::$activeAdapter;
        }

        self::$activeResolved = true;
        self::$activeAdapter = null;

        foreach (self::adapters() as $adapter) {
            if ($adapter->isActive()) {
                self::$activeAdapter = $adapter;
                break;
            }
        }

        return self::$activeAdapter;
    }

    /**
     * Identifier of the detected plugin, or false when none is active.
     */
    public static function detectStripePlugin(): string|false
    {
        return self::activeAdapter()?->id() ?? false;
    }

    /**
     * @return array<string, string>|false
     */
    public static function getStripeCredentials(bool $testmode = false): array|false
    {
        $mode = $testmode ? 'test' : 'live';

        if (array_key_exists($mode, self::$credentialsByMode)) {
            return self::$credentialsByMode[$mode];
        }

        $adapter = self::activeAdapter();
        $credentials = $adapter?->getCredentials($testmode) ?? false;

        /**
         * Filter the resolved Stripe credentials.
         *
         * @param  array<string, string>|false  $credentials
         * @param  bool  $testmode
         * @param  string|false  $plugin  Detected plugin identifier.
         */
        $credentials = apply_filters('btpw_stripe_credentials', $credentials, $testmode, $adapter?->id() ?? false);

        return self::$credentialsByMode[$mode] = $credentials;
    }

    /**
     * Build a Stripe client from the detected plugin's credentials.
     */
    public static function getStripeClient(bool $testmode = false): object|false
    {
        $credentials = self::getStripeCredentials($testmode);

        if ($credentials === false || empty($credentials['secret_key'])) {
            return false;
        }

        try {
            return ClientFactory::make($credentials['secret_key']) ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the gateway should fall back to a detected plugin's credentials.
     *
     * The gateway exposes `testmode`, `test_secret_key` and `live_secret_key`.
     */
    public static function shouldUseExistingCredentials(object $gateway): bool
    {
        if (! empty($gateway->testmode) && ! empty($gateway->test_secret_key)) {
            return false;
        }

        if (empty($gateway->testmode) && ! empty($gateway->live_secret_key)) {
            return false;
        }

        return self::activeAdapter() !== null;
    }

    /**
     * Admin notice HTML when reusing a detected plugin's credentials.
     */
    public static function getIntegrationNotice(): string|false
    {
        $adapter = self::activeAdapter();

        if ($adapter === null || self::getStripeCredentials() === false) {
            return false;
        }

        $message = sprintf(
            /* translators: %s: Detected Stripe plugin name. */
            esc_html__('Detected %s plugin. Using Stripe credentials from that plugin.', 'zirkel-iban-for-woocommerce'),
            '<strong>'.esc_html($adapter->label()).'</strong>'
        );

        return '<div class="notice notice-info inline"><p>'.$message.'</p></div>';
    }

    public static function clearCache(): void
    {
        self::$adapters = null;
        self::$activeResolved = false;
        self::$activeAdapter = null;
        self::$credentialsByMode = [];
    }

    /**
     * Resolve the Stripe customer ID for a user across adapter, generic meta
     * and order history.
     */
    public static function getStripeCustomerId(int $userId, bool $testmode = false): string|false
    {
        $adapter = self::activeAdapter();
        $customerId = $adapter?->getCustomerId($userId, $testmode) ?? false;

        if ($customerId === false) {
            $generic = get_user_meta($userId, '_stripe_customer_id', true);
            $customerId = ! empty($generic) ? (string) $generic : self::getCustomerIdFromOrders($userId);
        }

        /**
         * Filter the resolved Stripe customer ID.
         *
         * @param  string|false  $customerId
         * @param  int  $userId
         * @param  bool  $testmode
         * @param  string|false  $plugin  Detected plugin identifier.
         */
        return apply_filters('btpw_stripe_customer_id', $customerId, $userId, $testmode, $adapter?->id() ?? false);
    }

    /**
     * Scan a user's orders for a stored Stripe customer ID.
     */
    private static function getCustomerIdFromOrders(int $userId): string|false
    {
        if (! function_exists('wc_get_orders')) {
            return false;
        }

        $orders = wc_get_orders([
            'customer_id' => $userId,
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        foreach ($orders as $order) {
            $customerId = $order->get_meta('_stripe_customer_id');
            if (! empty($customerId)) {
                update_user_meta($userId, '_stripe_customer_id', $customerId);

                return (string) $customerId;
            }
        }

        return false;
    }
}
