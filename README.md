# WooCommerce Stripe Bank Transfers

A WordPress/WooCommerce payment gateway plugin that integrates Stripe bank transfer payments. Customers receive unique bank account details for each order.

## Features

- ✅ Supports multiple bank transfer types (ACH, SEPA, Bacs, SPEI, Japanese bank accounts)
- ✅ Unique bank account details generated for each order
- ✅ **Integrates with existing Stripe plugins** - automatically detects and reuses credentials
- ✅ **Customer balance display** in WordPress admin user profiles
- ✅ Automatic payment confirmation via Stripe webhooks
- ✅ Custom order status: "Awaiting Bank Transfer"
- ✅ Bank transfer details displayed on thank you page and in emails
- ✅ Test mode support
- ✅ Debug logging
- ✅ Fully translatable

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- Stripe account with bank transfer payment methods enabled

## Installation

### From Source

1. Clone this repository into your WordPress plugins directory:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/yourusername/woocommerce-stripe-bank-transfers.git
   ```

2. Install dependencies:
   ```bash
   cd woocommerce-stripe-bank-transfers
   composer install --no-dev
   ```

3. Activate the plugin through the WordPress admin panel

### For Development

1. Clone and install dependencies:
   ```bash
   git clone https://github.com/yourusername/woocommerce-stripe-bank-transfers.git
   cd woocommerce-stripe-bank-transfers
   composer install
   ```

2. Symlink to your WordPress installation:
   ```bash
   ln -s $(pwd) /path/to/wordpress/wp-content/plugins/woocommerce-stripe-bank-transfers
   ```

## Configuration

### Existing Stripe Plugin Integration

If you already have one of these Stripe plugins installed and configured, this plugin will automatically detect and use their Stripe credentials:

- **Payment Plugins for Stripe WooCommerce** (`woo-stripe-payment`)
- **WooCommerce Stripe Gateway** (`woocommerce-gateway-stripe`)

You'll see a notice on the settings page indicating which plugin's credentials are being used. You can still override by configuring your own API keys.

### Manual Configuration

1. Navigate to **WooCommerce → Settings → Payments**
2. Enable **Stripe Bank Transfer**
3. Click **Manage** to configure:
   - **Test Mode**: Enable for testing with Stripe test API keys
   - **Test/Live Secret Keys**: Add your Stripe API keys
   - **Bank Transfer Type**: Select the type of bank transfer (ACH, SEPA, etc.)
   - **Debug Mode**: Enable logging for troubleshooting

4. Set up webhooks in Stripe:
   - Go to Stripe Dashboard → Developers → Webhooks
   - Add endpoint: `https://yoursite.com/wp-json/wc-stripe-bank-transfers/v1/webhook`
   - Select these events:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `payment_intent.canceled`
     - `payment_intent.processing`
     - `payment_intent.requires_action`
   - Copy the webhook signing secret
   - Add it to the plugin settings (webhook_secret field - needs to be added to gateway settings)

## How It Works

1. **Customer Checkout**: Customer selects "Bank Transfer" as payment method
2. **Order Placement**: Order is created with "Awaiting Bank Transfer" status
3. **Bank Details**: Stripe generates unique bank account details for the order
4. **Payment Instructions**: Customer receives bank transfer instructions on:
   - Order thank you page
   - Order confirmation email
5. **Payment Confirmation**: When customer transfers funds:
   - Stripe webhook notifies the plugin
   - Order status automatically updates to "Processing"
   - Customer receives order confirmation

## Customer Balance Display

Administrators and shop managers can view Stripe customer balances directly in WordPress:

1. Go to **Users → All Users** and click on any user
2. Scroll down to the **Stripe Customer Balance** section
3. View:
   - Stripe Customer ID
   - Current balance
   - Available cash balance
   - Recent balance transactions
   - Direct link to Stripe Dashboard

This feature works with any customer who has a Stripe customer ID, including those created by other Stripe plugins.

## Bank Transfer Types Supported

- **US Bank Account (ACH)**: US domestic bank transfers
- **EU Bank Account (SEPA)**: European bank transfers
- **UK Bank Account (Bacs)**: UK bank transfers
- **Mexican Bank Account (SPEI)**: Mexican bank transfers
- **Japanese Bank Account**: Japanese bank transfers

## Development

### Testing Webhooks Locally

Use Stripe CLI to forward webhooks to your local environment:

```bash
stripe listen --forward-to http://localhost:8888/wp-json/wc-stripe-bank-transfers/v1/webhook
```

### Code Standards

This plugin follows WordPress Coding Standards and uses Laravel Pint for formatting:

```bash
# Check code style
composer lint

# Auto-fix issues
composer format

# Run tests
composer test

# Run tests with coverage
composer test:coverage

# JavaScript/TypeScript
bun run lint
bun run format
```

### Directory Structure

```
woocommerce-stripe-bank-transfers/
├── includes/
│   ├── class-wc-gateway-stripe-bank-transfer.php  # Payment gateway class
│   ├── class-stripe-webhook-handler.php            # Webhook handler
│   ├── class-stripe-integration.php                # Existing plugin integration
│   └── class-customer-balance-display.php          # Admin balance display
├── tests/
│   ├── Unit/                                       # Unit tests (PEST)
│   ├── Feature/                                    # Feature tests (PEST)
│   └── Pest.php                                    # PEST configuration
├── languages/                                       # Translation files
├── woocommerce-stripe-bank-transfers.php           # Main plugin file
├── composer.json                                    # PHP dependencies
├── package.json                                     # JavaScript dependencies (Bun)
├── pint.json                                        # Laravel Pint config
├── phpunit.xml                                      # PHPUnit config
└── README.md
```

## API Reference

### Webhook Endpoint

```
POST /wp-json/wc-stripe-bank-transfers/v1/webhook
```

Handles Stripe webhook events for payment status updates.

### Order Meta Keys

- `_stripe_payment_intent_id`: Stripe PaymentIntent ID
- `_stripe_payment_intent_status`: Current PaymentIntent status
- `_stripe_bank_transfer_details`: JSON-encoded bank account details

## Troubleshooting

### Enable Debug Logging

1. Go to plugin settings
2. Enable "Debug Mode"
3. View logs at **WooCommerce → Status → Logs**
4. Look for files starting with `stripe-bank-transfer`

### Common Issues

**Bank transfer details not showing**
- Check that the PaymentIntent was created successfully
- Verify the Stripe API version (should be 2023-10-16 or later)
- Enable debug logging and check for errors

**Webhooks not working**
- Verify webhook URL is accessible from the internet
- Check webhook signing secret is configured correctly
- Use Stripe CLI to test webhook delivery locally

**Payment not confirmed**
- Check webhook events are configured in Stripe Dashboard
- Verify webhook endpoint is receiving events (check logs)
- Ensure order has the correct PaymentIntent ID in meta data

## Security

- Never commit your Stripe API keys to version control
- Use environment variables or wp-config.php constants for sensitive data
- Always verify webhook signatures in production
- Keep the Stripe PHP library updated

## Support

For issues and questions:
- Create an issue on GitHub
- Check Stripe documentation: https://docs.stripe.com/payments/bank-transfers

## License

GPL-3.0-or-later

## Credits

Built with [Stripe PHP SDK](https://github.com/stripe/stripe-php)
