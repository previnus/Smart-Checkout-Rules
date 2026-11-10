Smart Checkout Rules for WooCommerce — Implementation Plan
Version: 1.0 (planning)
Target: WordPress plugin for WooCommerce
Status: Implementation specification, not completed software
1. Product goal
Provide a secure, modular, administrator-configurable rules engine for controlling payment methods, validating checkout data, enforcing order limits, and restricting shipping methods. Support WooCommerce Classic Checkout and Checkout Blocks with server-side enforcement.
Goals
- No-code rule creation with AND/OR condition groups, priority, active status, and clear explanations.
- Predictable rules under changing carts, addresses, coupons, and shipping selections.
- HPOS-safe implementation using WooCommerce CRUD APIs.
- Accessible, translatable admin UI with rule simulation and diagnostic logging.
Non-goals for v1
- Custom payment gateways or shipping-rate calculations.
- A general-purpose workflow automation engine.
- Arbitrary PHP expressions supplied through the admin.
2. Functional scope
2.1 Conditional payment methods
Actions: hide/allow eligible gateways; optionally show a checkout notice.
Conditions: subtotal, total, cart quantity, products, categories, shipping classes, coupon presence, customer role, billing/shipping country and state, selected shipping method, virtual-only cart.
Examples: hide Cash on Delivery above a threshold; permit bank transfer for approved wholesale roles; hide a gateway for selected destinations.

Safety: never force-enable a disabled or unavailable gateway. Evaluate against WooCommerce's already-available gateway set. When no gateways remain, show a helpful error and log the rule IDs involved.
2.2 Checkout validation
Actions: require field, validate value, block checkout with localized field-specific error.
Conditions: customer role, cart composition, destination, company field presence, order amount.
Initial validators: required, length bounds, email domain allow/block list, regex with safe guardrails, and country-aware postcode/phone validation where WooCommerce facilities exist.
Examples: require company name for B2B orders; require purchase order reference for bank-transfer orders; reject prohibited email domains.

Safety: validate on the server at final order submission. Treat user-supplied fields as untrusted. For Blocks, register additional fields through supported WooCommerce APIs rather than assuming classic field hooks apply.
2.3 Order minimums and maximums
Actions: prevent checkout and optionally display progress/remaining amount.
Conditions: cart subtotal, discounted subtotal, quantity, item/category totals, role, destination.
Examples: minimum subtotal of 50 in store currency; minimum 5 units in a category; wholesale-only minimum of 500.

Semantics: explicitly configure tax inclusion, coupon treatment, shipping exclusion, currency handling, and whether thresholds apply per cart or qualifying line items. Default to cart merchandise subtotal excluding shipping, after discounts, excluding tax. Evaluate using WooCommerce monetary APIs and consistent decimal precision.
2.4 Shipping restrictions
Actions: hide matching rates; optionally require a quote-only workflow if a separately configured compatible method exists.
Conditions: shipping destination, cart value, category/product, shipping class, quantity, role, package contents.
Examples: hide express for oversized products; restrict local pickup to selected regions; hide flat-rate shipping for a hazardous or bulky shipping class.

Safety: apply rules per shipping package; identify methods by method ID and instance ID, not display labels. Never invent a shipping rate. If no rate remains, present a clear checkout error.
3. Rule model and evaluation
3.1 Rule schema
Each rule contains:
- id (UUID or internal numeric ID), name, module, enabled, priority, stop_processing.
- conditions: nested groups with all / any, operator and typed values.
- actions: module-specific allow/hide/reject configuration.
- message: optional localized public-facing error or notice.
- created_at, updated_at, schema_version.
Example:
{
  "schema_version": 1,
  "name": "Hide COD for high-value carts",
  "module": "payment",
  "enabled": true,
  "priority": 100,
  "stop_processing": false,
  "conditions": {
    "all": [
      {"field": "cart.subtotal_after_discount_ex_tax", "operator": "gte", "value": "500.00"}
    ]
  },
  "actions": [{"type": "hide_gateway", "gateway_id": "cod"}]
}
3.2 Evaluation order
1. Build a normalized, request-scoped checkout context from WooCommerce cart/customer/package data.
2. Load enabled rules for the relevant module, sorted by ascending priority then stable ID.
3. Validate the stored schema and supported operators; skip invalid rules with admin-visible diagnostics.
4. Evaluate condition groups deterministically, with bounded nesting and condition count.
5. Accumulate actions and resolve conflicts. Restriction wins: hide overrides allow; allow never re-enables an unavailable method. stop_processing stops subsequent matching rules only within its module and defined scope.
6. Apply restrictions to WooCommerce-provided available methods/rates, or append validation errors.
7. Recompute on each relevant cart/checkout request; avoid cross-customer persistent caches.
3.3 Edge cases
- Guest users and customers without roles; carts with virtual products; multiple shipping packages.
- Coupon changes, currency-switcher plugins, tax-inclusive stores, rounding, empty carts.
- Missing/deactivated gateway or shipping instance, changing address mid-checkout.
- Block checkout asynchronous updates, express payment buttons, payment method eligibility.
- Re-entrant hooks and duplicated validation messages.
4. Technical architecture
smart-checkout-rules/
├── smart-checkout-rules.php
├── uninstall.php
├── readme.txt
├── composer.json
├── includes/
│   ├── Plugin.php
│   ├── Compatibility.php
│   ├── Rules/RuleRepository.php
│   ├── Rules/RuleSchema.php
│   ├── Rules/RuleEngine.php
│   ├── Rules/ConditionEvaluator.php
│   ├── Context/CheckoutContextFactory.php
│   ├── Modules/PaymentRules.php
│   ├── Modules/CheckoutValidation.php
│   ├── Modules/OrderLimits.php
│   ├── Modules/ShippingRestrictions.php
│   ├── Integrations/ClassicCheckout.php
│   ├── Integrations/BlocksCheckout.php
│   ├── Admin/AdminPage.php
│   ├── Admin/RulesRestController.php
│   └── Support/Diagnostics.php
├── assets/admin/
├── assets/frontend/
├── languages/
└── tests/{Unit,Integration,E2E}/
Storage
Use a dedicated rule repository, initially backed by a versioned WordPress option for small rule sets; define repository interfaces to allow migration to a custom table when scale requires. Avoid direct queries against WooCommerce order tables. Store only settings and rule definitions, not customer personal data in logs.
Hooks and integration
- Classic: payment gateway filtering (woocommerce_available_payment_gateways), shipping-rate filtering (woocommerce_package_rates), and supported classic checkout validation hooks. Guard against non-checkout contexts and recursive execution.
- Blocks: integrate through supported WooCommerce Blocks payment eligibility mechanisms, Store API checkout validation/extensions, and additional checkout fields APIs as applicable. Verify exact extension points against the supported WooCommerce release during implementation. Do not rely on classic hooks alone to provide equivalent Blocks behavior.
- HPOS: declare compatibility only after validation, and use WooCommerce order CRUD rather than direct wp_posts/`wp_postmeta` assumptions.
5. Admin experience
Menu: WooCommerce → Checkout Rules.

Screens:
1. Dashboard: module status, active rule count, warnings, last diagnostics.
2. Rule list: name, module, priority, enabled status, last modified; search, filter, duplicate, bulk enable/disable.
3. Rule builder: rule name, module, condition groups (AND/OR), actions, priority, messages, save and test.
4. Rule simulator: enter hypothetical cart, customer role, destination and shipping/payment selections; show matching rule IDs and final outcome without creating an order.
5. Settings: module toggles, logging level, cleanup preferences, compatibility checks.
Use WordPress capabilities (manage_woocommerce or equivalent explicit capability), REST permission callbacks, nonces for state-changing requests, input validation, output escaping, and accessible controls. Support import/export with schema checks and no executable payloads.
6. Security, reliability, and performance
- Require supported WordPress, PHP, and WooCommerce versions; fail gracefully if WooCommerce is inactive.
- Sanitize and strictly validate every stored condition/operator/action; reject unknown keys where appropriate.
- Restrict REST routes to authorized users; use REST nonces and capability checks.
- Never trust browser-side checks: payment, shipping, limits, and field validation must be enforced server-side.
- Prevent arbitrary code execution and unsafe regex patterns; cap condition nesting, rule counts, and evaluation work.
- Use WooCommerce notices and Store API errors appropriate to the checkout type; do not expose internal rule configuration or customer data.
- Cache parsed rule configuration per request; benchmark with 10, 100, and 500 rules.
- Diagnostic logs should redact emails, addresses, phone numbers, tokens, and payment information.
- Activation should not modify existing checkout settings; uninstall should preserve data by default unless explicit deletion is configured.
7. Implementation milestones
Phase	Work	Exit criteria
0. Discovery	Pin supported WP/WC/PHP versions; research Blocks extension points; compatibility matrix	Approved API and test matrix
1. Foundation	Bootstrap, autoloading, repository, schema, evaluator, unit tests	Deterministic rules, validated CRUD
2. Payment + limits	Classic integrations, subtotal/quantity conditions, errors	End-to-end classic checkout scenarios pass
3. Validation + shipping	Conditional fields, shipping package filtering	Correct field errors and per-package restrictions
4. Admin UI	Rule list, builder, preview, REST endpoints, accessibility	Admin can manage rules without code
5. Blocks	Store API validation, Blocks payment eligibility and checkout UI	Equivalent supported behavior in Blocks checkout
6. QA + release	HPOS, compatibility, security, performance, translations, packaging	Release checklist complete; installable ZIP


Recommended sequencing: validate Blocks architecture in Phase 0 rather than postponing feasibility until Phase 5. Implement shared rule logic once, with distinct Classic and Blocks adapters.
8. Testing plan
Unit tests
- Operators, nested AND/OR, priority, stop-processing, conflicts, missing fields.
- Decimal threshold boundaries, tax/coupon handling, zero/negative/empty values.
- Schema rejection, malicious inputs, invalid regex, bounded evaluation.
Integration tests
- WooCommerce gateways and shipping method instances.
- Multiple packages and shipping destinations.
- Guest vs logged-in, virtual-only and mixed carts.
- HPOS enabled/disabled; supported WooCommerce configurations.
End-to-end tests
- Classic and Blocks checkout with address/cart changes.
- A rule that hides a gateway; one that blocks an invalid field; minimum threshold; restricted shipping.
- No eligible gateway/rate; informative error and no bypass via crafted requests.
- Admin rule CRUD, permissions, duplication, import/export, and simulator accuracy.
Compatibility matrix
Test supported latest and minimum versions of WordPress/WooCommerce/PHP; both checkout implementations; HPOS on/off; common payment gateways and shipping extensions; tax-inclusive/exclusive settings; coupons and guest checkout. Record unsupported third-party combinations explicitly.
9. Acceptance criteria
- [ ] Administrator can create, edit, disable, prioritize, duplicate, and delete each of the four rule types.
- [ ] AND/OR conditions produce consistent outcomes and rule conflicts are documented.
- [ ] Payment restrictions cannot be bypassed by choosing a hidden gateway in a crafted request.
- [ ] Order minimums and checkout validation reject invalid submissions on the server.
- [ ] Shipping restrictions apply correctly per package and do not create rates.
- [ ] Supported Classic and Blocks checkouts behave consistently, including AJAX/Store API updates.
- [ ] HPOS compatibility is tested and accurately declared.
- [ ] Unauthorized users cannot read or modify rules through REST or admin actions.
- [ ] No sensitive checkout/customer data appears in diagnostics.
- [ ] Plugin passes automated tests and WordPress coding/security checks.
- [ ] Installable ZIP, source, readme, changelog, and administrator guide are delivered.
10. Deliverables
1. Installable smart-checkout-rules.zip and version-controlled source.
2. Administrator guide with screenshots and sample rules.
3. Developer reference for conditions, actions, hooks, and filters.
4. Test suite, compatibility matrix, and release checklist.
5. Changelog, license details, and upgrade/migration guidance.
11. Initial sample rule library
Rule	Conditions	Action
Disable COD for high-value orders	Merchandise subtotal ≥ 500	Hide cod
Wholesale minimum	Customer role = wholesale AND subtotal < 250	Reject checkout
Require company details	Customer role = business	Require company field
No express for bulky goods	Cart contains shipping class oversized	Hide configured express shipping instance
Local pickup only in region	Shipping state not in allowed set	Hide configured local-pickup instance


12. Open decisions before development
- Exact minimum supported WordPress, WooCommerce, and PHP versions.
- Whether currency switching and multi-currency thresholds are in v1.
- Which custom checkout fields and validators are essential for launch.
- Which third-party payment/shipping integrations require certified testing.
- Whether rule import/export and simulator ship in v1 or v1.1.
Release principle: prioritize server-side correctness and compatibility over the number of rule types. Never advertise Checkout Blocks support until tested end to end.

## 13. Implementation decisions

- Target WordPress 6.8+, WooCommerce 10.5+ and PHP 8.3+. Declare checkout and order-storage compatibility only for flows verified in the release matrix.
- WordPress authenticates administrators and checks `manage_woocommerce` on every rules endpoint. Mutations require the session's REST nonce and a current revision. Store a bounded, versioned option per site and preserve the original option for rollback.
- Checkout adapters use WooCommerce's authenticated cart, validated addresses, live gateway availability and calculated shipping packages. Checkout requests supply a selection or field value. The server derives role, currency, prices, discounts and product classifications independently.
- Filter shipping after WooCommerce loads its cached package rates, so policy changes do not persist in the carrier cache. Shipping rules can use merchandise metrics and package contents. The shipping-inclusive total and chosen shipping method would make shipping eligibility circular and are reserved for other modules.
- Store administrators configure one optional purchase order reference field through the rule builder. WooCommerce stores the field on the order. Rule settings and diagnostics contain rule definitions, IDs and event codes. The plugin does not copy shopper fields or payment details into those stores.
- Imports are bounded JSON with a strict schema. Messages are plain text. Patterns have fixed input, match and recursion limits. Rule matching has no executable actions. Public checkout responses expose only customer-facing messages and available methods.
- Abuse cases reviewed before implementation include unauthorized rule writes, stale edits, crafted payment and shipping selections, cache reuse across customers, malformed imports, costly patterns, and customer data in logs. Orders created by the admin, custom endpoints and payment on existing orders fall outside cart checkout policy.
