# Release checklist

- Check PHP and JavaScript syntax and run the WordPress coding standard.
- Exercise permissions, stale saves, malformed imports, output escaping, bounded patterns, and log redaction.
- Run the Classic and Store API smoke tests, including crafted requests and a successful order.
- Verify the Checkout Block hides the gateway in a real browser and verify both order storage modes.
- Test custom gateways, express flows, multi-currency providers and split-package extensions before enabling policies in a particular store.
- Run Rafter secret detection locally and its remote code scan against the release commit.
- Verify the ZIP contains exactly one plugin directory with the plugin entry point, readme, license and runtime source. Keep test fixtures, credentials and local WordPress configuration outside the ZIP.

## Security review

The review traced admin REST requests through the WooCommerce capability check, WordPress session nonce, size and schema validation, and revision-checked option write. The update compares the expected persisted revision in one database operation. The database table name comes from WordPress, and all data values are passed through WordPress database helpers.

The checkout review covered a submitted hidden gateway, a restricted shipping selection, and an address changed in the final Store API request. The package filter runs after the carrier cache. A final address change recalculates totals and rates before WooCommerce builds the order.

The importer parses bounded JSON without evaluating it. The builder writes labels and messages as DOM text. Shopper notices escape merchant text. Diagnostic events contain an event code and validated rule IDs. The plugin has no payment credentials, outbound service integration, or bundled runtime dependencies.

## Compatibility checks

| WordPress | WooCommerce | PHP | Order storage | Verified |
| --- | --- | --- | --- | --- |
| 7.1.4 | 10.5.0 | 8.3.21 | HPOS | Classic and Store API checkout, browser rule builder and Checkout Block gateway visibility |
| 7.1.4 | 10.5.0 | 8.3.21 | Legacy order tables | Classic and Store API checkout |
| 7.1.4 | 11.2.2 | 8.5.10 | HPOS | Classic and Store API checkout |

The fixtures use the standard bank transfer and cash on delivery gateways and the flat-rate and free shipping methods. Third-party gateways, provider-specific express flows, currency switchers and split-package extensions were outside the test matrix.
