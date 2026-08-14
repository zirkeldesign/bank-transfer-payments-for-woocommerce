=== Bank Transfer Payments for WooCommerce ===
Contributors: dsturm
Tags: bank transfer, vorkasse, banküberweisung, sepa, iban
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept reconciled bank transfer payments in WooCommerce via Stripe. Each order gets unique virtual bank account details, reconciled automatically.

== Description ==

Bank Transfer Payments for WooCommerce lets your store accept bank transfers the modern way: powered by Stripe's customer_balance funding flow, every order receives its **own unique virtual bank account** (SEPA IBAN, UK Bacs, US ACH, Mexican SPEI or Japanese Zengin). When the customer sends the transfer, a Stripe webhook marks the order paid **automatically** — no manual bank-statement matching.

Built DACH-first: SEPA / EUR is the default, with a German (Sie and Du) interface.

**Free means free.** This plugin processes real, live payments out of the box. There is no test-mode-only limitation and no paywall on taking money.

**Features**

* Per-order virtual bank account details shown on the thank-you page and in order emails.
* Automatic reconciliation via Stripe webhooks (paid, failed, cancelled, processing, partially funded).
* **GiroCode (EPC-QR)** on the thank-you page — customers scan it with their banking app to pre-fill the transfer.
* Full and partial **refunds** straight from the WooCommerce order screen.
* Works in both the **block-based checkout** and the classic checkout.
* A dedicated "Awaiting Bank Transfer" order status, or choose your own (On hold / Pending payment).
* Underpayment detection: partial transfers are flagged on the order.
* Optional reuse of Stripe API keys already configured by the official WooCommerce Stripe Gateway, Payment Plugins for Stripe WooCommerce, or Payment Gateway Stripe and WooCommerce Integration.
* German translations, informal (Du) and formal (Sie).
* HPOS (High-Performance Order Storage) compatible.

This plugin requires a Stripe account and the WooCommerce plugin. Stripe is a third-party payment service; by using this plugin payment data is transmitted to Stripe (see the Stripe [Privacy Policy](https://stripe.com/privacy) and [Terms](https://stripe.com/legal)).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Go to WooCommerce → Settings → Payments → Bank Transfer and enable it.
3. Enter your Stripe secret key (test and live), or let the plugin reuse keys from an existing Stripe plugin.
4. In your Stripe Dashboard, add a webhook endpoint pointing to the URL shown in the gateway settings, and paste the signing secret into the Webhook Secret field.

== Frequently Asked Questions ==

= Do I need a Stripe account? =
Yes. This plugin uses Stripe's bank-transfer (customer_balance) feature; availability of specific bank-transfer types depends on your Stripe account country and currency.

= Does it work with the official WooCommerce Stripe gateway installed? =
Yes. It can reuse the API credentials stored by the official WooCommerce Stripe Gateway or by Payment Plugins for Stripe WooCommerce, so you do not have to enter keys twice.

= Is the webhook required? =
Yes, for automatic reconciliation. A signing secret must be configured — the plugin refuses to process unverified webhook requests.

= Can I really take live payments with the free version? =
Yes. The free version is not limited to Stripe test mode. Stripe's own transaction fees apply, as with any payment method.

= What if a customer transfers too little? =
Stripe holds the part-payment in the customer balance and waits for the rest. The plugin records the shortfall as an order note so you can follow up.

= Does it support WooCommerce Subscriptions? =
Bank transfers cannot be charged automatically, so subscription renewals are always manual: each renewal issues a fresh virtual bank account for the customer to pay. This is available as an optional add-on feature.

== Changelog ==

= 1.0.0 =
* Initial release: Stripe bank-transfer gateway with per-order virtual bank accounts and automatic webhook reconciliation.
* Added: GiroCode (EPC-QR) on the thank-you page for SEPA payments.
* Added: Full and partial refunds from the order screen.
* Added: Block-based checkout support.
* Added: Configurable awaiting-payment order status.
* Added: Underpayment detection for partially funded transfers.
