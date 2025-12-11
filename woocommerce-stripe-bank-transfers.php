<?php

/**
 * Plugin Name: WooCommerce Stripe Bank Transfers
 * Plugin URI: https://github.com/yourusername/woocommerce-stripe-bank-transfers
 * Description: Accept bank transfer payments via Stripe. Customers receive individual bank account details for each order.
 * Version: 1.0.0
 * Author: zirkel.design
 * Author URI: https://zirkel.design
 * Text Domain: wc-stripe-bank-transfers
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 * WC tested up to: 10.4
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WC_STRIPE_BANK_TRANSFERS_VERSION', '1.0.0');
define('WC_STRIPE_BANK_TRANSFERS_PLUGIN_FILE', __FILE__);
define('WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_STRIPE_BANK_TRANSFERS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (! in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>' . esc_html__('WooCommerce Stripe Bank Transfers', 'wc-stripe-bank-transfers') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'wc-stripe-bank-transfers') . '</p></div>';
    });

    return;
}

/**
 * Load Composer autoloader
 */
if (file_exists(WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Initialize the payment gateway
 */
function wc_stripe_bank_transfers_init()
{
    // Check if WooCommerce is loaded
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * Load plugin classes after WooCommerce is available
     */
    require_once WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'includes/class-stripe-integration.php';
    require_once WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'includes/class-wc-gateway-stripe-bank-transfer.php';
    require_once WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'includes/class-stripe-webhook-handler.php';
    require_once WC_STRIPE_BANK_TRANSFERS_PLUGIN_DIR . 'includes/class-customer-balance-display.php';

    /**
     * Add the gateway to WooCommerce
     */
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Stripe_Bank_Transfer';

        return $gateways;
    });

    /**
     * Initialize webhook handler
     */
    add_action('rest_api_init', function () {
        $webhook_handler = new WC_Stripe_Webhook_Handler;
        $webhook_handler->register_routes();
    });

    /**
     * Initialize customer balance display
     */
    new WC_Stripe_Customer_Balance_Display;

    /**
     * Add custom order statuses for bank transfer workflow
     */
    add_action('init', function () {
        register_post_status('wc-awaiting-transfer', [
            'label' => _x('Awaiting Bank Transfer', 'Order status', 'wc-stripe-bank-transfers'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Awaiting bank transfer <span class="count">(%s)</span>', 'Awaiting bank transfer <span class="count">(%s)</span>', 'wc-stripe-bank-transfers'),
        ]);
    });

    /**
     * Add custom order status to WooCommerce
     */
    add_filter('wc_order_statuses', function ($order_statuses) {
        $new_statuses = [];

        foreach ($order_statuses as $key => $status) {
            $new_statuses[$key] = $status;
            if ($key === 'wc-on-hold') {
                $new_statuses['wc-awaiting-transfer'] = _x('Awaiting Bank Transfer', 'Order status', 'wc-stripe-bank-transfers');
            }
        }

        return $new_statuses;
    });
}

add_action('plugins_loaded', 'wc_stripe_bank_transfers_init', 11);

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Plugin activation
 */
register_activation_hook(__FILE__, function () {
    // Check for WooCommerce
    if (! class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('WooCommerce Stripe Bank Transfers requires WooCommerce to be installed and active.', 'wc-stripe-bank-transfers'),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Flush rewrite rules for REST API endpoints
    flush_rewrite_rules();
});

/**
 * Plugin deactivation
 */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/**
 * Load plugin text domain
 */
add_action('init', function () {
    load_plugin_textdomain('wc-stripe-bank-transfers', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Add settings link on plugin page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=stripe_bank_transfer');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'wc-stripe-bank-transfers') . '</a>';
    array_unshift($links, $settings_link);

    return $links;
});
