=== Moniq Payment Gateway ===
Contributors: Moniq Team
Tags: woocommerce, payment gateway, moniq, payments
Requires at least: 5.0
Tested up to: 6.4
WC requires at least: 3.5
WC tested up to: 9.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through Moniq. Customers are redirected to a secure checkout page.

== Description ==

This plugin allows WooCommerce store owners to accept payments via Moniq. Customers are redirected to the secure Moniq checkout page to complete their purchase.

**Features:**

* Secure hosted checkout
* Webhook support for reliable payment confirmation
* WooCommerce Blocks support
* High-Performance Order Storage (HPOS) compatible
* Debug logging

== Installation ==

1. Upload the `moniq-wc` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to WooCommerce > Settings > Payments and enable "Moniq"
4. Enter your API Public Key and API Secret

== Configuration ==

1. Navigate to WooCommerce > Settings > Payments > Moniq
2. Enable the payment gateway
3. Enter your Public Key and API Secret from the Moniq dashboard
4. Optionally configure a webhook secret for signature verification
5. Save settings and test the connection

== Changelog ==

= 2.0.0 =
* Rebranded to Moniq
* Streamlined codebase
* Added customer address support
* Improved error handling

= 1.0.0 =
* Initial release

== File Structure ==

moniq-wc/
├── assets/
│   ├── css/
│   │   └── moniq-admin.css
│   ├── images/
│   │   └── icon.png
│   └── js/
│       ├── moniq-admin.js
│       └── moniq-checkout.js
├── includes/
│   ├── class-wc-moniq-api.php
│   ├── class-wc-moniq-blocks-integration.php
│   └── class-wc-moniq-logger.php
├── moniq.php
├── class-wc-moniq-gateway.php
└── readme.txt
