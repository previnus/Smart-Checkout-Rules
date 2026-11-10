# Developer reference

Rules are stored in a site-specific, non-autoloaded option as a versioned JSON snapshot. The repository checks the current revision and writes with a compare-and-swap against the exact stored snapshot. The engine evaluates a validated snapshot once per request. Older rule definitions are read through a deterministic migration until the administrator saves.

## Integrations

| Flow | WooCommerce integration |
| --- | --- |
| Classic payment and Store API cart availability | woocommerce_available_payment_gateways |
| Calculated packages | woocommerce_shipping_packages, after the raw rate cache and the core package filters |
| Final shortcode checkout | woocommerce_after_checkout_validation |
| Final Store API cart checkout | woocommerce_store_api_checkout_update_order_from_request identifies the submission, then woocommerce_checkout_validate_order_before_payment attaches errors |
| Store API address changes | woocommerce_store_api_checkout_update_customer_from_request refreshes totals and rates before WooCommerce creates the order |
| Purchase order field | Classic checkout fields and order CRUD, or woocommerce_register_additional_checkout_field in Blocks |
| Blocks notices | Cart Store API extension data in the smart-checkout-rules namespace |

A WooCommerce merchant controls payment and shipping availability. The plugin can remove rates and gateways or validate checkout. Manual orders, payment for an existing order, custom checkout endpoints and provider-specific express flows outside WooCommerce's normal cart checkout hooks are outside this policy.

## Rule format

Schema version 1 accepts UUIDv4 rule IDs, module, priority, enabled and stop-processing booleans, one condition group, one to eight actions, and an optional plain-text shopper message. The server maintains timestamps. An option can store up to 500 rules. A group can be nested to four levels with at most 40 total condition nodes per rule.

Condition fields and operator types are defined in RuleSchema.php. Numeric thresholds are decimal strings. List values are arrays of strings. Product and variation IDs match the parent or variation. Product category IDs include the WooCommerce product's category ancestry. Numeric comparisons use the store's price decimals or integer quantity. Missing values only satisfy explicit empty checks.

| Module | Actions |
| --- | --- |
| payment | hide_gateway, allow_gateway |
| validation | required, length, email_allow, email_block, pattern, postcode, phone, reject |
| limits | minimum, maximum |
| shipping | hide_rate, quote_only |

Shipping uses package destination and contents as well as cart-level values. The shipping-inclusive total and checkout selections are excluded from shipping conditions so eligibility does not depend on the chosen or calculated rate. Quote rules intersect the existing rates, and hide wins. Empty results produce an error where appropriate.

The WordPress REST namespace is **scr/v1**. Use GET or PUT on **configuration**, POST on **simulate**, and POST on **import** to validate and normalize an export. All require the **manage_woocommerce** capability. Use a current WordPress REST nonce for mutations and include the current revision for PUT. Simulation and import validation do not persist changes.

See [WooCommerce payment filtering](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/filtering-payment-methods), [Store API extension data](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/available-endpoints-to-extend), and [additional checkout fields](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields).
