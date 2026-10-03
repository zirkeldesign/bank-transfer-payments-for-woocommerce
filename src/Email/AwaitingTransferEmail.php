<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Email;

use WC_Email;
use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * "Bank transfer details" email, sent when an order starts waiting for payment.
 *
 * WooCommerce only ships transactional emails for its own order statuses, so a
 * custom status sends nothing at all. Without this the customer's only copy of
 * the IBAN is the thank-you page — lose that tab and the order cannot be paid.
 *
 * Registering a real WC_Email (rather than calling wp_mail directly) means
 * merchants get the usual controls: enable/disable, subject and heading, and
 * template overrides from their theme.
 */
final class AwaitingTransferEmail extends WC_Email
{
    public function __construct()
    {
        $this->id = 'btpw_awaiting_transfer';
        $this->customer_email = true;
        $this->title = __('Bank transfer details', 'zirkel-iban-for-woocommerce');
        $this->description = __('Sent to the customer when an order is waiting for a bank transfer, containing the account details to pay into.', 'zirkel-iban-for-woocommerce');

        $this->template_html = 'emails/customer-awaiting-transfer.php';
        $this->template_plain = 'emails/plain/customer-awaiting-transfer.php';
        $this->template_base = \BTPW_DIR.'templates/';

        $this->placeholders = [
            '{order_number}' => '',
            '{order_date}' => '',
        ];

        // WooCommerce only fires *_notification actions for its own statuses,
        // so trigger from the status transition itself.
        add_action('woocommerce_order_status_awaiting-transfer', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('Your order {order_number} — bank transfer details', 'zirkel-iban-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Please transfer the amount due', 'zirkel-iban-for-woocommerce');
    }

    /**
     * @param  int  $order_id
     * @param  WC_Order|null  $order
     */
    public function trigger($order_id, $order = null): void
    {
        $this->setup_locale();

        if (! $order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (! $order instanceof WC_Order) {
            $this->restore_locale();

            return;
        }

        // Stripe retries and manual status changes can re-enter this; the
        // customer should not be emailed the same details repeatedly.
        if ($order->get_meta('_btpw_details_email_sent') === 'yes') {
            $this->restore_locale();

            return;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();

        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());

            $order->update_meta_data('_btpw_details_email_sent', 'yes');
            $order->save();
        }

        $this->restore_locale();
    }

    public function get_content_html(): string
    {
        return wc_get_template_html($this->template_html, [
            'order' => $this->object,
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => false,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function get_content_plain(): string
    {
        return wc_get_template_html($this->template_plain, [
            'order' => $this->object,
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => true,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function get_default_additional_content(): string
    {
        return __('Your order ships as soon as the transfer arrives. Bank transfers usually take one to three business days.', 'zirkel-iban-for-woocommerce');
    }
}
