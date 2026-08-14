<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Gateway;

use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;
use ZirkelDesign\BankTransfersForWooCommerce\Payment\GiroCode;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\ClientFactory;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\PluginIntegration;
use ZirkelDesign\BankTransfersForWooCommerce\Subscriptions\SubscriptionSupport;
use ZirkelDesign\BankTransfersForWooCommerce\Support\Features;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bank-transfer payment gateway backed by Stripe's customer_balance funding
 * flow. Each order receives its own virtual bank-account details, and webhooks
 * reconcile the transfer back to the order automatically.
 */
final class BankTransferGateway extends WC_Payment_Gateway
{
    public const GATEWAY_ID = 'stripe_bank_transfer';

    /**
     * Legacy setting values that were never valid Stripe bank-transfer types.
     *
     * Early versions stored "<cc>_bank_account" and sent it as both
     * `funding_type` and `bank_transfer.type`. Stripe expects the literal
     * `bank_transfer` for the former and `<cc>_bank_transfer` for the latter,
     * so stored settings are translated on read.
     *
     * @var array<string, string>
     */
    private const LEGACY_TRANSFER_TYPES = [
        'eu_bank_account' => 'eu_bank_transfer',
        'gb_bank_account' => 'gb_bank_transfer',
        'us_bank_account' => 'us_bank_transfer',
        'jp_bank_account' => 'jp_bank_transfer',
        'mx_bank_account' => 'mx_bank_transfer',
    ];

    public bool $testmode = false;

    public string $test_secret_key = '';

    public string $live_secret_key = '';

    public string $transfer_type = 'eu_bank_transfer';

    public string $default_currency = 'eur';

    public bool $debug_mode = false;

    public bool $enable_subscriptions = false;

    public string $order_status_awaiting = 'awaiting-transfer';

    public string $secret_key = '';

    /**
     * Stripe SDK client.
     *
     * @var StripeClient|object|null
     */
    private ?object $stripe = null;

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->icon = '';
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];
        $this->method_title = __('Bank Transfer Payments', 'bank-transfer-payments-for-woocommerce');
        $this->method_description = __('Accept bank transfer payments via Stripe. Customers receive individual bank account details for each order.', 'bank-transfer-payments-for-woocommerce');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = $this->get_option('testmode') === 'yes';
        $this->test_secret_key = (string) $this->get_option('test_secret_key');
        $this->live_secret_key = (string) $this->get_option('live_secret_key');
        $this->transfer_type = self::normaliseTransferType((string) $this->get_option('transfer_type', 'eu_bank_transfer'));
        $this->default_currency = (string) $this->get_option('default_currency', 'eur');
        $this->debug_mode = $this->get_option('debug_mode') === 'yes';
        $this->enable_subscriptions = $this->get_option('enable_subscriptions') === 'yes';
        $this->order_status_awaiting = (string) $this->get_option('order_status_awaiting', 'awaiting-transfer');

        $this->secret_key = $this->resolve_secret_key();

        if ($this->secret_key !== '') {
            $this->stripe = ClientFactory::make($this->secret_key);
        }

        // Manual-renewal subscription support, opt-in and only when WooCommerce
        // Subscriptions is active. Bank transfers cannot be auto-charged, so
        // each renewal issues a fresh virtual account the customer pays.
        if ($this->enable_subscriptions && class_exists('WC_Subscriptions') && Features::has(Features::SUBSCRIPTIONS)) {
            SubscriptionSupport::attach($this);
        }

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, function (): void {
            $this->process_admin_options();
        });
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'clear_integration_cache']);
        add_action('woocommerce_thankyou_'.$this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * Enqueue the customer-facing instruction styles on checkout / thank-you.
     */
    public function enqueue_frontend_assets(): void
    {
        if (! function_exists('is_checkout') || (! is_checkout() && ! is_wc_endpoint_url('order-received'))) {
            return;
        }

        wp_enqueue_style(
            'btpw-frontend-bank-transfer',
            \BTPW_URL.'assets/css/frontend-bank-transfer.css',
            [],
            \BTPW_VERSION
        );
    }

    /**
     * Resolve the effective secret key, preferring a detected Stripe plugin.
     */
    private function resolve_secret_key(): string
    {
        if (PluginIntegration::shouldUseExistingCredentials($this)) {
            $credentials = PluginIntegration::getStripeCredentials($this->testmode);

            if ($credentials !== false && ! empty($credentials['secret_key'])) {
                $this->log('Using Stripe credentials from: '.$credentials['source']);

                return (string) $credentials['secret_key'];
            }
        }

        return $this->testmode ? $this->test_secret_key : $this->live_secret_key;
    }

    public function admin_notices(): void
    {
        // WordPress admin screen params; read-only, no state change.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($page !== 'wc-settings' || $tab !== 'checkout' || $section !== $this->id) {
            return;
        }

        $notice = PluginIntegration::getIntegrationNotice();
        if ($notice !== false) {
            echo wp_kses_post($notice);
        }
    }

    public function clear_integration_cache(): void
    {
        PluginIntegration::clearCache();
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'bank-transfer-payments-for-woocommerce'),
                'label' => __('Enable Bank Transfer Payments', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'bank-transfer-payments-for-woocommerce'),
                'default' => __('Bank Transfer', 'bank-transfer-payments-for-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'bank-transfer-payments-for-woocommerce'),
                'default' => __('Pay securely using bank transfer. You will receive unique bank account details after placing your order.', 'bank-transfer-payments-for-woocommerce'),
                'desc_tip' => true,
            ],
            'testmode' => [
                'title' => __('Test mode', 'bank-transfer-payments-for-woocommerce'),
                'label' => __('Enable Test Mode', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'bank-transfer-payments-for-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ],
            'test_secret_key' => [
                'title' => __('Test Secret Key', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Stripe account.', 'bank-transfer-payments-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'live_secret_key' => [
                'title' => __('Live Secret Key', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Stripe account.', 'bank-transfer-payments-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'webhook_secret' => [
                'title' => __('Webhook Secret', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: Webhook URL */
                    __('Enter your webhook signing secret from the Stripe Dashboard. Webhook URL: %s', 'bank-transfer-payments-for-woocommerce'),
                    '<code>'.esc_url(rest_url('bank-transfer-payments-for-woocommerce/v1/webhook')).'</code>'
                ),
                'default' => '',
                'desc_tip' => false,
            ],
            'transfer_type' => [
                'title' => __('Bank Transfer Type', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'select',
                'description' => __('Select the type of bank transfer to accept.', 'bank-transfer-payments-for-woocommerce'),
                'default' => 'eu_bank_transfer',
                'desc_tip' => true,
                'options' => [
                    'eu_bank_transfer' => __('EU Bank Account (SEPA)', 'bank-transfer-payments-for-woocommerce'),
                    'gb_bank_transfer' => __('UK Bank Account (Bacs)', 'bank-transfer-payments-for-woocommerce'),
                    'us_bank_transfer' => __('US Bank Account (ACH)', 'bank-transfer-payments-for-woocommerce'),
                    'jp_bank_transfer' => __('Japanese Bank Account', 'bank-transfer-payments-for-woocommerce'),
                    'mx_bank_transfer' => __('Mexican Bank Account (SPEI)', 'bank-transfer-payments-for-woocommerce'),
                ],
            ],
            'default_currency' => [
                'title' => __('Default Currency for Virtual Bank Accounts', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'select',
                'description' => __('Currency used when creating virtual bank account details for customers.', 'bank-transfer-payments-for-woocommerce'),
                'default' => 'eur',
                'desc_tip' => true,
                'options' => [
                    'usd' => __('US Dollar (USD)', 'bank-transfer-payments-for-woocommerce'),
                    'eur' => __('Euro (EUR)', 'bank-transfer-payments-for-woocommerce'),
                    'gbp' => __('British Pound (GBP)', 'bank-transfer-payments-for-woocommerce'),
                    'jpy' => __('Japanese Yen (JPY)', 'bank-transfer-payments-for-woocommerce'),
                    'mxn' => __('Mexican Peso (MXN)', 'bank-transfer-payments-for-woocommerce'),
                ],
            ],
            'order_status_awaiting' => [
                'title' => __('Awaiting-payment order status', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'select',
                'description' => __('Status to set on the order while waiting for the customer to complete the bank transfer.', 'bank-transfer-payments-for-woocommerce'),
                'default' => 'awaiting-transfer',
                'desc_tip' => true,
                'options' => [
                    'awaiting-transfer' => __('Awaiting Bank Transfer', 'bank-transfer-payments-for-woocommerce'),
                    'on-hold' => __('On hold', 'bank-transfer-payments-for-woocommerce'),
                    'pending' => __('Pending payment', 'bank-transfer-payments-for-woocommerce'),
                ],
            ],
            'debug_mode' => [
                'title' => __('Debug Mode', 'bank-transfer-payments-for-woocommerce'),
                'label' => __('Enable logging', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Log events to WooCommerce logs for debugging.', 'bank-transfer-payments-for-woocommerce'),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'enable_subscriptions' => [
                'title' => __('Subscriptions', 'bank-transfer-payments-for-woocommerce'),
                'label' => __('Allow bank transfer for subscriptions (manual renewals)', 'bank-transfer-payments-for-woocommerce'),
                'type' => 'checkbox',
                'description' => Features::upsell(__('Requires the WooCommerce Subscriptions extension. Bank transfers cannot be charged automatically, so each renewal issues a new virtual bank account that the customer pays manually.', 'bank-transfer-payments-for-woocommerce')),
                'default' => 'no',
                'desc_tip' => false,
            ],
        ];
    }

    /**
     * The smallest amount Stripe will accept for a bank transfer.
     *
     * Stripe's floor is 0.50 EUR (or equivalent). Offering the method below
     * that would let the customer reach the end of checkout only to be refused
     * by the API, so it is hidden instead.
     */
    public function minimum_amount(): float
    {
        /**
         * Filter the minimum order total for bank transfer.
         *
         * @param  float  $minimum  In the store's currency.
         * @param  self  $gateway
         */
        return (float) apply_filters('btpw_minimum_amount', 0.50, $this);
    }

    /**
     * Hide the gateway when the cart total is below Stripe's minimum.
     */
    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }

        if (! function_exists('WC') || WC()->cart === null) {
            return true; // Admin or REST context: nothing to measure.
        }

        $total = (float) WC()->cart->get_total('edit');

        // A zero total means the cart is not yet calculated, not that it is free.
        return $total <= 0 || $total >= $this->minimum_amount();
    }

    public function payment_fields(): void
    {
        if ($this->description) {
            echo wp_kses_post(wpautop($this->description));
        }

        echo '<div class="btpw-bank-transfer-info">';
        echo '<p>'.esc_html__('After placing your order, you will receive unique bank account details to complete your payment.', 'bank-transfer-payments-for-woocommerce').'</p>';
        echo '</div>';
    }

    /**
     * Build the Stripe PaymentIntent payload for an order.
     *
     * Pure and side-effect free so it can be unit-tested without the SDK.
     *
     * @return array<string, mixed>
     */
    public function build_payment_intent_data(WC_Order $order, string $customerId = ''): array
    {
        $orderId = $order->get_id();

        /**
         * Filter the PaymentIntent metadata.
         *
         * @param  array<string, mixed>  $metadata
         * @param  WC_Order  $order
         * @param  self  $gateway
         */
        $metadata = apply_filters('btpw_payment_intent_metadata', [
            'order_id' => $orderId,
            'customer_email' => $order->get_billing_email(),
            'customer_name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
        ], $order, $this);

        // Stripe requires the literal `bank_transfer` funding type; the country
        // scheme goes on bank_transfer.type. EU additionally needs the country
        // that determines which localised IBAN the customer is shown.
        $bankTransfer = ['type' => $this->transfer_type];

        if ($this->transfer_type === 'eu_bank_transfer') {
            $bankTransfer['eu_bank_transfer'] = ['country' => $this->euBankTransferCountry($order)];
        }

        $data = [
            'amount' => $this->get_order_total_in_cents($order),
            'currency' => strtolower($order->get_currency()),
            'payment_method_types' => ['customer_balance'],
            'payment_method_data' => [
                'type' => 'customer_balance',
            ],
            'payment_method_options' => [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => $bankTransfer,
                ],
            ],
            // Bank transfers are only assigned a virtual account once the
            // intent is confirmed; without this there is no next_action and
            // therefore no bank details to show the customer.
            'confirm' => true,
            'metadata' => $metadata,
            /* translators: 1: Order number, 2: Site name */
            'description' => sprintf(__('Order %1$s from %2$s', 'bank-transfer-payments-for-woocommerce'), $order->get_order_number(), get_bloginfo('name')),
        ];

        // A cash balance belongs to a Stripe customer, so customer_balance
        // payments cannot be created without one.
        if ($customerId !== '') {
            $data['customer'] = $customerId;
        }

        /**
         * Filter the full PaymentIntent payload before it is sent to Stripe.
         *
         * @param  array<string, mixed>  $data
         * @param  WC_Order  $order
         * @param  self  $gateway
         */
        return apply_filters('btpw_payment_intent_data', $data, $order, $this);
    }

    /**
     * Translate a stored setting value into the Stripe bank-transfer type.
     */
    public static function normaliseTransferType(string $stored): string
    {
        return self::LEGACY_TRANSFER_TYPES[$stored] ?? $stored;
    }

    /**
     * The country whose localised IBAN the customer should be shown.
     *
     * Stripe requires this for eu_bank_transfer. Prefer the store's own
     * country; fall back to Germany, the primary market for this plugin.
     * Stripe cannot currently issue localised Spanish accounts, so ES falls
     * back too.
     */
    private function euBankTransferCountry(WC_Order $order): string
    {
        $country = '';

        if (function_exists('wc_get_base_location')) {
            $country = (string) (wc_get_base_location()['country'] ?? '');
        }

        $supported = ['DE', 'FR', 'IE', 'NL', 'BE'];

        if (! in_array($country, $supported, true)) {
            $country = 'DE';
        }

        /**
         * Filter the country used for the customer's localised EU IBAN.
         *
         * @param  string  $country  Two-letter country code.
         * @param  WC_Order  $order
         */
        return (string) apply_filters('btpw_eu_bank_transfer_country', $country, $order);
    }

    /**
     * Find or create the Stripe customer this order's cash balance belongs to.
     *
     * Reuses an id already stored by another Stripe plugin (via the adapter
     * registry) or by a previous order, so we do not litter the Stripe account
     * with duplicates.
     *
     * @throws RuntimeException When no customer can be resolved.
     */
    public function resolve_stripe_customer(WC_Order $order): string
    {
        $stored = (string) $order->get_meta('_stripe_customer_id');

        if ($stored !== '') {
            return $stored;
        }

        $userId = (int) $order->get_customer_id();

        if ($userId > 0) {
            $existing = PluginIntegration::getStripeCustomerId($userId, $this->testmode);

            if ($existing !== false && $existing !== '') {
                $order->update_meta_data('_stripe_customer_id', $existing);

                return $existing;
            }
        }

        if ($this->stripe === null) {
            throw new RuntimeException('Stripe client is not configured.');
        }

        /** @var StripeClient $stripe */
        $stripe = $this->stripe;
        $customer = $stripe->customers->create([
            'email' => $order->get_billing_email(),
            'name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
            'metadata' => ['order_id' => (string) $order->get_id()],
        ]);

        $order->update_meta_data('_stripe_customer_id', $customer->id);

        if ($userId > 0) {
            update_user_meta($userId, '_stripe_customer_id', $customer->id);
        }

        return (string) $customer->id;
    }

    /**
     * Create the Stripe PaymentIntent for an order, store its virtual bank
     * account details, and move the order to "awaiting transfer".
     *
     * Shared by checkout ({@see process_payment()}) and subscription renewals.
     * The order is left awaiting the transfer; the webhook completes it once
     * the funds arrive.
     *
     * @return object The Stripe PaymentIntent.
     *
     * @throws RuntimeException When the Stripe client is not configured.
     */
    public function create_bank_transfer_for_order(WC_Order $order): object
    {
        if ($this->stripe === null) {
            throw new RuntimeException('Stripe client is not configured.');
        }

        $orderId = $order->get_id();
        $paymentIntentData = $this->build_payment_intent_data($order, $this->resolve_stripe_customer($order));

        $this->log('Creating PaymentIntent for order #'.$orderId);

        /** @var StripeClient $stripe */
        $stripe = $this->stripe;
        $paymentIntent = $stripe->paymentIntents->create($paymentIntentData);

        /**
         * Fires after the PaymentIntent has been created.
         *
         * @param  object  $paymentIntent
         * @param  WC_Order  $order
         * @param  self  $gateway
         */
        do_action('btpw_payment_intent_created', $paymentIntent, $order, $this);

        $order->update_meta_data('_stripe_payment_intent_id', $paymentIntent->id);
        $order->update_meta_data('_stripe_payment_intent_status', $paymentIntent->status);

        if (isset($paymentIntent->next_action->display_bank_transfer_instructions)) {
            $order->update_meta_data(
                '_stripe_bank_transfer_details',
                wp_json_encode($paymentIntent->next_action->display_bank_transfer_instructions)
            );
            $this->log('Bank transfer details stored for order #'.$orderId);
        }

        $order->save();

        /**
         * Filter the order status set after PaymentIntent creation.
         *
         * @param  string  $status  Status slug including the `wc-` prefix.
         * @param  WC_Order  $order
         * @param  object  $paymentIntent
         */
        $orderStatus = apply_filters('btpw_order_status', 'wc-'.$this->order_status_awaiting, $order, $paymentIntent);

        $order->update_status($orderStatus, __('Awaiting bank transfer payment.', 'bank-transfer-payments-for-woocommerce'));

        return $paymentIntent;
    }

    /**
     * Process an order payment.
     *
     * @return array<string, string>
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            wc_add_notice(__('Order could not be loaded. Please try again.', 'bank-transfer-payments-for-woocommerce'), 'error');

            return ['result' => 'fail'];
        }

        if ($this->stripe === null) {
            wc_add_notice(__('Payment gateway configuration error. Please contact support.', 'bank-transfer-payments-for-woocommerce'), 'error');

            return ['result' => 'fail'];
        }

        try {
            $paymentIntent = $this->create_bank_transfer_for_order($order);

            wc_reduce_stock_levels($order_id);

            if (WC()->cart !== null) {
                WC()->cart->empty_cart();
            }

            $this->log('Payment initiated successfully for order #'.$order_id);

            /**
             * Fires after the payment has been initiated successfully.
             *
             * @param  int  $order_id
             * @param  WC_Order  $order
             * @param  object  $paymentIntent
             */
            do_action('btpw_payment_processed', $order_id, $order, $paymentIntent);

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (ApiErrorException $e) {
            $this->log('Stripe API Error: '.$e->getMessage(), 'error');
            wc_add_notice(__('Payment failed. Please try again or contact us.', 'bank-transfer-payments-for-woocommerce'), 'error');

            return ['result' => 'fail'];
        } catch (Throwable $e) {
            $this->log('Error processing payment: '.$e->getMessage(), 'error');
            wc_add_notice(__('An error occurred while processing your payment. Please try again.', 'bank-transfer-payments-for-woocommerce'), 'error');

            return ['result' => 'fail'];
        }
    }

    /**
     * Refund a bank-transfer payment through Stripe.
     *
     * Stripe returns the funds to the customer's bank account (or their cash
     * balance when no account details are known). Supports partial amounts.
     *
     * @param  int  $order_id
     * @param  float|null  $amount
     * @param  string  $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            return new WP_Error('btpw_refund_error', __('Order could not be loaded.', 'bank-transfer-payments-for-woocommerce'));
        }

        if ($this->stripe === null) {
            return new WP_Error('btpw_refund_error', __('Stripe is not configured.', 'bank-transfer-payments-for-woocommerce'));
        }

        $paymentIntentId = (string) $order->get_meta('_stripe_payment_intent_id');

        if ($paymentIntentId === '') {
            return new WP_Error('btpw_refund_error', __('No Stripe payment found for this order.', 'bank-transfer-payments-for-woocommerce'));
        }

        try {
            $payload = ['payment_intent' => $paymentIntentId];

            if ($amount !== null) {
                $payload['amount'] = absint(wc_format_decimal((string) ((float) $amount * 100), 0));
            }

            if ($reason !== '') {
                $payload['metadata'] = ['reason' => $reason];
            }

            /** @var StripeClient $stripe */
            $stripe = $this->stripe;
            $refund = $stripe->refunds->create($payload);

            $order->add_order_note(sprintf(
                /* translators: 1: Refunded amount, 2: Stripe refund ID */
                __('Refunded %1$s via Stripe bank transfer. Refund ID: %2$s', 'bank-transfer-payments-for-woocommerce'),
                $amount !== null ? (string) $amount : __('full amount', 'bank-transfer-payments-for-woocommerce'),
                (string) ($refund->id ?? '')
            ));

            $this->log('Refund created for order #'.$order->get_id());

            return true;
        } catch (Throwable $e) {
            $this->log('Refund failed for order #'.$order->get_id().': '.$e->getMessage(), 'error');

            return new WP_Error('btpw_refund_error', $e->getMessage());
        }
    }

    public function thankyou_page(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            return;
        }

        $bankDetailsJson = $order->get_meta('_stripe_bank_transfer_details');

        if (! empty($bankDetailsJson)) {
            $bankDetails = json_decode($bankDetailsJson, true);
            if (is_array($bankDetails)) {
                $this->display_bank_transfer_instructions($bankDetails, $order);
            }
        }
    }

    /**
     * Render bank-transfer instructions for the customer.
     *
     * @param  array<string, mixed>  $bankDetails
     */
    private function display_bank_transfer_instructions(array $bankDetails, WC_Order $order, bool $forEmail = false): void
    {
        if (! isset($bankDetails['financial_addresses']) || ! is_array($bankDetails['financial_addresses'])) {
            return;
        }

        echo '<section class="btpw-bank-transfer-details">';
        echo '<h2>'.esc_html__('Bank Transfer Instructions', 'bank-transfer-payments-for-woocommerce').'</h2>';
        echo '<p>'.esc_html__('Please transfer the funds to the following bank account:', 'bank-transfer-payments-for-woocommerce').'</p>';

        $sepaAddress = null;

        foreach ($bankDetails['financial_addresses'] as $address) {
            $type = $address['type'] ?? '';

            echo '<div class="btpw-bank-details-box">';

            if ($type === 'ach') {
                echo '<h3>'.esc_html__('US Bank Account (ACH)', 'bank-transfer-payments-for-woocommerce').'</h3>';
                echo '<p><strong>'.esc_html__('Account Number:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['ach']['account_number'] ?? '').'</p>';
                echo '<p><strong>'.esc_html__('Routing Number:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['ach']['routing_number'] ?? '').'</p>';
            } elseif ($type === 'sepa') {
                $sepaAddress = is_array($address['sepa'] ?? null) ? $address['sepa'] : null;
                echo '<h3>'.esc_html__('EU Bank Account (SEPA)', 'bank-transfer-payments-for-woocommerce').'</h3>';
                echo '<p><strong>'.esc_html__('IBAN:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['sepa']['iban'] ?? '').'</p>';
                echo '<p><strong>'.esc_html__('BIC:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['sepa']['bic'] ?? '').'</p>';
            } elseif ($type === 'sort_code') {
                echo '<h3>'.esc_html__('UK Bank Account (Bacs)', 'bank-transfer-payments-for-woocommerce').'</h3>';
                echo '<p><strong>'.esc_html__('Account Number:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['sort_code']['account_number'] ?? '').'</p>';
                echo '<p><strong>'.esc_html__('Sort Code:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['sort_code']['sort_code'] ?? '').'</p>';
            } elseif ($type === 'spei') {
                echo '<h3>'.esc_html__('Mexican Bank Account (SPEI)', 'bank-transfer-payments-for-woocommerce').'</h3>';
                echo '<p><strong>'.esc_html__('CLABE:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($address['spei']['clabe'] ?? '').'</p>';
            }

            echo '</div>';
        }

        // GiroCode (EPC069-12) for SEPA/EUR on the thank-you page. JS-rendered,
        // so it is skipped in emails, where no script runs.
        if (! $forEmail && $sepaAddress !== null) {
            $this->render_girocode($sepaAddress, $order);
        }

        echo '<div class="btpw-payment-details">';
        echo '<p><strong>'.esc_html__('Amount:', 'bank-transfer-payments-for-woocommerce').'</strong> '.wp_kses_post(wc_price($order->get_total(), ['currency' => $order->get_currency()])).'</p>';
        echo '<p><strong>'.esc_html__('Order Number:', 'bank-transfer-payments-for-woocommerce').'</strong> '.esc_html($order->get_order_number()).'</p>';

        if (isset($bankDetails['hosted_instructions_url'])) {
            echo '<p><a href="'.esc_url($bankDetails['hosted_instructions_url']).'" class="button" target="_blank" rel="noopener">'.esc_html__('View Full Instructions', 'bank-transfer-payments-for-woocommerce').'</a></p>';
        }
        echo '</div>';

        echo '<p class="btpw-important-notice">'.esc_html__('Important: Please include your order number as the payment reference.', 'bank-transfer-payments-for-woocommerce').'</p>';
        echo '</section>';
    }

    /**
     * Render a scannable GiroCode (EPC069-12) for a SEPA/EUR transfer.
     *
     * @param  array<string, mixed>  $sepa  The SEPA financial address.
     */
    private function render_girocode(array $sepa, WC_Order $order): void
    {
        if (strtoupper($order->get_currency()) !== 'EUR') {
            return;
        }

        $payload = GiroCode::payload(
            (string) ($sepa['account_holder_name'] ?? get_bloginfo('name')),
            (string) ($sepa['iban'] ?? ''),
            (string) ($sepa['bic'] ?? ''),
            (float) $order->get_total(),
            (string) $order->get_order_number()
        );

        if ($payload === null) {
            return;
        }

        wp_enqueue_script('btpw-qrcode', \BTPW_URL.'assets/js/vendor/qrcode.js', [], \BTPW_VERSION, true);
        wp_enqueue_script('btpw-girocode', \BTPW_URL.'assets/js/frontend-girocode.js', ['btpw-qrcode'], \BTPW_VERSION, true);

        // Base64 so newline separators survive HTML attribute normalisation.
        echo '<div class="btpw-girocode" data-girocode="'.esc_attr(base64_encode($payload)).'"></div>';
        echo '<span class="btpw-girocode__hint">'.esc_html__('Scan this GiroCode with your banking app to pre-fill the transfer.', 'bank-transfer-payments-for-woocommerce').'</span>';
    }

    /**
     * Append instructions to WooCommerce customer emails.
     */
    public function email_instructions(WC_Order $order, bool $sent_to_admin, bool $plain_text = false): void
    {
        if ($this->id !== $order->get_payment_method() || $sent_to_admin) {
            return;
        }

        $bankDetailsJson = $order->get_meta('_stripe_bank_transfer_details');

        if (! empty($bankDetailsJson) && ! $plain_text) {
            $bankDetails = json_decode($bankDetailsJson, true);
            if (is_array($bankDetails)) {
                $this->display_bank_transfer_instructions($bankDetails, $order, true);
            }
        }
    }

    private function get_order_total_in_cents(WC_Order $order): int
    {
        return absint(wc_format_decimal((string) ($order->get_total() * 100), 0));
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->debug_mode && function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'bank-transfer-payments']);
        }
    }
}
