# Changelog

All notable changes to this project are documented here. This file is for
developers; the end-user changelog lives in `readme.txt`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- `translate:pot` now scans `templates/` as well. It only looked at the plugin file
  and `src/`, so every string in the awaiting-transfer email templates was dropped
  from the POT, and their German translations were deleted from both locales on any
  `bun run translate`. Latent since the email templates were added.
- The GiroCode no longer falls back to the shop name when Stripe omits
  `account_holder_name`. Encoding a beneficiary the IBAN is not registered under
  guarantees a Verification of Payee mismatch on a payment we generated ourselves,
  so the QR code is now dropped instead and the customer uses the details table.

### Changed
- Tested up to WordPress 7.1. Dev-only toolchain updated to match: `wordpress-stubs`
  7.1, `woocommerce-stubs` 11.0, PHPStan 2.2.10. None of these ship in the plugin zip.
- "Account Holder Name" is now the first row for SEPA (`iban`) and Bacs
  (`sort_code`) addresses. Since VoP became mandatory on 9 October 2025 it is the
  field the customer must transcribe most carefully.
- SEPA payment instructions carry a Verification of Payee note explaining that the
  payer's bank checks the recipient name against the IBAN, and that the payee of
  record is the Stripe business name rather than the shop name. Filterable via
  `btpw_vop_notice`; shown on the thank-you page and in both HTML and plain-text
  emails.

## [1.0.0] - 2026-08-13

Initial release.

### Added
- WooCommerce Cart/Checkout **blocks** support (the gateway is otherwise invisible in
  block-based checkout, which is the WooCommerce default).
- Full and partial **refunds** via `process_refund()` / the `refunds` gateway capability.
- **GiroCode (EPC069-12)** QR on the thank-you page for SEPA/EUR orders, rendered from a
  locally vendored MIT QR library (no CDN, DSGVO-safe).
- Handling for `payment_intent.partially_funded` (underpayments), with a
  `btpw_payment_partially_funded` hook.
- Configurable awaiting-payment order status.
- Optional manual-renewal WooCommerce Subscriptions support.
- `Features` capability layer providing the declarative free/Pro tier split.
- Stripe bank-transfer payment gateway using the `customer_balance` funding flow.
- Per-order virtual bank account details (SEPA / ACH / Bacs / SPEI) on the
  thank-you page and in order emails.
- Automatic reconciliation via signed Stripe webhooks (succeeded, failed,
  cancelled, processing, requires_action).
- Custom "Awaiting Bank Transfer" order status.
- Pluggable Stripe-credential reuse via an adapter registry: the official
  WooCommerce Stripe Gateway, Payment Plugins for Stripe WooCommerce, and
  WP Swings' Payment Gateway Stripe and WooCommerce Integration — extendable
  through the `btpw_stripe_plugin_adapters` filter.
- Admin customer balance / virtual-account view on the user profile screen,
  driven by a capability-checked REST endpoint.
- HPOS (High-Performance Order Storage) compatibility.

### Security
- Webhooks are rejected unless a signing secret is configured and the Stripe
  signature verifies.
- The bundled Stripe PHP SDK is namespace-scoped at build time (Strauss) to
  avoid class collisions with other Stripe plugins.
