<?php

/**
 * Customer Balance Display
 *
 * Displays customer Stripe balance in WordPress admin user profile.
 *
 * @class       WC_Stripe_Customer_Balance_Display
 */
if (! defined('ABSPATH')) {
    exit;
}

class WC_Stripe_Customer_Balance_Display
{
    /**
     * Stripe API instance
     *
     * @var \Stripe\StripeClient|null
     */
    private $stripe;

    /**
     * Gateway instance
     *
     * @var WC_Gateway_Stripe_Bank_Transfer|null
     */
    private $gateway;

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('show_user_profile', [$this, 'display_customer_balance']);
        add_action('edit_user_profile', [$this, 'display_customer_balance']);

        // Admin styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Initialize Stripe connection
     */
    private function init_stripe()
    {
        if ($this->stripe) {
            return true;
        }

        $this->gateway = $this->get_gateway_instance();

        if (! $this->gateway) {
            return false;
        }

        $secret_key = $this->gateway->testmode
            ? $this->gateway->test_secret_key
            : $this->gateway->live_secret_key;

        if (! $secret_key) {
            return false;
        }

        try {
            $this->stripe = new \Stripe\StripeClient([
                'api_key' => $secret_key,
                'stripe_version' => '2023-10-16',
            ]);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Display customer balance in user profile
     *
     * @param  WP_User  $user
     */
    public function display_customer_balance($user)
    {
        // Only show to admins and shop managers
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        if (! $this->init_stripe()) {
            return;
        }

        echo '<h2>' . esc_html__('Stripe Customer Balance', 'wc-stripe-bank-transfers') . '</h2>';
        echo '<div class="stripe-customer-balance-section">';

        // Get Stripe customer ID from integration class
        $testmode = $this->gateway && $this->gateway->testmode;
        $stripe_customer_id = WC_Stripe_Plugin_Integration::get_stripe_customer_id($user->ID, $testmode);

        if (! $stripe_customer_id) {
            echo '<p>' . esc_html__('No Stripe customer ID found for this user.', 'wc-stripe-bank-transfers') . '</p>';
            echo '</div>';

            return;
        }

        try {
            // Fetch customer from Stripe
            $customer = $this->stripe->customers->retrieve($stripe_customer_id);

            echo '<table class="form-table" role="presentation">';

            // Customer ID
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Stripe Customer ID', 'wc-stripe-bank-transfers') . '</th>';
            echo '<td><code>' . esc_html($customer->id) . '</code></td>';
            echo '</tr>';

            // Balance
            $balance = $customer->balance ?? 0;
            $balance_formatted = $this->format_stripe_amount($balance);
            $balance_class = $balance < 0 ? 'negative' : ($balance > 0 ? 'positive' : 'zero');

            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Customer Balance', 'wc-stripe-bank-transfers') . '</th>';
            echo '<td>';
            echo '<span class="stripe-balance stripe-balance-' . esc_attr($balance_class) . '">';
            echo esc_html($balance_formatted);
            echo '</span>';

            if ($balance < 0) {
                echo '<p class="description">' . esc_html__('Negative balance means the customer has a credit.', 'wc-stripe-bank-transfers') . '</p>';
            } elseif ($balance > 0) {
                echo '<p class="description">' . esc_html__('Positive balance means the customer owes money.', 'wc-stripe-bank-transfers') . '</p>';
            }
            echo '</td>';
            echo '</tr>';

            // Currency
            if (isset($customer->currency)) {
                echo '<tr>';
                echo '<th scope="row">' . esc_html__('Currency', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html(strtoupper($customer->currency)) . '</td>';
                echo '</tr>';
            }

            // Cash Balance (if available)
            if (isset($customer->cash_balance)) {
                $cash_balance = $customer->cash_balance;
                if (isset($cash_balance['available'])) {
                    foreach ($cash_balance['available'] as $currency => $amount) {
                        $cash_formatted = $this->format_stripe_amount($amount, $currency);
                        echo '<tr>';
                        echo '<th scope="row">' . esc_html__('Available Cash Balance', 'wc-stripe-bank-transfers') . '</th>';
                        echo '<td>' . esc_html($cash_formatted) . '</td>';
                        echo '</tr>';
                    }
                }
            }

            // Link to Stripe dashboard
            $stripe_mode = $this->gateway->testmode ? 'test' : 'live';
            $dashboard_url = $stripe_mode === 'test'
                ? 'https://dashboard.stripe.com/test/customers/' . $customer->id
                : 'https://dashboard.stripe.com/customers/' . $customer->id;

            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Stripe Dashboard', 'wc-stripe-bank-transfers') . '</th>';
            echo '<td><a href="' . esc_url($dashboard_url) . '" target="_blank" class="button">' . esc_html__('View in Stripe', 'wc-stripe-bank-transfers') . ' →</a></td>';
            echo '</tr>';

            echo '</table>';

            // Display recent balance transactions
            $this->display_balance_transactions($stripe_customer_id);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            echo '<div class="notice notice-error inline">';
            echo '<p>' . esc_html__('Error fetching customer data from Stripe: ', 'wc-stripe-bank-transfers') . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        } catch (Exception $e) {
            echo '<div class="notice notice-error inline">';
            echo '<p>' . esc_html__('An error occurred: ', 'wc-stripe-bank-transfers') . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Display recent balance transactions
     *
     * @param  string  $customer_id
     */
    private function display_balance_transactions($customer_id)
    {
        try {
            $transactions = $this->stripe->customers->allBalanceTransactions($customer_id, ['limit' => 10]);

            if (empty($transactions->data)) {
                return;
            }

            echo '<h3>' . esc_html__('Recent Balance Transactions', 'wc-stripe-bank-transfers') . '</h3>';
            echo '<table class="widefat striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . esc_html__('Date', 'wc-stripe-bank-transfers') . '</th>';
            echo '<th>' . esc_html__('Type', 'wc-stripe-bank-transfers') . '</th>';
            echo '<th>' . esc_html__('Amount', 'wc-stripe-bank-transfers') . '</th>';
            echo '<th>' . esc_html__('Description', 'wc-stripe-bank-transfers') . '</th>';
            echo '<th>' . esc_html__('Ending Balance', 'wc-stripe-bank-transfers') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($transactions->data as $transaction) {
                echo '<tr>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $transaction->created)) . '</td>';
                echo '<td>' . esc_html(ucfirst(str_replace('_', ' ', $transaction->type))) . '</td>';

                $amount_class = $transaction->amount < 0 ? 'negative' : 'positive';
                echo '<td class="stripe-balance-' . esc_attr($amount_class) . '">' . esc_html($this->format_stripe_amount($transaction->amount, $transaction->currency)) . '</td>';

                echo '<td>' . esc_html($transaction->description ?? '-') . '</td>';
                echo '<td>' . esc_html($this->format_stripe_amount($transaction->ending_balance, $transaction->currency)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

        } catch (Exception $e) {
            // Silently fail - balance transactions are supplementary info
        }
    }

    /**
     * Format Stripe amount (cents to dollars)
     *
     * @param  int  $amount  Amount in cents
     * @param  string  $currency  Currency code
     * @return string
     */
    private function format_stripe_amount($amount, $currency = 'usd')
    {
        $amount_decimal = $amount / 100;

        return wc_price($amount_decimal, ['currency' => strtoupper($currency)]);
    }

    /**
     * Get gateway instance
     *
     * @return WC_Gateway_Stripe_Bank_Transfer|false
     */
    private function get_gateway_instance()
    {
        if (! function_exists('WC') || ! WC()->payment_gateways) {
            return false;
        }

        $gateways = WC()->payment_gateways->payment_gateways();

        if (isset($gateways['stripe_bank_transfer'])) {
            return $gateways['stripe_bank_transfer'];
        }

        return false;
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook)
    {
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .stripe-customer-balance-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin: 20px 0;
            }
            .stripe-balance {
                font-size: 18px;
                font-weight: 600;
                padding: 5px 10px;
                border-radius: 3px;
                display: inline-block;
            }
            .stripe-balance.stripe-balance-positive {
                background: #fef5e7;
                color: #d68910;
            }
            .stripe-balance.stripe-balance-negative {
                background: #eafaf1;
                color: #239b56;
            }
            .stripe-balance.stripe-balance-zero {
                background: #f8f9fa;
                color: #6c757d;
            }
            .stripe-customer-balance-section table.widefat {
                margin-top: 15px;
            }
        ');
    }
}
