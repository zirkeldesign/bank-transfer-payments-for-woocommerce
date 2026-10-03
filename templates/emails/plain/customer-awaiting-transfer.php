<?php

/**
 * Customer "bank transfer details" email (plain text).
 *
 * @var WC_Order $order
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

echo '= '.esc_html($email_heading)." =\n\n";

printf(
    /* translators: %s: Customer first name. */
    esc_html__('Hi %s,', 'zirkel-iban-for-woocommerce'),
    esc_html($order->get_billing_first_name())
);
echo "\n\n";

esc_html_e('Thanks for your order. Please transfer the amount due using the bank details below — your order is reserved until the money arrives.', 'zirkel-iban-for-woocommerce');
echo "\n\n";

do_action('woocommerce_email_before_order_table', $order, false, true, $email);

echo "\n".esc_html(wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()])))."\n\n";
echo esc_html__('Payment reference:', 'zirkel-iban-for-woocommerce').' '.esc_html($order->get_order_number())."\n\n";

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}

echo esc_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
