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

        // AJAX handlers
        add_action('wp_ajax_create_financial_address', [$this, 'ajax_create_financial_address']);
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

        $testmode = $this->gateway->get_option('testmode') === 'yes';

        // Try to get Stripe client from integration class (handles both existing plugins and manual config)
        $this->stripe = WC_Stripe_Plugin_Integration::get_stripe_client($testmode);

        if (! $this->stripe) {
            // Fallback: Try using gateway's secret key directly
            $secret_key = $testmode
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
            } catch (Exception $e) {
                return false;
            }
        }

        return true;
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
            // Fetch customer from Stripe with cash balance expansion
            $customer = $this->stripe->customers->retrieve($stripe_customer_id, [
                'expand' => ['cash_balance'],
            ]);

            echo '<table class="form-table" role="presentation">';

            // Customer ID
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Stripe Customer ID', 'wc-stripe-bank-transfers') . '</th>';
            echo '<td><code>' . esc_html($customer->id) . '</code></td>';
            echo '</tr>';

            // Overall Cash Balance (for virtual bank account)
            $cash_balance_shown = false;
            if (isset($customer->cash_balance)) {
                // Try to get from available balance
                if (isset($customer->cash_balance->available) && ! empty($customer->cash_balance->available)) {
                    // Convert Stripe object to array for iteration
                    $available_balances = json_decode(json_encode($customer->cash_balance->available), true);
                    if (is_array($available_balances)) {
                        foreach ($available_balances as $currency => $amount) {
                            if ($amount != 0) {
                                $cash_formatted = $this->format_stripe_amount($amount, $currency);
                                $cash_class = $amount < 0 ? 'negative' : ($amount > 0 ? 'positive' : 'zero');

                                echo '<tr>';
                                echo '<th scope="row">' . esc_html__('Cash Balance (Virtual Bank Account)', 'wc-stripe-bank-transfers') . '</th>';
                                echo '<td>';
                                echo '<span class="stripe-balance stripe-balance-' . esc_attr($cash_class) . '">';
                                echo wp_kses_post($cash_formatted);
                                echo '</span>';
                                echo '<p class="description">' . esc_html__('Available funds from bank transfer payments.', 'wc-stripe-bank-transfers') . '</p>';
                                echo '</td>';
                                echo '</tr>';
                                $cash_balance_shown = true;
                            }
                        }
                    }
                }

                // If still not shown, check if there are transactions and calculate balance
                if (! $cash_balance_shown) {
                    try {
                        $transactions = $this->stripe->customers->allCashBalanceTransactions($stripe_customer_id, ['limit' => 100]);
                        if (! empty($transactions->data)) {
                            // Calculate net balance from transactions
                            $balance_by_currency = [];
                            foreach ($transactions->data as $transaction) {
                                $currency = $transaction->currency ?? 'usd';
                                if (! isset($balance_by_currency[$currency])) {
                                    $balance_by_currency[$currency] = 0;
                                }
                                $balance_by_currency[$currency] += $transaction->net_amount ?? 0;
                            }

                            // Display calculated balances
                            foreach ($balance_by_currency as $currency => $amount) {
                                $cash_formatted = $this->format_stripe_amount($amount, $currency);
                                $cash_class = $amount < 0 ? 'negative' : ($amount > 0 ? 'positive' : 'zero');

                                echo '<tr>';
                                echo '<th scope="row">' . esc_html__('Cash Balance (Virtual Bank Account)', 'wc-stripe-bank-transfers') . '</th>';
                                echo '<td>';
                                echo '<span class="stripe-balance stripe-balance-' . esc_attr($cash_class) . '">';
                                echo wp_kses_post($cash_formatted);
                                echo '</span>';
                                echo '<p class="description">' . esc_html__('Calculated from transaction history.', 'wc-stripe-bank-transfers') . '</p>';
                                echo '</td>';
                                echo '</tr>';
                                $cash_balance_shown = true;
                            }
                        }
                    } catch (Exception $e) {
                        // Could not fetch transactions
                    }
                }

                // If still nothing to show, display zero
                if (! $cash_balance_shown) {
                    $default_currency = $customer->currency ?? 'usd';
                    $cash_formatted = $this->format_stripe_amount(0, $default_currency);

                    echo '<tr>';
                    echo '<th scope="row">' . esc_html__('Cash Balance (Virtual Bank Account)', 'wc-stripe-bank-transfers') . '</th>';
                    echo '<td>';
                    echo '<span class="stripe-balance stripe-balance-zero">';
                    echo wp_kses_post($cash_formatted);
                    echo '</span>';
                    echo '<p class="description">' . esc_html__('No funds received yet via bank transfers.', 'wc-stripe-bank-transfers') . '</p>';
                    echo '</td>';
                    echo '</tr>';
                }
            }

            // Currency
            if (isset($customer->currency)) {
                echo '<tr>';
                echo '<th scope="row">' . esc_html__('Default Currency', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html(strtoupper($customer->currency)) . '</td>';
                echo '</tr>';
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

            // Display virtual bank account details and cash balance transactions
            $this->display_cash_balance_transactions($stripe_customer_id, $user);

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
                echo '<td class="stripe-balance-' . esc_attr($amount_class) . '">' . wp_kses_post($this->format_stripe_amount($transaction->amount, $transaction->currency)) . '</td>';

                echo '<td>' . esc_html($transaction->description ?? '-') . '</td>';
                echo '<td>' . wp_kses_post($this->format_stripe_amount($transaction->ending_balance, $transaction->currency)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';

        } catch (Exception $e) {
            // Silently fail - balance transactions are supplementary info
        }
    }

    /**
     * Display cash balance transactions
     *
     * @param  string  $customer_id
     * @param  WP_User  $user
     */
    private function display_cash_balance_transactions($customer_id, $user)
    {
        try {
            // First, display the customer's virtual bank account details if available
            $has_virtual_account = $this->display_financial_addresses($customer_id, $user);

            // Get cash balance transactions
            $transactions = $this->stripe->customers->allCashBalanceTransactions(
                $customer_id,
                ['limit' => 10]
            );

            echo '<h3>' . esc_html__('Cash Balance Transactions', 'wc-stripe-bank-transfers') . '</h3>';

            if (! empty($transactions->data)) {
                echo '<table class="widefat striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>' . esc_html__('Date', 'wc-stripe-bank-transfers') . '</th>';
                echo '<th>' . esc_html__('Type', 'wc-stripe-bank-transfers') . '</th>';
                echo '<th>' . esc_html__('Amount', 'wc-stripe-bank-transfers') . '</th>';
                echo '<th>' . esc_html__('Status', 'wc-stripe-bank-transfers') . '</th>';
                echo '<th>' . esc_html__('Details', 'wc-stripe-bank-transfers') . '</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($transactions->data as $transaction) {
                    $type_label = ucfirst(str_replace('_', ' ', $transaction->type));
                    $amount_class = $transaction->net_amount > 0 ? 'positive' : ($transaction->net_amount < 0 ? 'negative' : 'zero');

                    echo '<tr>';
                    echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $transaction->created)) . '</td>';
                    echo '<td>' . esc_html($type_label) . '</td>';
                    echo '<td class="stripe-balance-' . esc_attr($amount_class) . '">' . wp_kses_post($this->format_stripe_amount($transaction->net_amount, $transaction->currency)) . '</td>';
                    echo '<td><span class="stripe-balance stripe-balance-' . esc_attr($amount_class) . '">' . esc_html(ucfirst($transaction->status ?? 'pending')) . '</span></td>';

                    // Show details based on transaction type
                    $details = '-';
                    if (isset($transaction->applied_to_payment)) {
                        $details = __('Applied to payment', 'wc-stripe-bank-transfers');
                    } elseif (isset($transaction->funded)) {
                        $details = __('Funded', 'wc-stripe-bank-transfers');
                    } elseif (isset($transaction->refunded_from_payment)) {
                        $details = __('Refunded from payment', 'wc-stripe-bank-transfers');
                    }
                    echo '<td>' . esc_html($details) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
            } else {
                echo '<p>' . esc_html__('No cash balance transactions yet.', 'wc-stripe-bank-transfers') . '</p>';
            }

        } catch (Exception $e) {
            // Silently fail - cash balance transactions are supplementary info
        }
    }

    /**
     * Display customer's financial addresses (virtual bank account details)
     *
     * @param  string  $customer_id
     * @param  WP_User  $user
     * @return bool Whether virtual bank account exists
     */
    private function display_financial_addresses($customer_id, $user)
    {
        try {
            echo '<h3>' . esc_html__('Virtual Bank Account Details', 'wc-stripe-bank-transfers') . '</h3>';

            $has_addresses = false;

            // Try to retrieve funding instructions using the API request method
            try {
                $response = $this->stripe->request(
                    'get',
                    '/v1/customers/' . $customer_id . '/funding_instructions',
                    [],
                    []
                );

                if (! empty($response->data)) {
                    echo '<p class="description" style="margin-bottom: 15px;">'
                        . esc_html__('These are unique bank account details for this customer to receive bank transfers.', 'wc-stripe-bank-transfers')
                        . '</p>';

                    foreach ($response->data as $instruction) {
                        if (isset($instruction->bank_transfer->financial_addresses)) {
                            foreach ($instruction->bank_transfer->financial_addresses as $address) {
                                $this->render_financial_address($address);
                                $has_addresses = true;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // No funding instructions found or API error
            }

            if (! $has_addresses) {
                echo '<p>' . esc_html__('No virtual bank account created yet.', 'wc-stripe-bank-transfers') . '</p>';

                // Add button to create financial address (only if no account exists)
                echo '<div style="margin-top: 15px; margin-bottom: 30px;">';
                echo '<button type="button" class="button button-primary" id="create-financial-address" data-customer-id="' . esc_attr($customer_id) . '" data-user-id="' . esc_attr($user->ID) . '">';
                echo esc_html__('Create Virtual Bank Account', 'wc-stripe-bank-transfers');
                echo '</button>';
                echo '<span class="spinner" style="float: none; margin: 0 0 0 10px;"></span>';
                echo '<p class="description" style="margin-top: 10px;">'
                    . esc_html__('Generates unique bank account details for this customer to receive transfers.', 'wc-stripe-bank-transfers')
                    . '</p>';
                echo '</div>';
            } else {
                // Small margin after bank details if account exists
                echo '<div style="margin-bottom: 30px;"></div>';
            }

            // Add inline script for AJAX
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#create-financial-address').on('click', function() {
                    var button = $(this);
                    var spinner = button.next('.spinner');
                    var customerId = button.data('customer-id');
                    var userId = button.data('user-id');

                    button.prop('disabled', true);
                    spinner.addClass('is-active');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'create_financial_address',
                            customer_id: customerId,
                            user_id: userId,
                            nonce: '<?php echo wp_create_nonce('create_financial_address_' . $user->ID); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php echo esc_js(__('Virtual bank account created successfully!', 'wc-stripe-bank-transfers')); ?>');
                                location.reload();
                            } else {
                                alert('<?php echo esc_js(__('Error: ', 'wc-stripe-bank-transfers')); ?>' + (response.data || '<?php echo esc_js(__('Unknown error', 'wc-stripe-bank-transfers')); ?>'));
                            }
                        },
                        error: function() {
                            alert('<?php echo esc_js(__('An error occurred. Please try again.', 'wc-stripe-bank-transfers')); ?>');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                            spinner.removeClass('is-active');
                        }
                    });
                });
            });
            </script>
            <?php

            return $has_addresses;

        } catch (Exception $e) {
            echo '<p>' . esc_html__('Unable to load financial addresses.', 'wc-stripe-bank-transfers') . '</p>';
            echo '<p class="description" style="color: #d63638;">' . esc_html($e->getMessage()) . '</p>';

            return false;
        }
    }

    /**
     * Render a financial address (bank account details)
     *
     * @param  object  $address
     */
    private function render_financial_address($address)
    {
        if (! isset($address->type)) {
            return;
        }

        echo '<div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-bottom: 15px;">';
        echo '<h4 style="margin-top: 0;">' . esc_html(strtoupper($address->type)) . ' ' . esc_html__('Bank Account', 'wc-stripe-bank-transfers') . '</h4>';

        echo '<table class="widefat" style="background: white;">';
        echo '<tbody>';

        // Display based on type
        if ($address->type === 'aba') {
            // US bank account (ACH)
            if (isset($address->aba)) {
                echo '<tr><th style="width: 200px;">' . esc_html__('Account Number', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->aba->account_number ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Routing Number', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->aba->routing_number ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Bank Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->aba->bank_name ?? '-') . '</td></tr>';

                echo '<tr><th>' . esc_html__('Account Holder Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->aba->account_holder_name ?? '-') . '</td></tr>';
            }
        } elseif ($address->type === 'iban') {
            // European bank account (SEPA)
            if (isset($address->iban)) {
                echo '<tr><th style="width: 200px;">' . esc_html__('IBAN', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->iban->iban ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('BIC/SWIFT', 'wc-stripe-bank-transfers') . '</th>';
                $bic = $address->iban->bic ?? $address->iban->swift_code ?? $address->iban->swift ?? '-';
                echo '<td><code style="font-size: 14px;">' . esc_html($bic) . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Bank Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->iban->bank_name ?? '-') . '</td></tr>';

                echo '<tr><th>' . esc_html__('Account Holder Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->iban->account_holder_name ?? '-') . '</td></tr>';
            }
        } elseif ($address->type === 'sort_code') {
            // UK bank account (Bacs)
            if (isset($address->sort_code)) {
                echo '<tr><th style="width: 200px;">' . esc_html__('Account Number', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->sort_code->account_number ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Sort Code', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->sort_code->sort_code ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Bank Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->sort_code->bank_name ?? '-') . '</td></tr>';

                echo '<tr><th>' . esc_html__('Account Holder Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->sort_code->account_holder_name ?? '-') . '</td></tr>';
            }
        } elseif ($address->type === 'spei') {
            // Mexican bank account (SPEI)
            if (isset($address->spei)) {
                echo '<tr><th style="width: 200px;">' . esc_html__('CLABE', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->spei->clabe ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Bank Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->spei->bank_name ?? '-') . '</td></tr>';

                echo '<tr><th>' . esc_html__('Account Holder Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->spei->account_holder_name ?? '-') . '</td></tr>';
            }
        } elseif ($address->type === 'zengin') {
            // Japanese bank account
            if (isset($address->zengin)) {
                echo '<tr><th style="width: 200px;">' . esc_html__('Account Number', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->zengin->account_number ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Bank Code', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->zengin->bank_code ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Branch Code', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td><code style="font-size: 14px;">' . esc_html($address->zengin->branch_code ?? '-') . '</code></td></tr>';

                echo '<tr><th>' . esc_html__('Account Holder Name', 'wc-stripe-bank-transfers') . '</th>';
                echo '<td>' . esc_html($address->zengin->account_holder_name ?? '-') . '</td></tr>';
            }
        }

        // Supported networks
        if (isset($address->supported_networks) && is_array($address->supported_networks)) {
            echo '<tr><th>' . esc_html__('Supported Networks', 'wc-stripe-bank-transfers') . '</th>';
            echo '<td>' . esc_html(implode(', ', array_map('strtoupper', $address->supported_networks))) . '</td></tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * AJAX handler to create financial address for customer
     */
    public function ajax_create_financial_address()
    {
        // Verify nonce
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'create_financial_address_' . $user_id)) {
            wp_send_json_error(__('Security check failed', 'wc-stripe-bank-transfers'));

            return;
        }

        // Check permissions
        if (! current_user_can('edit_user', $user_id)) {
            wp_send_json_error(__('Permission denied', 'wc-stripe-bank-transfers'));

            return;
        }

        $customer_id = isset($_POST['customer_id']) ? sanitize_text_field($_POST['customer_id']) : '';

        if (! $customer_id) {
            wp_send_json_error(__('Customer ID is required', 'wc-stripe-bank-transfers'));

            return;
        }

        try {
            $this->init_stripe();

            if (! $this->stripe) {
                wp_send_json_error(__('Stripe connection failed', 'wc-stripe-bank-transfers'));

                return;
            }

            // Create funding instructions using direct API request
            $transfer_type = $this->gateway->get_option('transfer_type', 'eu_bank_account');
            $currency = $this->gateway->get_option('default_currency', 'eur');

            $response = $this->stripe->request(
                'post',
                '/v1/customers/' . $customer_id . '/funding_instructions',
                [
                    'bank_transfer' => [
                        'type' => $transfer_type,
                    ],
                    'currency' => $currency,
                    'funding_type' => 'bank_transfer',
                ],
                []
            );

            wp_send_json_success([
                'message' => __('Virtual bank account created successfully', 'wc-stripe-bank-transfers'),
                'funding_instructions' => $response->id ?? null,
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            wp_send_json_error($e->getMessage());
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
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
                font-size: 0.9rem;
                font-weight: 600;
                padding: 0.5rem 0.75rem;
                border-radius: 0.25rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
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
