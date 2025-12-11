<?php

/**
 * Stripe Plugin Integration
 *
 * Detects and integrates with existing Stripe plugins to reuse credentials and SDK.
 *
 * @class       WC_Stripe_Plugin_Integration
 */
if (! defined('ABSPATH')) {
    exit;
}

class WC_Stripe_Plugin_Integration
{
    /**
     * Detected plugin
     *
     * @var string|null
     */
    private static $detected_plugin = null;

    /**
     * Cached credentials
     *
     * @var array|null
     */
    private static $credentials = null;

    /**
     * Detect active Stripe plugins
     *
     * @return string|null Plugin identifier or null if none found
     */
    public static function detect_stripe_plugin()
    {
        if (self::$detected_plugin !== null) {
            return self::$detected_plugin;
        }

        // Check for Payment Plugins for Stripe WooCommerce (woo-stripe-payment)
        if (class_exists('WC_Stripe_Manager') || defined('WC_STRIPE_VERSION')) {
            self::$detected_plugin = 'woo-stripe-payment';

            return self::$detected_plugin;
        }

        // Check for WooCommerce Stripe Gateway (woocommerce-gateway-stripe)
        if (class_exists('WC_Stripe') || class_exists('WC_Gateway_Stripe')) {
            self::$detected_plugin = 'woocommerce-gateway-stripe';

            return self::$detected_plugin;
        }

        self::$detected_plugin = false;

        return self::$detected_plugin;
    }

    /**
     * Get Stripe credentials from existing plugin
     *
     * @param  bool  $testmode  Whether to get test mode credentials
     * @return array|false Array with 'secret_key' and 'publishable_key' or false
     */
    public static function get_stripe_credentials($testmode = false)
    {
        if (self::$credentials !== null) {
            return self::$credentials;
        }

        $plugin = self::detect_stripe_plugin();

        if (! $plugin) {
            return false;
        }

        $credentials = false;

        switch ($plugin) {
            case 'woo-stripe-payment':
                $credentials = self::get_woo_stripe_payment_credentials($testmode);
                break;

            case 'woocommerce-gateway-stripe':
                $credentials = self::get_woocommerce_gateway_stripe_credentials($testmode);
                break;
        }

        self::$credentials = $credentials;

        /**
         * Filter Stripe credentials
         *
         * @param  array|false  $credentials  Array with 'secret_key', 'publishable_key', 'source' or false
         * @param  bool  $testmode  Whether test mode credentials are requested
         * @param  string|false  $plugin  Detected plugin identifier
         */
        return apply_filters('wc_stripe_bank_transfers_credentials', self::$credentials, $testmode, $plugin);
    }

    /**
     * Get credentials from Payment Plugins for Stripe WooCommerce
     *
     * @param  bool  $testmode
     * @return array|false
     */
    private static function get_woo_stripe_payment_credentials($testmode)
    {
        // This plugin stores settings in wp_options
        $mode = $testmode ? 'test' : 'live';

        // Try to get settings from options
        $secret_key = get_option("_stripe_{$mode}_secret_key");
        $publishable_key = get_option("_stripe_{$mode}_publishable_key");

        // Alternative: Check gateway settings
        if (! $secret_key) {
            $gateway_settings = get_option('woocommerce_stripe_cc_settings', []);

            if ($testmode) {
                $secret_key = $gateway_settings['test_secret_key'] ?? '';
                $publishable_key = $gateway_settings['test_publishable_key'] ?? '';
            } else {
                $secret_key = $gateway_settings['secret_key'] ?? '';
                $publishable_key = $gateway_settings['publishable_key'] ?? '';
            }
        }

        if ($secret_key) {
            return [
                'secret_key' => $secret_key,
                'publishable_key' => $publishable_key,
                'source' => 'woo-stripe-payment',
            ];
        }

        return false;
    }

    /**
     * Get credentials from WooCommerce Stripe Gateway
     *
     * @param  bool  $testmode
     * @return array|false
     */
    private static function get_woocommerce_gateway_stripe_credentials($testmode)
    {
        // This plugin stores settings in woocommerce_stripe_settings option
        $settings = get_option('woocommerce_stripe_settings', []);

        if (empty($settings)) {
            return false;
        }

        $test_mode = isset($settings['testmode']) && $settings['testmode'] === 'yes';

        if ($testmode) {
            $secret_key = $settings['test_secret_key'] ?? '';
            $publishable_key = $settings['test_publishable_key'] ?? '';
        } else {
            $secret_key = $settings['secret_key'] ?? '';
            $publishable_key = $settings['publishable_key'] ?? '';
        }

        if ($secret_key) {
            return [
                'secret_key' => $secret_key,
                'publishable_key' => $publishable_key,
                'source' => 'woocommerce-gateway-stripe',
            ];
        }

        return false;
    }

    /**
     * Get Stripe SDK instance (reuse if possible)
     *
     * @param  bool  $testmode
     * @return \Stripe\StripeClient|false
     */
    public static function get_stripe_client($testmode = false)
    {
        $credentials = self::get_stripe_credentials($testmode);

        if (! $credentials || empty($credentials['secret_key'])) {
            return false;
        }

        try {
            return new \Stripe\StripeClient([
                'api_key' => $credentials['secret_key'],
                'stripe_version' => '2023-10-16',
            ]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if we should use existing plugin credentials
     *
     * @param  WC_Gateway_Stripe_Bank_Transfer  $gateway
     * @return bool
     */
    public static function should_use_existing_credentials($gateway)
    {
        // If user has configured their own keys, use those
        if ($gateway->testmode && ! empty($gateway->test_secret_key)) {
            return false;
        }

        if (! $gateway->testmode && ! empty($gateway->live_secret_key)) {
            return false;
        }

        // Check if existing plugin is detected
        $plugin = self::detect_stripe_plugin();

        return $plugin !== false;
    }

    /**
     * Get integration notice for admin
     *
     * @return string|false HTML notice or false
     */
    public static function get_integration_notice()
    {
        $plugin = self::detect_stripe_plugin();

        if (! $plugin) {
            return false;
        }

        $credentials = self::get_stripe_credentials();

        if (! $credentials) {
            return false;
        }

        $plugin_name = $plugin === 'woo-stripe-payment'
            ? 'Payment Plugins for Stripe WooCommerce'
            : 'WooCommerce Stripe Gateway';

        $message = sprintf(
            __('Detected %s plugin. Using Stripe credentials from that plugin.', 'wc-stripe-bank-transfers'),
            '<strong>' . esc_html($plugin_name) . '</strong>'
        );

        return '<div class="notice notice-info inline"><p>' . $message . '</p></div>';
    }

    /**
     * Clear cached credentials (useful after settings update)
     */
    public static function clear_cache()
    {
        self::$credentials = null;
        self::$detected_plugin = null;
    }

    /**
     * Get Stripe customer ID for a WordPress user
     *
     * @param  int  $user_id  WordPress user ID
     * @param  bool  $testmode  Whether to get test mode customer ID
     * @return string|false Customer ID or false if not found
     */
    public static function get_stripe_customer_id($user_id, $testmode = false)
    {
        // Detect which Stripe plugin is active
        $active_plugin = self::detect_stripe_plugin();

        // If a Stripe plugin is detected, check its meta keys first
        if ($active_plugin) {
            $customer_id = match ($active_plugin) {
                'woo-stripe-payment' => self::get_payment_plugins_customer_id($user_id, $testmode),
                'woocommerce-gateway-stripe' => self::get_wc_stripe_gateway_customer_id($user_id),
                default => false,
            };

            if ($customer_id) {
                return $customer_id;
            }
        }

        // Fallback: Try generic meta key
        $customer_id = get_user_meta($user_id, '_stripe_customer_id', true);
        if ($customer_id) {
            return $customer_id;
        }

        // Last resort: Try to find from customer orders
        $customer_id = self::get_customer_id_from_orders($user_id);

        /**
         * Filter Stripe customer ID
         *
         * @param  string|false  $customer_id  Stripe customer ID or false if not found
         * @param  int  $user_id  WordPress user ID
         * @param  bool  $testmode  Whether test mode customer ID is requested
         * @param  string|false  $active_plugin  Detected plugin identifier
         */
        return apply_filters('wc_stripe_bank_transfers_customer_id', $customer_id, $user_id, $testmode, $active_plugin);
    }

    /**
     * Get customer ID from Payment Plugins for Stripe WooCommerce
     *
     * @param  int  $user_id
     * @param  bool  $testmode
     * @return string|false
     */
    private static function get_payment_plugins_customer_id($user_id, $testmode)
    {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $meta_key_suffix = $testmode ? 'test' : 'live';

        // Check with table prefix first (e.g., wpzd_wc_stripe_customer_live)
        $customer_id = get_user_meta($user_id, $table_prefix . 'wc_stripe_customer_' . $meta_key_suffix, true);
        if ($customer_id) {
            return $customer_id;
        }

        // Check without prefix (wc_stripe_customer_live)
        $customer_id = get_user_meta($user_id, 'wc_stripe_customer_' . $meta_key_suffix, true);
        if ($customer_id) {
            return $customer_id;
        }

        return false;
    }

    /**
     * Get customer ID from WooCommerce Stripe Gateway
     *
     * @param  int  $user_id
     * @return string|false
     */
    private static function get_wc_stripe_gateway_customer_id($user_id)
    {
        // WooCommerce Stripe Gateway uses _stripe_customer_id
        $customer_id = get_user_meta($user_id, '_stripe_customer_id', true);

        if ($customer_id) {
            return $customer_id;
        }

        // Also try legacy key
        $customer_id = get_user_meta($user_id, '_wc_stripe_customer_id', true);
        if ($customer_id) {
            return $customer_id;
        }

        return false;
    }

    /**
     * Get customer ID from user's orders
     *
     * @param  int  $user_id
     * @return string|false
     */
    private static function get_customer_id_from_orders($user_id)
    {
        if (! function_exists('wc_get_orders')) {
            return false;
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        foreach ($orders as $order) {
            $customer_id = $order->get_meta('_stripe_customer_id');
            if ($customer_id) {
                // Cache it for future use
                update_user_meta($user_id, '_stripe_customer_id', $customer_id);

                return $customer_id;
            }
        }

        return false;
    }
}
