<?php

declare(strict_types=1);

/**
 * Boots a real WordPress so the Integration suite can exercise the gateway
 * against the live Stripe test API rather than a fake client.
 *
 * Provision the install first: ./scripts/integration-env.sh
 *
 * The Stripe key is read from BTPW_STRIPE_TEST_KEY, or from a gitignored
 * .stripe-test-key file in the plugin root — never from the repository.
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

$wpRoot = getenv('BTPW_WP_ROOT') ?: dirname(__DIR__).'/.wp-integration';

if (! file_exists($wpRoot.'/wp-load.php')) {
    fwrite(STDERR, sprintf("WordPress not found at %s.\nRun ./scripts/integration-env.sh first.\n", $wpRoot));
    exit(1);
}

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/';
$_SERVER['REQUEST_METHOD'] ??= 'GET';

define('WP_USE_THEMES', false);

require_once $wpRoot.'/wp-load.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active in the integration install.\n");
    exit(1);
}

/**
 * The Stripe secret key for the integration suite, or '' when none is set.
 *
 * Refuses anything that is not a test key: these tests create real API objects,
 * and pointing them at a live account would create real payment intents
 * against real customers.
 */
function btpw_stripe_test_key(): string
{
    $key = (string) getenv('BTPW_STRIPE_TEST_KEY');

    if ($key === '') {
        $file = dirname(__DIR__).'/.stripe-test-key';
        $key = is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    if ($key === '') {
        return '';
    }

    if (! str_starts_with($key, 'sk_test_')) {
        fwrite(STDERR, "Refusing to run: BTPW_STRIPE_TEST_KEY is not a test key (expected sk_test_…).\n");
        exit(1);
    }

    return $key;
}

/**
 * Configure the gateway with the test key and return a fresh instance.
 */
function btpw_live_gateway(string $transferType = 'eu_bank_transfer'): \ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway
{
    update_option('woocommerce_stripe_bank_transfer_settings', [
        'enabled' => 'yes',
        'testmode' => 'yes',
        'test_secret_key' => btpw_stripe_test_key(),
        'transfer_type' => $transferType,
        'default_currency' => 'eur',
        'debug_mode' => 'no',
    ]);

    return new \ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;
}
