=== Smart Checkout Rules ===
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.3
Requires Plugins: woocommerce
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Conditional payment methods, checkout validation, order limits, and shipping restrictions for WooCommerce.

== Description ==

Manage AND/OR rules from WooCommerce > Checkout Rules. Use the simulator to evaluate hypothetical carts before saving. Rules restrict WooCommerce's existing gateways and rates. Supported validation and limits run on the server when an order is submitted.

== Installation ==

1. Activate WooCommerce 10.5 or newer on WordPress 6.8+ with PHP 8.3+.
2. Upload smart-checkout-rules.zip in Plugins > Add New Plugin > Upload Plugin and activate it.
3. Open WooCommerce > Checkout Rules > Rules. Add and name a rule, choose a module, then add conditions and actions.
4. Enable the rule. Try it in Simulator, then select Save rules and settings.

== Configuration ==

* Payment: use a gateway ID such as cod or bacs. Hide takes precedence over allow. Allow cannot enable a disabled gateway.
* Validation: require a checkout field, validate its format or block checkout. An active validation rule for the purchase order reference adds that field.
* Order limits: set a minimum or maximum subtotal or item quantity. The default subtotal excludes tax and shipping and accounts for discounts. Choose a currency or use the store currency.
* Shipping: use a method ID such as flat_rate or an instance ID such as flat_rate:3. Rules run separately for each shipping package.

Lower priority numbers run first. Stop after this rule matches affects later rules in the same module and shipping package. Settings contains module toggles, optional redacted logging, import/export and uninstall cleanup.

== Upgrade Notice ==

= 2.0.0 =
Requires PHP 8.3, WordPress 6.8 and WooCommerce 10.5. Migrates 1.0 rules into the new editor and retains their original settings for rollback.

== Changelog ==

= 2.0.0 =
* Add nested conditions, rule priorities, simulator, import/export, and protected REST configuration.
* Add Store API enforcement, maximums, scoped limits, and additional checkout field validation.
* Apply shipping restrictions after cached rates are loaded.
* Preserve configuration by default on uninstall.
