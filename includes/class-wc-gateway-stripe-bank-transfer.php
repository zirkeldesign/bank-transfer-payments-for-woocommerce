<?php

/**
 * WooCommerce Stripe Bank Transfer Gateway
 *
 * Provides a Stripe Bank Transfer Payment Gateway for WooCommerce.
 *
 * @class       WC_Gateway_Stripe_Bank_Transfer
 *
 * @extends     WC_Payment_Gateway
 */
if (! defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Stripe_Bank_Transfer extends WC_Payment_Gateway
{
    /**
     * Stripe API instance
     *
     * @var \Stripe\StripeClient
     */
    private $stripe;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'stripe_bank_transfer';
        $this->icon = ''; // URL to icon
        $this->has_fields = true;
        $this->method_title = __('Stripe Bank Transfer', 'wc-stripe-bank-transfers');
        $this->method_description = __('Accept bank transfer payments via Stripe. Customers receive individual bank account details for each order.', 'wc-stripe-bank-transfers');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = $this->get_option('testmode') === 'yes';
        $this->test_secret_key = $this->get_option('test_secret_key');
        $this->live_secret_key = $this->get_option('live_secret_key');
        $this->transfer_type = $this->get_option('transfer_type', 'us_bank_account');
        $this->default_currency = $this->get_option('default_currency', 'eur');
        $this->debug_mode = $this->get_option('debug_mode') === 'yes';

        // Check for existing Stripe plugin integration
        if (WC_Stripe_Plugin_Integration::should_use_existing_credentials($this)) {
            $credentials = WC_Stripe_Plugin_Integration::get_stripe_credentials($this->testmode);

            if ($credentials) {
                $this->secret_key = $credentials['secret_key'];
                $this->log('Using Stripe credentials from: ' . $credentials['source']);
            } else {
                $this->secret_key = $this->testmode ? $this->test_secret_key : $this->live_secret_key;
            }
        } else {
            // Set API key based on mode
            $this->secret_key = $this->testmode ? $this->test_secret_key : $this->live_secret_key;
        }

        // Initialize Stripe
        if ($this->secret_key) {
            $this->stripe = new \Stripe\StripeClient([
                'api_key' => $this->secret_key,
                'stripe_version' => '2023-10-16',
            ]);
        }

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'clear_integration_cache']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    /**
     * Display admin notices
     */
    public function admin_notices()
    {
        // Only show on settings page
        if (! isset($_GET['page']) || $_GET['page'] !== 'wc-settings' ||
            ! isset($_GET['tab']) || $_GET['tab'] !== 'checkout' ||
            ! isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }

        // Show integration notice
        $notice = WC_Stripe_Plugin_Integration::get_integration_notice();
        if ($notice) {
            echo $notice;
        }
    }

    /**
     * Clear integration cache after settings save
     */
    public function clear_integration_cache()
    {
        WC_Stripe_Plugin_Integration::clear_cache();
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'wc-stripe-bank-transfers'),
                'label' => __('Enable Stripe Bank Transfer', 'wc-stripe-bank-transfers'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'wc-stripe-bank-transfers'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-stripe-bank-transfers'),
                'default' => __('Bank Transfer', 'wc-stripe-bank-transfers'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'wc-stripe-bank-transfers'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'wc-stripe-bank-transfers'),
                'default' => __('Pay securely using bank transfer. You will receive unique bank account details after placing your order.', 'wc-stripe-bank-transfers'),
                'desc_tip' => true,
            ],
            'testmode' => [
                'title' => __('Test mode', 'wc-stripe-bank-transfers'),
                'label' => __('Enable Test Mode', 'wc-stripe-bank-transfers'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'wc-stripe-bank-transfers'),
                'default' => 'yes',
                'desc_tip' => true,
            ],
            'test_secret_key' => [
                'title' => __('Test Secret Key', 'wc-stripe-bank-transfers'),
                'type' => 'password',
                'description' => __('Get your API keys from your Stripe account.', 'wc-stripe-bank-transfers'),
                'default' => '',
                'desc_tip' => true,
            ],
            'live_secret_key' => [
                'title' => __('Live Secret Key', 'wc-stripe-bank-transfers'),
                'type' => 'password',
                'description' => __('Get your API keys from your Stripe account.', 'wc-stripe-bank-transfers'),
                'default' => '',
                'desc_tip' => true,
            ],
            'webhook_secret' => [
                'title' => __('Webhook Secret', 'wc-stripe-bank-transfers'),
                'type' => 'password',
                'description' => sprintf(
                    __('Enter your webhook signing secret from Stripe Dashboard. Webhook URL: %s', 'wc-stripe-bank-transfers'),
                    '<code>' . rest_url('wc-stripe-bank-transfers/v1/webhook') . '</code>'
                ),
                'default' => '',
                'desc_tip' => false,
            ],
            'transfer_type' => [
                'title' => __('Bank Transfer Type', 'wc-stripe-bank-transfers'),
                'type' => 'select',
                'description' => __('Select the type of bank transfer to accept.', 'wc-stripe-bank-transfers'),
                'default' => 'us_bank_account',
                'desc_tip' => true,
                'options' => [
                    'us_bank_account' => __('US Bank Account (ACH)', 'wc-stripe-bank-transfers'),
                    'eu_bank_account' => __('EU Bank Account (SEPA)', 'wc-stripe-bank-transfers'),
                    'gb_bank_account' => __('UK Bank Account (Bacs)', 'wc-stripe-bank-transfers'),
                    'jp_bank_account' => __('Japanese Bank Account', 'wc-stripe-bank-transfers'),
                    'mx_bank_account' => __('Mexican Bank Account (SPEI)', 'wc-stripe-bank-transfers'),
                ],
            ],
            'default_currency' => [
                'title' => __('Default Currency for Virtual Bank Accounts', 'wc-stripe-bank-transfers'),
                'type' => 'select',
                'description' => __('Currency used when creating virtual bank account details for customers.', 'wc-stripe-bank-transfers'),
                'default' => 'eur',
                'desc_tip' => true,
                'options' => [
                    'usd' => __('US Dollar (USD)', 'wc-stripe-bank-transfers'),
                    'eur' => __('Euro (EUR)', 'wc-stripe-bank-transfers'),
                    'gbp' => __('British Pound (GBP)', 'wc-stripe-bank-transfers'),
                    'jpy' => __('Japanese Yen (JPY)', 'wc-stripe-bank-transfers'),
                    'mxn' => __('Mexican Peso (MXN)', 'wc-stripe-bank-transfers'),
                ],
            ],
            'debug_mode' => [
                'title' => __('Debug Mode', 'wc-stripe-bank-transfers'),
                'label' => __('Enable logging', 'wc-stripe-bank-transfers'),
                'type' => 'checkbox',
                'description' => __('Log events to WooCommerce logs for debugging.', 'wc-stripe-bank-transfers'),
                'default' => 'no',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Output payment fields
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        echo '<div class="stripe-bank-transfer-info">';
        echo '<p>' . esc_html__('After placing your order, you will receive unique bank account details to complete your payment.', 'wc-stripe-bank-transfers') . '</p>';
        echo '</div>';
    }

    /**
     * Process the payment
     *
     * @param  int  $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $this->stripe) {
            wc_add_notice(__('Payment gateway configuration error. Please contact support.', 'wc-stripe-bank-transfers'), 'error');

            return ['result' => 'fail'];
        }

        try {
            // Create customer email
            $customer_email = $order->get_billing_email();

            /**
             * Filter payment intent metadata
             *
             * @param  array  $metadata  Metadata to attach to payment intent
             * @param  WC_Order  $order  WooCommerce order object
             * @param  WC_Gateway_Stripe_Bank_Transfer  $gateway  Gateway instance
             */
            $metadata = apply_filters('wc_stripe_bank_transfer_payment_intent_metadata', [
                'order_id' => $order_id,
                'customer_email' => $customer_email,
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            ], $order, $this);

            // Create PaymentIntent with bank transfer
            $payment_intent_data = [
                'amount' => $this->get_order_total_in_cents($order),
                'currency' => strtolower($order->get_currency()),
                'payment_method_types' => ['customer_balance'],
                'payment_method_data' => [
                    'type' => 'customer_balance',
                ],
                'payment_method_options' => [
                    'customer_balance' => [
                        'funding_type' => $this->transfer_type,
                        'bank_transfer' => [
                            'type' => $this->transfer_type,
                        ],
                    ],
                ],
                'metadata' => $metadata,
                'description' => sprintf(__('Order %s from %s', 'wc-stripe-bank-transfers'), $order->get_order_number(), get_bloginfo('name')),
            ];

            /**
             * Filter payment intent data before sending to Stripe
             *
             * @param  array  $payment_intent_data  Payment intent data
             * @param  WC_Order  $order  WooCommerce order object
             * @param  WC_Gateway_Stripe_Bank_Transfer  $gateway  Gateway instance
             */
            $payment_intent_data = apply_filters('wc_stripe_bank_transfer_payment_intent_data', $payment_intent_data, $order, $this);

            $this->log('Creating PaymentIntent for order #' . $order_id);

            $payment_intent = $this->stripe->paymentIntents->create($payment_intent_data);

            /**
             * Action fired after payment intent is created
             *
             * @param  object  $payment_intent  Stripe PaymentIntent object
             * @param  WC_Order  $order  WooCommerce order object
             * @param  WC_Gateway_Stripe_Bank_Transfer  $gateway  Gateway instance
             */
            do_action('wc_stripe_bank_transfer_payment_intent_created', $payment_intent, $order, $this);

            // Store payment intent details in order meta
            $order->update_meta_data('_stripe_payment_intent_id', $payment_intent->id);
            $order->update_meta_data('_stripe_payment_intent_status', $payment_intent->status);

            // Store bank transfer details if available
            if (isset($payment_intent->next_action->display_bank_transfer_instructions)) {
                $bank_details = $payment_intent->next_action->display_bank_transfer_instructions;
                $order->update_meta_data('_stripe_bank_transfer_details', json_encode($bank_details));

                $this->log('Bank transfer details stored for order #' . $order_id);
            }

            $order->save();

            /**
             * Filter the order status to set after payment intent creation
             *
             * @param  string  $status  Order status (without 'wc-' prefix)
             * @param  WC_Order  $order  WooCommerce order object
             * @param  object  $payment_intent  Stripe PaymentIntent object
             */
            $order_status = apply_filters('wc_stripe_bank_transfer_order_status', 'wc-awaiting-transfer', $order, $payment_intent);

            // Update order status
            $order->update_status($order_status, __('Awaiting bank transfer payment.', 'wc-stripe-bank-transfers'));

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            $this->log('Payment initiated successfully for order #' . $order_id);

            /**
             * Action fired after successful payment processing
             *
             * @param  int  $order_id  Order ID
             * @param  WC_Order  $order  WooCommerce order object
             * @param  object  $payment_intent  Stripe PaymentIntent object
             */
            do_action('wc_stripe_bank_transfer_payment_processed', $order_id, $order, $payment_intent);

            // Return success and redirect to thank you page
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log('Stripe API Error: ' . $e->getMessage(), 'error');
            wc_add_notice(__('Payment failed: ', 'wc-stripe-bank-transfers') . $e->getMessage(), 'error');

            return ['result' => 'fail'];
        } catch (Exception $e) {
            $this->log('Error processing payment: ' . $e->getMessage(), 'error');
            wc_add_notice(__('An error occurred while processing your payment. Please try again.', 'wc-stripe-bank-transfers'), 'error');

            return ['result' => 'fail'];
        }
    }

    /**
     * Thank you page - display bank transfer details
     *
     * @param  int  $order_id
     */
    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        $bank_details_json = $order->get_meta('_stripe_bank_transfer_details');

        if ($bank_details_json) {
            $bank_details = json_decode($bank_details_json, true);
            $this->display_bank_transfer_instructions($bank_details, $order);
        }
    }

    /**
     * Display bank transfer instructions
     *
     * @param  array  $bank_details
     * @param  WC_Order  $order
     */
    private function display_bank_transfer_instructions($bank_details, $order)
    {
        if (! isset($bank_details['financial_addresses'])) {
            return;
        }

        echo '<section class="woocommerce-bank-transfer-details">';
        echo '<h2>' . esc_html__('Bank Transfer Instructions', 'wc-stripe-bank-transfers') . '</h2>';

        echo '<p>' . esc_html__('Please transfer the funds to the following bank account:', 'wc-stripe-bank-transfers') . '</p>';

        foreach ($bank_details['financial_addresses'] as $address) {
            $type = $address['type'];

            echo '<div class="bank-details-box" style="background: #f8f8f8; padding: 20px; margin: 20px 0; border-radius: 5px;">';

            if ($type === 'ach') {
                echo '<h3>' . esc_html__('US Bank Account (ACH)', 'wc-stripe-bank-transfers') . '</h3>';
                echo '<p><strong>' . esc_html__('Account Number:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['ach']['account_number']) . '</p>';
                echo '<p><strong>' . esc_html__('Routing Number:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['ach']['routing_number']) . '</p>';
            } elseif ($type === 'sepa') {
                echo '<h3>' . esc_html__('EU Bank Account (SEPA)', 'wc-stripe-bank-transfers') . '</h3>';
                echo '<p><strong>' . esc_html__('IBAN:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['sepa']['iban']) . '</p>';
                echo '<p><strong>' . esc_html__('BIC:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['sepa']['bic']) . '</p>';
            } elseif ($type === 'sort_code') {
                echo '<h3>' . esc_html__('UK Bank Account (Bacs)', 'wc-stripe-bank-transfers') . '</h3>';
                echo '<p><strong>' . esc_html__('Account Number:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['sort_code']['account_number']) . '</p>';
                echo '<p><strong>' . esc_html__('Sort Code:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['sort_code']['sort_code']) . '</p>';
            } elseif ($type === 'spei') {
                echo '<h3>' . esc_html__('Mexican Bank Account (SPEI)', 'wc-stripe-bank-transfers') . '</h3>';
                echo '<p><strong>' . esc_html__('CLABE:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($address['spei']['clabe']) . '</p>';
            }

            echo '</div>';
        }

        // Display amount and reference
        echo '<div class="payment-details" style="background: #e7f7ff; padding: 20px; margin: 20px 0; border-radius: 5px;">';
        echo '<p><strong>' . esc_html__('Amount:', 'wc-stripe-bank-transfers') . '</strong> ' . wc_price($order->get_total(), ['currency' => $order->get_currency()]) . '</p>';
        echo '<p><strong>' . esc_html__('Order Number:', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html($order->get_order_number()) . '</p>';

        if (isset($bank_details['hosted_instructions_url'])) {
            echo '<p><a href="' . esc_url($bank_details['hosted_instructions_url']) . '" class="button" target="_blank">' . esc_html__('View Full Instructions', 'wc-stripe-bank-transfers') . '</a></p>';
        }
        echo '</div>';

        echo '<p class="important-notice" style="color: #d63638;">' . esc_html__('Important: Please include your order number as the payment reference.', 'wc-stripe-bank-transfers') . '</p>';
        echo '</section>';
    }

    /**
     * Add content to the WC emails
     *
     * @param  WC_Order  $order
     * @param  bool  $sent_to_admin
     * @param  bool  $plain_text
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {
        if ($this->id !== $order->get_payment_method() || $sent_to_admin) {
            return;
        }

        $bank_details_json = $order->get_meta('_stripe_bank_transfer_details');

        if ($bank_details_json && ! $plain_text) {
            $bank_details = json_decode($bank_details_json, true);
            $this->display_bank_transfer_instructions($bank_details, $order);
        }
    }

    /**
     * Get order total in cents
     *
     * @param  WC_Order  $order
     * @return int
     */
    private function get_order_total_in_cents($order)
    {
        return absint(wc_format_decimal($order->get_total() * 100, 0));
    }

    /**
     * Log messages
     *
     * @param  string  $message
     * @param  string  $level
     */
    private function log($message, $level = 'info')
    {
        if ($this->debug_mode) {
            $logger = wc_get_logger();
            $logger->log($level, $message, ['source' => 'stripe-bank-transfer']);
        }
    }
}
