# WooCommerce Stripe Bank Transfers - Changelog

## [1.0.0] - 2025-12-11

### Added
- Initial release
- Support for Stripe bank transfer payment methods (ACH, SEPA, Bacs, SPEI, Japanese)
- Unique bank account details for each order
- Custom order status: "Awaiting Bank Transfer"
- Webhook handler for payment confirmations
- Integration with existing Stripe plugins:
  - Payment Plugins for Stripe WooCommerce (woo-stripe-payment)
  - WooCommerce Stripe Gateway (woocommerce-gateway-stripe)
- Customer balance display in WordPress admin user profiles
- Test mode support
- Debug logging
- PEST test framework
- Laravel Pint code formatting
- Bun support for JavaScript dependencies

### Developer Features
- Automatic credential detection from existing Stripe plugins
- REST API webhook endpoint: `/wp-json/wc-stripe-bank-transfers/v1/webhook`
- Order meta keys for Stripe data storage
- Comprehensive logging system
