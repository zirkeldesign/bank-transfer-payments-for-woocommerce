# Quick Start Guide

## Installation

1. **Install PHP dependencies:**
   ```bash
   composer install
   ```

2. **Install JavaScript dependencies (optional):**
   ```bash
   bun install
   ```

3. **Symlink to WordPress (for development):**
   ```bash
   # Replace with your WordPress path
   ln -s $(pwd) /path/to/wordpress/wp-content/plugins/woocommerce-stripe-bank-transfers
   ```

4. **Activate the plugin** in WordPress admin

## Configuration

### Option 1: Use Existing Stripe Plugin (Recommended)

If you already have one of these plugins installed:
- Payment Plugins for Stripe WooCommerce
- WooCommerce Stripe Gateway

Just activate this plugin and it will automatically use their Stripe credentials!

### Option 2: Manual Configuration

1. Go to **WooCommerce → Settings → Payments**
2. Enable **Stripe Bank Transfer**
3. Click **Manage**
4. Add your Stripe API keys
5. Select bank transfer type (ACH, SEPA, Bacs, etc.)

## Set Up Webhooks

1. Go to [Stripe Dashboard → Webhooks](https://dashboard.stripe.com/webhooks)
2. Click **Add endpoint**
3. Enter URL: `https://yoursite.com/wp-json/wc-stripe-bank-transfers/v1/webhook`
4. Select events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payment_intent.canceled`
   - `payment_intent.processing`
   - `payment_intent.requires_action`
5. Copy the **Signing secret**
6. Add it to plugin settings under **Webhook Secret**

## Testing Locally

Use Stripe CLI to test webhooks:

```bash
stripe listen --forward-to http://localhost:8888/wp-json/wc-stripe-bank-transfers/v1/webhook
```

## Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Check code style
composer lint

# Auto-fix code style
composer format
```

## Verify Installation

1. Create a test order with bank transfer payment
2. Check the thank you page shows bank details
3. Check order status is "Awaiting Bank Transfer"
4. View customer balance in user profile (admin only)

## Troubleshooting

Enable debug mode in plugin settings and check logs at:
**WooCommerce → Status → Logs** (look for `stripe-bank-transfer-*` files)

## Quick Links

- [Stripe Bank Transfers Documentation](https://docs.stripe.com/payments/bank-transfers)
- [Stripe Test Data](https://stripe.com/docs/testing)
- [WooCommerce Payment Gateway API](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html)
