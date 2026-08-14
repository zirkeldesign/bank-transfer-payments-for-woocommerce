<?php
/**
 * Customer "bank transfer details" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/customer-awaiting-transfer.php
 *
 * @var WC_Order $order
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php printf(
    /* translators: %s: Customer first name. */
    esc_html__('Hi %s,', 'bank-transfer-payments-for-woocommerce'),
    esc_html($order->get_billing_first_name())
); ?></p>

<p><?php esc_html_e('Thanks for your order. Please transfer the amount due using the bank details below — your order is reserved until the money arrives.', 'bank-transfer-payments-for-woocommerce'); ?></p>

<?php
/*
 * The gateway hooks this to print the virtual bank account, which is the whole
 * point of the email.
 */
do_action('woocommerce_email_before_order_table', $order, false, false, $email);

do_action('woocommerce_email_order_details', $order, false, false, $email);
do_action('woocommerce_email_order_meta', $order, false, false, $email);
do_action('woocommerce_email_customer_details', $order, false, false, $email);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
