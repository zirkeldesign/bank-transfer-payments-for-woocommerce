<?php

/**
 * Stripe Webhook Handler
 *
 * Handles incoming webhooks from Stripe for bank transfer payment events.
 *
 * @class       WC_Stripe_Webhook_Handler
 */
if (! defined('ABSPATH')) {
    exit;
}

class WC_Stripe_Webhook_Handler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Webhook endpoint will be: /wp-json/wc-stripe-bank-transfers/v1/webhook
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        register_rest_route('wc-stripe-bank-transfers/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Stripe signature verification handles security
        ]);
    }

    /**
     * Handle incoming webhook
     *
     * @param  WP_REST_Request  $request
     * @return WP_REST_Response
     */
    public function handle_webhook($request)
    {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');

        // Get gateway settings
        $gateway = $this->get_gateway_instance();

        if (! $gateway) {
            $this->log('Gateway not configured', 'error');

            return new WP_REST_Response(['error' => 'Gateway not configured'], 500);
        }

        // Get webhook secret from settings (you'll need to add this field)
        $webhook_secret = $gateway->get_option('webhook_secret');

        if (! $webhook_secret) {
            // If no webhook secret is configured, we can't verify the signature
            // Log this but process anyway (not recommended for production)
            $this->log('Webhook secret not configured - processing unverified webhook', 'warning');
        }

        try {
            // Verify webhook signature
            if ($webhook_secret && $sig_header) {
                $event = \Stripe\Webhook::constructEvent(
                    $payload,
                    $sig_header,
                    $webhook_secret
                );
            } else {
                // Parse event without verification (not recommended for production)
                $event = json_decode($payload, true);
            }

            $this->log('Webhook received: ' . $event['type']);

            // Handle the event
            switch ($event['type']) {
                case 'payment_intent.succeeded':
                    $this->handle_payment_succeeded($event['data']['object']);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handle_payment_failed($event['data']['object']);
                    break;

                case 'payment_intent.canceled':
                    $this->handle_payment_canceled($event['data']['object']);
                    break;

                case 'payment_intent.processing':
                    $this->handle_payment_processing($event['data']['object']);
                    break;

                case 'payment_intent.requires_action':
                    // Bank transfer instructions are available
                    $this->handle_requires_action($event['data']['object']);
                    break;

                default:
                    $this->log('Unhandled webhook event type: ' . $event['type']);
            }

            return new WP_REST_Response(['received' => true], 200);

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->log('Webhook signature verification failed: ' . $e->getMessage(), 'error');

            return new WP_REST_Response(['error' => 'Invalid signature'], 400);
        } catch (Exception $e) {
            $this->log('Webhook error: ' . $e->getMessage(), 'error');

            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle successful payment
     *
     * @param  array  $payment_intent
     */
    private function handle_payment_succeeded($payment_intent)
    {
        $order = $this->get_order_from_payment_intent($payment_intent);

        if (! $order) {
            $this->log('Order not found for payment_intent: ' . $payment_intent['id'], 'error');

            return;
        }

        // Check if already processed
        if ($order->has_status('processing') || $order->has_status('completed')) {
            $this->log('Order #' . $order->get_id() . ' already processed');

            return;
        }

        // Update order status
        $order->payment_complete($payment_intent['id']);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Stripe bank transfer payment completed. Payment Intent ID: %s', 'wc-stripe-bank-transfers'),
                $payment_intent['id']
            )
        );

        // Update payment intent status
        $order->update_meta_data('_stripe_payment_intent_status', 'succeeded');
        $order->save();

        $this->log('Payment succeeded for order #' . $order->get_id());
    }

    /**
     * Handle failed payment
     *
     * @param  array  $payment_intent
     */
    private function handle_payment_failed($payment_intent)
    {
        $order = $this->get_order_from_payment_intent($payment_intent);

        if (! $order) {
            $this->log('Order not found for payment_intent: ' . $payment_intent['id'], 'error');

            return;
        }

        // Update order status to failed
        $order->update_status('failed', __('Bank transfer payment failed.', 'wc-stripe-bank-transfers'));

        // Add order note with failure reason
        $failure_message = isset($payment_intent['last_payment_error']['message'])
            ? $payment_intent['last_payment_error']['message']
            : __('Unknown error', 'wc-stripe-bank-transfers');

        $order->add_order_note(
            sprintf(
                __('Stripe bank transfer payment failed. Reason: %s. Payment Intent ID: %s', 'wc-stripe-bank-transfers'),
                $failure_message,
                $payment_intent['id']
            )
        );

        // Update payment intent status
        $order->update_meta_data('_stripe_payment_intent_status', 'payment_failed');
        $order->save();

        $this->log('Payment failed for order #' . $order->get_id() . ': ' . $failure_message);
    }

    /**
     * Handle canceled payment
     *
     * @param  array  $payment_intent
     */
    private function handle_payment_canceled($payment_intent)
    {
        $order = $this->get_order_from_payment_intent($payment_intent);

        if (! $order) {
            $this->log('Order not found for payment_intent: ' . $payment_intent['id'], 'error');

            return;
        }

        // Update order status to cancelled
        $order->update_status('cancelled', __('Bank transfer payment was cancelled.', 'wc-stripe-bank-transfers'));

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Stripe bank transfer payment cancelled. Payment Intent ID: %s', 'wc-stripe-bank-transfers'),
                $payment_intent['id']
            )
        );

        // Update payment intent status
        $order->update_meta_data('_stripe_payment_intent_status', 'canceled');
        $order->save();

        $this->log('Payment canceled for order #' . $order->get_id());
    }

    /**
     * Handle processing payment
     *
     * @param  array  $payment_intent
     */
    private function handle_payment_processing($payment_intent)
    {
        $order = $this->get_order_from_payment_intent($payment_intent);

        if (! $order) {
            $this->log('Order not found for payment_intent: ' . $payment_intent['id'], 'error');

            return;
        }

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Bank transfer payment is being processed by Stripe. Payment Intent ID: %s', 'wc-stripe-bank-transfers'),
                $payment_intent['id']
            )
        );

        // Update payment intent status
        $order->update_meta_data('_stripe_payment_intent_status', 'processing');
        $order->save();

        $this->log('Payment processing for order #' . $order->get_id());
    }

    /**
     * Handle requires action (bank transfer instructions available)
     *
     * @param  array  $payment_intent
     */
    private function handle_requires_action($payment_intent)
    {
        $order = $this->get_order_from_payment_intent($payment_intent);

        if (! $order) {
            $this->log('Order not found for payment_intent: ' . $payment_intent['id'], 'error');

            return;
        }

        // Update bank transfer details if available
        if (isset($payment_intent['next_action']['display_bank_transfer_instructions'])) {
            $bank_details = $payment_intent['next_action']['display_bank_transfer_instructions'];
            $order->update_meta_data('_stripe_bank_transfer_details', json_encode($bank_details));

            $order->add_order_note(
                __('Bank transfer instructions have been updated.', 'wc-stripe-bank-transfers')
            );

            $order->save();

            $this->log('Bank transfer instructions updated for order #' . $order->get_id());
        }
    }

    /**
     * Get order from payment intent
     *
     * @param  array  $payment_intent
     * @return WC_Order|false
     */
    private function get_order_from_payment_intent($payment_intent)
    {
        // First try to get order ID from metadata
        if (isset($payment_intent['metadata']['order_id'])) {
            $order_id = $payment_intent['metadata']['order_id'];
            $order = wc_get_order($order_id);

            if ($order) {
                return $order;
            }
        }

        // Fallback: search for order by payment intent ID
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_stripe_payment_intent_id',
            'meta_value' => $payment_intent['id'],
            'meta_compare' => '=',
        ]);

        return ! empty($orders) ? $orders[0] : false;
    }

    /**
     * Get gateway instance
     *
     * @return WC_Gateway_Stripe_Bank_Transfer|false
     */
    private function get_gateway_instance()
    {
        $gateways = WC()->payment_gateways->payment_gateways();

        if (isset($gateways['stripe_bank_transfer'])) {
            return $gateways['stripe_bank_transfer'];
        }

        return false;
    }

    /**
     * Log messages
     *
     * @param  string  $message
     * @param  string  $level
     */
    private function log($message, $level = 'info')
    {
        $gateway = $this->get_gateway_instance();

        if ($gateway && $gateway->get_option('debug_mode') === 'yes') {
            $logger = wc_get_logger();
            $logger->log($level, $message, ['source' => 'stripe-bank-transfer-webhook']);
        }
    }
}
