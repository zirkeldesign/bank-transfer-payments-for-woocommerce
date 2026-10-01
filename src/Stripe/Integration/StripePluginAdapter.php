<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe\Integration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adapter that knows how a specific third-party Stripe plugin stores its API
 * credentials and customer IDs, so this gateway can reuse them.
 *
 * Add support for another Stripe plugin by implementing this interface and
 * registering it via the `btpw_stripe_plugin_adapters` filter.
 */
interface StripePluginAdapter
{
    /**
     * Stable identifier for the adapter (used in filters/logging).
     */
    public function id(): string;

    /**
     * Human-readable plugin name, shown in the admin integration notice.
     */
    public function label(): string;

    /**
     * Whether the target plugin is active in this installation.
     */
    public function isActive(): bool;

    /**
     * Resolve the plugin's stored credentials for the requested mode.
     *
     * @return array{secret_key: string, publishable_key: string, source: string}|false
     */
    public function getCredentials(bool $testmode): array|false;

    /**
     * Resolve the plugin's stored Stripe customer ID for a user, if any.
     */
    public function getCustomerId(int $userId, bool $testmode): string|false;
}
