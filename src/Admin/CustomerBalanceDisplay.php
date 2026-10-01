<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Admin;

use Stripe\StripeClient;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\ClientFactory;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\PluginIntegration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shows a customer's Stripe cash balance, cash-balance transactions and virtual
 * bank-account (funding instruction) details on the WordPress user profile
 * screen, and lets a shop manager create a virtual account on demand.
 */
final class CustomerBalanceDisplay
{
    private const ASSET_HANDLE = 'btpw-admin-customer-balance';

    /**
     * Stripe SDK client.
     *
     * @var StripeClient|object|null
     */
    private ?object $stripe = null;

    /**
     * Gateway instance.
     */
    private ?object $gateway = null;

    public const REST_NAMESPACE = 'bank-transfer-payments-for-woocommerce/v1';

    public function register(): void
    {
        add_action('show_user_profile', [$this, 'display_customer_balance']);
        add_action('edit_user_profile', [$this, 'display_customer_balance']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register the authenticated admin REST route used to create a virtual
     * bank account for a customer (replaces the legacy admin-ajax handler).
     */
    public function register_rest_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/funding-instructions', [
            'methods' => 'POST',
            'callback' => [$this, 'create_financial_address'],
            'permission_callback' => static function (WP_REST_Request $request): bool {
                return current_user_can('edit_user', absint($request->get_param('user_id')));
            },
            'args' => [
                'user_id' => ['required' => true, 'type' => 'integer'],
                'customer_id' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * Enqueue the profile-screen script and style.
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') {
            return;
        }

        wp_enqueue_style(
            self::ASSET_HANDLE,
            \BTPW_URL.'assets/css/admin-customer-balance.css',
            [],
            \BTPW_VERSION
        );

        wp_enqueue_script(
            self::ASSET_HANDLE,
            \BTPW_URL.'assets/js/admin-customer-balance.js',
            [],
            \BTPW_VERSION,
            true
        );

        wp_localize_script(self::ASSET_HANDLE, 'btpwAdmin', [
            'restUrl' => rest_url(self::REST_NAMESPACE.'/funding-instructions'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => [
                'created' => __('Virtual bank account created successfully!', 'bank-transfer-payments-for-woocommerce'),
                'errorPrefix' => __('Error: ', 'bank-transfer-payments-for-woocommerce'),
                'unknownError' => __('Unknown error', 'bank-transfer-payments-for-woocommerce'),
                'genericError' => __('An error occurred. Please try again.', 'bank-transfer-payments-for-woocommerce'),
            ],
        ]);
    }

    private function init_stripe(): bool
    {
        if ($this->stripe !== null) {
            return true;
        }

        $this->gateway = $this->get_gateway_instance();

        if ($this->gateway === null) {
            return false;
        }

        $testmode = $this->gateway->get_option('testmode') === 'yes';

        $client = PluginIntegration::getStripeClient($testmode);

        if ($client === false) {
            $secretKey = $testmode
                ? (string) $this->gateway->get_option('test_secret_key')
                : (string) $this->gateway->get_option('live_secret_key');

            if ($secretKey === '') {
                return false;
            }

            try {
                $client = ClientFactory::make($secretKey);
            } catch (Throwable) {
                return false;
            }
        }

        $this->stripe = $client ?: null;

        return $this->stripe !== null;
    }

    public function display_customer_balance(WP_User $user): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        if (! $this->init_stripe()) {
            return;
        }

        echo '<h2>'.esc_html__('Stripe Customer Balance', 'bank-transfer-payments-for-woocommerce').'</h2>';
        echo '<div class="btpw-customer-balance-section">';

        $testmode = (bool) ($this->gateway?->get_option('testmode') === 'yes');
        $stripeCustomerId = PluginIntegration::getStripeCustomerId($user->ID, $testmode);

        if ($stripeCustomerId === false) {
            echo '<p>'.esc_html__('No Stripe customer ID found for this user.', 'bank-transfer-payments-for-woocommerce').'</p>';
            echo '</div>';

            return;
        }

        try {
            /** @var StripeClient $stripe */
            $stripe = $this->stripe;
            $customer = $stripe->customers->retrieve($stripeCustomerId, ['expand' => ['cash_balance']]);

            echo '<table class="form-table" role="presentation">';

            echo '<tr>';
            echo '<th scope="row">'.esc_html__('Stripe Customer ID', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<td><code>'.esc_html($customer->id).'</code></td>';
            echo '</tr>';

            $this->render_cash_balance_row($customer, $stripeCustomerId);

            if (isset($customer->currency)) {
                echo '<tr>';
                echo '<th scope="row">'.esc_html__('Default Currency', 'bank-transfer-payments-for-woocommerce').'</th>';
                echo '<td>'.esc_html(strtoupper((string) $customer->currency)).'</td>';
                echo '</tr>';
            }

            $mode = $testmode ? 'test/' : '';
            $dashboardUrl = 'https://dashboard.stripe.com/'.$mode.'customers/'.$customer->id;

            echo '<tr>';
            echo '<th scope="row">'.esc_html__('Stripe Dashboard', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<td><a href="'.esc_url($dashboardUrl).'" target="_blank" rel="noopener" class="button">'.esc_html__('View in Stripe', 'bank-transfer-payments-for-woocommerce').' →</a></td>';
            echo '</tr>';

            echo '</table>';

            $this->display_cash_balance_transactions($stripeCustomerId, $user);
        } catch (Throwable $e) {
            echo '<div class="notice notice-error inline"><p>';
            echo esc_html__('Error fetching customer data from Stripe: ', 'bank-transfer-payments-for-woocommerce').esc_html($e->getMessage());
            echo '</p></div>';
        }

        echo '</div>';
    }

    /**
     * Render the cash-balance row, deriving it from the balance or transactions.
     */
    private function render_cash_balance_row(object $customer, string $customerId): void
    {
        if (! isset($customer->cash_balance)) {
            return;
        }

        $shown = false;

        $available = json_decode((string) wp_json_encode($customer->cash_balance->available ?? []), true);
        if (is_array($available)) {
            foreach ($available as $currency => $amount) {
                if ($amount != 0) {
                    $this->render_balance_cell((int) $amount, (string) $currency, __('Available funds from bank transfer payments.', 'bank-transfer-payments-for-woocommerce'));
                    $shown = true;
                }
            }
        }

        if (! $shown) {
            try {
                /** @var StripeClient $stripe */
                $stripe = $this->stripe;
                $transactions = $stripe->customers->allCashBalanceTransactions($customerId, ['limit' => 100]);

                $byCurrency = [];
                foreach ($transactions->data as $transaction) {
                    $currency = $transaction->currency ?? 'usd';
                    $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + ($transaction->net_amount ?? 0);
                }

                foreach ($byCurrency as $currency => $amount) {
                    $this->render_balance_cell((int) $amount, (string) $currency, __('Calculated from transaction history.', 'bank-transfer-payments-for-woocommerce'));
                    $shown = true;
                }
            } catch (Throwable) {
                // Supplementary data; ignore failures.
            }
        }

        if (! $shown) {
            $this->render_balance_cell(0, (string) ($customer->currency ?? 'usd'), __('No funds received yet via bank transfers.', 'bank-transfer-payments-for-woocommerce'));
        }
    }

    private function render_balance_cell(int $amount, string $currency, string $description): void
    {
        $class = $amount < 0 ? 'negative' : ($amount > 0 ? 'positive' : 'zero');

        echo '<tr>';
        echo '<th scope="row">'.esc_html__('Cash Balance (Virtual Bank Account)', 'bank-transfer-payments-for-woocommerce').'</th>';
        echo '<td>';
        echo '<span class="btpw-balance btpw-balance-'.esc_attr($class).'">'.wp_kses_post($this->format_stripe_amount($amount, $currency)).'</span>';
        echo '<p class="description">'.esc_html($description).'</p>';
        echo '</td>';
        echo '</tr>';
    }

    private function display_cash_balance_transactions(string $customerId, WP_User $user): void
    {
        try {
            $this->display_financial_addresses($customerId, $user);

            /** @var StripeClient $stripe */
            $stripe = $this->stripe;
            $transactions = $stripe->customers->allCashBalanceTransactions($customerId, ['limit' => 10]);

            echo '<h3>'.esc_html__('Cash Balance Transactions', 'bank-transfer-payments-for-woocommerce').'</h3>';

            if (empty($transactions->data)) {
                echo '<p>'.esc_html__('No cash balance transactions yet.', 'bank-transfer-payments-for-woocommerce').'</p>';

                return;
            }

            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>'.esc_html__('Date', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<th>'.esc_html__('Type', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<th>'.esc_html__('Amount', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<th>'.esc_html__('Status', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<th>'.esc_html__('Details', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '</tr></thead><tbody>';

            foreach ($transactions->data as $transaction) {
                $typeLabel = ucfirst(str_replace('_', ' ', (string) $transaction->type));
                $class = $transaction->net_amount > 0 ? 'positive' : ($transaction->net_amount < 0 ? 'negative' : 'zero');

                echo '<tr>';
                echo '<td>'.esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), $transaction->created)).'</td>';
                echo '<td>'.esc_html($typeLabel).'</td>';
                echo '<td class="btpw-balance-'.esc_attr($class).'">'.wp_kses_post($this->format_stripe_amount((int) $transaction->net_amount, (string) $transaction->currency)).'</td>';
                echo '<td><span class="btpw-balance btpw-balance-'.esc_attr($class).'">'.esc_html(ucfirst((string) ($transaction->status ?? 'pending'))).'</span></td>';

                $details = '—';
                if (isset($transaction->applied_to_payment)) {
                    $details = __('Applied to payment', 'bank-transfer-payments-for-woocommerce');
                } elseif (isset($transaction->funded)) {
                    $details = __('Funded', 'bank-transfer-payments-for-woocommerce');
                } elseif (isset($transaction->refunded_from_payment)) {
                    $details = __('Refunded from payment', 'bank-transfer-payments-for-woocommerce');
                }
                echo '<td>'.esc_html($details).'</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } catch (Throwable) {
            // Supplementary data; ignore failures.
        }
    }

    private function display_financial_addresses(string $customerId, WP_User $user): bool
    {
        echo '<h3>'.esc_html__('Virtual Bank Account Details', 'bank-transfer-payments-for-woocommerce').'</h3>';

        $hasAddresses = false;

        try {
            /** @var StripeClient $stripe */
            $stripe = $this->stripe;
            $response = $stripe->request('get', '/v1/customers/'.$customerId.'/funding_instructions', [], []);

            if (! empty($response->data)) {
                echo '<p class="description btpw-addresses-intro">'.esc_html__('These are unique bank account details for this customer to receive bank transfers.', 'bank-transfer-payments-for-woocommerce').'</p>';

                foreach ($response->data as $instruction) {
                    if (isset($instruction->bank_transfer->financial_addresses)) {
                        foreach ($instruction->bank_transfer->financial_addresses as $address) {
                            $this->render_financial_address($address);
                            $hasAddresses = true;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // No funding instructions yet or API error.
        }

        if (! $hasAddresses) {
            echo '<p>'.esc_html__('No virtual bank account created yet.', 'bank-transfer-payments-for-woocommerce').'</p>';
            echo '<div class="btpw-create-account">';
            echo '<button type="button" class="button button-primary" id="btpw-create-financial-address" data-customer-id="'.esc_attr($customerId).'" data-user-id="'.esc_attr((string) $user->ID).'">';
            echo esc_html__('Create Virtual Bank Account', 'bank-transfer-payments-for-woocommerce');
            echo '</button>';
            echo '<span class="spinner"></span>';
            echo '<p class="description">'.esc_html__('Generates unique bank account details for this customer to receive transfers.', 'bank-transfer-payments-for-woocommerce').'</p>';
            echo '</div>';
        }

        return $hasAddresses;
    }

    private function render_financial_address(object $address): void
    {
        if (! isset($address->type)) {
            return;
        }

        $rows = $this->financial_address_rows($address);

        if ($rows === []) {
            return;
        }

        echo '<div class="btpw-address-box">';
        echo '<h4>'.esc_html(strtoupper((string) $address->type)).' '.esc_html__('Bank Account', 'bank-transfer-payments-for-woocommerce').'</h4>';
        echo '<table class="widefat btpw-address-table"><tbody>';

        foreach ($rows as [$label, $value, $isCode]) {
            echo '<tr><th>'.esc_html($label).'</th><td>';
            echo $isCode ? '<code>'.esc_html($value).'</code>' : esc_html($value);
            echo '</td></tr>';
        }

        if (isset($address->supported_networks) && is_array($address->supported_networks)) {
            echo '<tr><th>'.esc_html__('Supported Networks', 'bank-transfer-payments-for-woocommerce').'</th>';
            echo '<td>'.esc_html(implode(', ', array_map('strtoupper', $address->supported_networks))).'</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Build label/value/is-code rows for a funding address by type.
     *
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    private function financial_address_rows(object $address): array
    {
        $type = (string) $address->type;
        $data = $address->{$type} ?? null;

        if ($data === null) {
            return [];
        }

        return match ($type) {
            'aba' => [
                [__('Account Number', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_number ?? '—'), true],
                [__('Routing Number', 'bank-transfer-payments-for-woocommerce'), (string) ($data->routing_number ?? '—'), true],
                [__('Bank Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bank_name ?? '—'), false],
                [__('Account Holder Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_holder_name ?? '—'), false],
            ],
            'iban' => [
                [__('IBAN', 'bank-transfer-payments-for-woocommerce'), (string) ($data->iban ?? '—'), true],
                [__('BIC/SWIFT', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bic ?? $data->swift_code ?? $data->swift ?? '—'), true],
                [__('Bank Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bank_name ?? '—'), false],
                [__('Account Holder Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_holder_name ?? '—'), false],
            ],
            'sort_code' => [
                [__('Account Number', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_number ?? '—'), true],
                [__('Sort Code', 'bank-transfer-payments-for-woocommerce'), (string) ($data->sort_code ?? '—'), true],
                [__('Bank Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bank_name ?? '—'), false],
                [__('Account Holder Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_holder_name ?? '—'), false],
            ],
            'spei' => [
                [__('CLABE', 'bank-transfer-payments-for-woocommerce'), (string) ($data->clabe ?? '—'), true],
                [__('Bank Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bank_name ?? '—'), false],
                [__('Account Holder Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_holder_name ?? '—'), false],
            ],
            'zengin' => [
                [__('Account Number', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_number ?? '—'), true],
                [__('Bank Code', 'bank-transfer-payments-for-woocommerce'), (string) ($data->bank_code ?? '—'), true],
                [__('Branch Code', 'bank-transfer-payments-for-woocommerce'), (string) ($data->branch_code ?? '—'), true],
                [__('Account Holder Name', 'bank-transfer-payments-for-woocommerce'), (string) ($data->account_holder_name ?? '—'), false],
            ],
            default => [],
        };
    }

    /**
     * REST callback: create a virtual bank account (funding instructions) for
     * a customer. Capability is enforced by the route's permission_callback.
     */
    public function create_financial_address(WP_REST_Request $request): WP_REST_Response
    {
        $customerId = sanitize_text_field((string) $request->get_param('customer_id'));

        if ($customerId === '') {
            return new WP_REST_Response(['message' => __('Customer ID is required', 'bank-transfer-payments-for-woocommerce')], 400);
        }

        if (! $this->init_stripe() || $this->stripe === null || $this->gateway === null) {
            return new WP_REST_Response(['message' => __('Stripe connection failed', 'bank-transfer-payments-for-woocommerce')], 500);
        }

        try {
            $transferType = (string) $this->gateway->get_option('transfer_type', 'eu_bank_account');
            $currency = (string) $this->gateway->get_option('default_currency', 'eur');

            /** @var StripeClient $stripe */
            $stripe = $this->stripe;
            $response = $stripe->request('post', '/v1/customers/'.$customerId.'/funding_instructions', [
                'bank_transfer' => ['type' => $transferType],
                'currency' => $currency,
                'funding_type' => 'bank_transfer',
            ], []);

            return new WP_REST_Response([
                'message' => __('Virtual bank account created successfully', 'bank-transfer-payments-for-woocommerce'),
                'funding_instructions' => $response->id ?? null,
            ], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['message' => $e->getMessage()], 502);
        }
    }

    private function format_stripe_amount(int $amount, string $currency = 'usd'): string
    {
        return wc_price($amount / 100, ['currency' => strtoupper($currency)]);
    }

    private function get_gateway_instance(): ?object
    {
        if (! function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        return $gateways['stripe_bank_transfer'] ?? null;
    }
}
