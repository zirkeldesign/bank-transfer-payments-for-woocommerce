<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Capability gate for the freemium split.
 *
 * The free core is deliberately complete: it processes real, live payments and
 * reconciles them automatically. Pro adds operational tooling on top (dunning,
 * reconciliation reporting, exports) — never a switch that turns the core job
 * on or off.
 *
 * The tier map below is the single source of truth for the split. Moving a
 * feature between tiers is a one-line change here; no call site changes.
 */
final class Features
{
    // Free core.
    public const GATEWAY = 'gateway';

    public const RECONCILIATION = 'reconciliation';

    public const GIROCODE = 'girocode';

    public const REFUNDS = 'refunds';

    // Pro.
    public const SUBSCRIPTIONS = 'subscriptions';

    public const DUNNING = 'dunning';

    public const RECONCILIATION_DASHBOARD = 'reconciliation_dashboard';

    public const BALANCE_MANAGER = 'balance_manager';

    public const EXPORT = 'export';

    /**
     * Features included in the free core.
     *
     * @var array<int, string>
     */
    private const FREE = [
        self::GATEWAY,
        self::RECONCILIATION,
        self::GIROCODE,
        self::REFUNDS,
    ];

    /**
     * Whether a feature is available in this installation.
     *
     * Free-core features are always available. Pro features are off unless the
     * Pro add-on answers the `btpw_has_feature` filter.
     */
    public static function has(string $feature): bool
    {
        if (in_array($feature, self::FREE, true)) {
            return true;
        }

        /**
         * Filter whether a Pro feature is unlocked.
         *
         * The Pro add-on hooks this and returns true for the features its
         * license covers.
         *
         * @param  bool  $enabled  Whether the feature is unlocked.
         * @param  string  $feature  Feature identifier.
         */
        return (bool) apply_filters('btpw_has_feature', false, $feature);
    }

    /**
     * Whether the Pro add-on is active at all (used for upsell messaging).
     */
    public static function isProActive(): bool
    {
        /**
         * Filter whether the Pro add-on is active.
         *
         * @param  bool  $active
         */
        return (bool) apply_filters('btpw_pro_active', false);
    }

    /**
     * Marketing URL for the Pro upgrade, filterable for affiliate/campaign use.
     */
    public static function upgradeUrl(): string
    {
        return (string) apply_filters(
            'btpw_upgrade_url',
            'https://zirkel.design/bank-transfer-payments-for-woocommerce/'
        );
    }

    /**
     * A short, non-nagging upsell line for a gated setting's description.
     */
    public static function upsell(string $message): string
    {
        if (self::isProActive()) {
            return $message;
        }

        return $message.' '.sprintf(
            /* translators: %s: Link to the Pro upgrade page. */
            __('Available in %s.', 'bank-transfer-payments-for-woocommerce'),
            '<a href="'.esc_url(self::upgradeUrl()).'" target="_blank" rel="noopener">'
                .esc_html__('Pro', 'bank-transfer-payments-for-woocommerce').'</a>'
        );
    }
}
