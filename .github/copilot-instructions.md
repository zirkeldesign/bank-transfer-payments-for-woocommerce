# Copilot Instructions: WooCommerce Stripe Bank Transfers

## Project Overview
This is a WooCommerce payment gateway plugin that integrates Stripe bank transfer payments into WooCommerce checkout flows.

## Architecture & Structure

### Core Components (Expected)
- **Payment Gateway Class**: Extends `WC_Payment_Gateway` to implement bank transfer payment method
- **Webhook Handler**: Processes Stripe webhook events for bank transfer confirmations/status updates
- **Admin Settings**: Configuration interface in WooCommerce → Settings → Payments
- **Order Management**: Custom order statuses and meta data for tracking bank transfer states

### WordPress/WooCommerce Conventions
- Main plugin file: `woocommerce-stripe-bank-transfers.php` (or similar) with WordPress plugin headers
- Classes in `/includes/` or `/src/` directory with PSR-4 autoloading or manual requires
- Assets (JS/CSS) in `/assets/` with separate subdirectories for admin and frontend
- Use WordPress coding standards (WPCS) and WooCommerce naming conventions

## Development Workflows

### WordPress Plugin Setup
```bash
# Plugin typically loaded via WordPress wp-content/plugins directory
# For local development, symlink to WordPress install:
ln -s /path/to/this/repo /path/to/wordpress/wp-content/plugins/woocommerce-stripe-bank-transfers
```

### Dependencies
- **PHP**: `composer install` - Includes Stripe PHP SDK (`stripe/stripe-php`)
- **JavaScript**: `bun install` - For frontend/admin asset building
- WooCommerce must be active as a dependency

### Testing & Code Quality
```bash
composer test              # Run PEST tests
composer test:coverage     # Run tests with coverage
composer lint              # Check code style with Pint (Laravel preset)
composer format            # Auto-fix code style with Pint
bun run lint              # Lint JavaScript/TypeScript
bun run format            # Format JS/TS with Prettier
```

### Activation/Deactivation
- Implement activation hooks for creating custom tables or default options
- Implement deactivation hooks for cleanup (avoid deleting user data)

## Critical Patterns

### Payment Gateway Implementation
```php
class WC_Gateway_Stripe_Bank_Transfer extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'stripe_bank_transfer';
        $this->method_title = __('Stripe Bank Transfer', 'text-domain');
        $this->has_fields = true;
        // Initialize settings, hooks, etc.
    }
    
    public function process_payment($order_id) {
        // Create Stripe payment intent with bank transfer method
        // Update order status to 'on-hold' or 'pending'
        // Return success array with redirect
    }
}
```

### Webhook Security
- Always verify Stripe webhook signatures using `\Stripe\Webhook::constructEvent()`
- Log all webhook events for debugging
- Use WordPress transients to prevent duplicate processing

### WooCommerce Integration Points
- Hook into `woocommerce_payment_gateways` filter to register gateway
- Use `woocommerce_order_status_*` actions for status transitions
- Store Stripe data in order meta using `$order->update_meta_data()`

### Security Best Practices
- Sanitize all input with `sanitize_text_field()`, `sanitize_email()`, etc.
- Escape all output with `esc_html()`, `esc_url()`, `wp_kses()`, etc.
- Use nonces for all form submissions: `wp_nonce_field()` and `wp_verify_nonce()`
- Store API keys encrypted using WordPress options with `_transient` for sensitive temp data

### Internationalization
- Text domain should match plugin slug
- Use `__()`, `_e()`, `_n()`, `_x()` functions for translatable strings
- Load text domain in plugin initialization: `load_plugin_textdomain()`

## Testing Strategies

### Manual Testing
- Test in WordPress test mode with Stripe test API keys
- Use Stripe CLI for webhook testing: `stripe listen --forward-to localhost/wp-json/wc/v3/stripe-bank-transfer/webhook`
- Test with various WooCommerce order scenarios (virtual, physical, subscription compatibility)

### Stripe Test Data
- Use Stripe test bank account numbers for different scenarios
- Test various webhook events: `payment_intent.succeeded`, `payment_intent.payment_failed`, etc.

## Common Pitfalls

### WordPress Specific
- Never use PHP sessions in WordPress; use transients or user meta instead
- Always check `is_admin()` and `is_ajax()` contexts before loading admin-only code
- Enqueue scripts properly with `wp_enqueue_script()` and declare dependencies
- Use WordPress HTTP API (`wp_remote_post()`) instead of cURL for API calls

### WooCommerce Specific
- Always check if WooCommerce is active before initializing gateway
- Use `wc_get_order()` instead of direct database queries
- Respect WooCommerce order statuses workflow
- Handle both manual and automatic order processing modes

### Stripe API
- Always catch Stripe exceptions and log appropriately
- Set API version explicitly to prevent breaking changes
- Use idempotency keys for payment operations
- Implement retry logic for network failures

## Key Files to Reference

### Core Components
- **woocommerce-stripe-bank-transfers.php** - Main plugin file with activation hooks and initialization
- **includes/class-wc-gateway-stripe-bank-transfer.php** - Payment gateway implementation extending `WC_Payment_Gateway`
- **includes/class-stripe-webhook-handler.php** - REST API webhook handler for Stripe events
- **includes/class-stripe-integration.php** - Integration layer for existing Stripe plugins
- **includes/class-customer-balance-display.php** - Customer balance display in admin user profiles

### Integration Features
- Automatically detects and reuses credentials from:
  - Payment Plugins for Stripe WooCommerce (`woo-stripe-payment`)
    - Uses meta keys: `{prefix}wc_stripe_customer_live` / `{prefix}wc_stripe_customer_test`
    - Checks both with and without table prefix
  - WooCommerce Stripe Gateway (`woocommerce-gateway-stripe`)
- Displays Stripe customer balance in WordPress user profiles (admin only)
- Shows recent balance transactions in user profile
- Customer ID detection checks multiple meta key formats for compatibility

## Testing Strategy

### Unit Tests (PEST)
- Located in `tests/Unit/`
- Test individual classes and methods
- Use PEST's expressive syntax: `expect($value)->toBe()`
- Run with `composer test`

### Feature Tests (PEST)
- Located in `tests/Feature/`
- Test complete workflows (checkout, webhooks, etc.)
- Mock Stripe API responses

### Code Style
- Use Laravel Pint for PHP formatting (Laravel preset)
- Configuration in `pint.json`
- Auto-fix with `composer format`

## Project Decisions

✅ **Implemented**:
- Supports ACH, SEPA, Bacs, SPEI, and Japanese bank transfers
- Integrates with existing Stripe plugins (woo-stripe-payment, woocommerce-gateway-stripe)
- Uses Composer for Stripe PHP SDK
- Uses Bun for JavaScript dependencies
- PEST for testing, Pint for code style
- Customer balance display in admin profiles
