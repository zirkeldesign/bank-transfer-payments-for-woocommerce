<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers the gateway with the WooCommerce Cart/Checkout blocks.
 *
 * The classic gateway class alone is invisible in block-based checkout, which
 * is the WooCommerce default — without this the payment method simply never
 * appears for most modern stores.
 */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /**
     * Payment method slug, matched against the classic gateway id.
     */
    protected $name = BankTransferGateway::GATEWAY_ID;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_'.BankTransferGateway::GATEWAY_ID.'_settings', []);
    }

    public function is_active(): bool
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes';
    }

    /**
     * Register and return the script handles backing the block integration.
     *
     * @return array<int, string>
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'btpw-blocks-checkout';

        wp_register_script(
            $handle,
            \BTPW_URL.'assets/js/blocks-checkout.js',
            ['wc-blocks-registry', 'wp-element', 'wp-i18n', 'wc-settings'],
            \BTPW_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'bank-transfer-payments-for-woocommerce');
        }

        return [$handle];
    }

    /**
     * Data handed to the block script.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->settings['title'] ?? __('Bank Transfer', 'bank-transfer-payments-for-woocommerce'),
            'description' => $this->settings['description'] ?? '',
            'notice' => __('After placing your order, you will receive unique bank account details to complete your payment.', 'bank-transfer-payments-for-woocommerce'),
            'supports' => ['products'],
        ];
    }
}
