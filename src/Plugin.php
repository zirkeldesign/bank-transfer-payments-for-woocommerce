<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce;

use ZirkelDesign\BankTransfersForWooCommerce\Admin\CustomerBalanceDisplay;
use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;
use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BlocksSupport;
use ZirkelDesign\BankTransfersForWooCommerce\Webhook\WebhookHandler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Boots the plugin once WooCommerce is available: registers the gateway, the
 * webhook route, the admin customer-balance display and the custom order
 * status used while a transfer is pending.
 *
 * load_plugin_textdomain() is intentionally NOT called — WordPress 4.6+
 * auto-loads `{textdomain}-{locale}.mo` from the plugin's /languages/ directory.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (! class_exists('WC_Payment_Gateway')) {
            return;
        }

        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = BankTransferGateway::class;

            return $gateways;
        });

        add_action('rest_api_init', static function (): void {
            (new WebhookHandler)->register_routes();
        });

        // Cart/Checkout blocks are the WooCommerce default; without this the
        // gateway is invisible in block-based checkout.
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry): void {
            if (class_exists(BlocksSupport::class)) {
                $registry->register(new BlocksSupport);
            }
        });

        (new CustomerBalanceDisplay)->register();

        self::registerOrderStatus();
    }

    /**
     * Register the custom "Awaiting Bank Transfer" order status.
     */
    private static function registerOrderStatus(): void
    {
        add_action('init', static function (): void {
            register_post_status('wc-awaiting-transfer', [
                'label' => _x('Awaiting Bank Transfer', 'Order status', 'bank-transfer-payments-for-woocommerce'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: Order count. */
                'label_count' => _n_noop('Awaiting bank transfer <span class="count">(%s)</span>', 'Awaiting bank transfer <span class="count">(%s)</span>', 'bank-transfer-payments-for-woocommerce'),
            ]);
        });

        add_filter('wc_order_statuses', static function (array $statuses): array {
            $reordered = [];

            foreach ($statuses as $key => $label) {
                $reordered[$key] = $label;

                if ($key === 'wc-on-hold') {
                    $reordered['wc-awaiting-transfer'] = _x('Awaiting Bank Transfer', 'Order status', 'bank-transfer-payments-for-woocommerce');
                }
            }

            return $reordered;
        });
    }
}
