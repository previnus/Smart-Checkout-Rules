# Tests

Use a disposable WordPress installation with WooCommerce and Smart Checkout Rules active. Start with an empty test store. The scripts intentionally place test orders and replace checkout-rule settings during each run.

1. Run **wp eval-file tests/fixture.php**. It installs the WooCommerce pages, enables the built-in bank-transfer and cash-on-delivery gateways, and seeds a product, coupon and two shipping rates. The command prints IDs for the smoke tests.
2. Run **wp eval-file tests/rules.php** for rule-engine tests and the 10-, 100- and 500-rule benchmark.
3. Set **SCR_TEST_USER** and **SCR_TEST_PASSWORD** to the credentials of a disposable store administrator. Run the HTTP test with the IDs printed in step 1.

       python tests/checkout-smoke.py http://127.0.0.1:8874 --product PRODUCT_ID --classic-page CLASSIC_ID --flat-rate FLAT_ID --free-rate FREE_ID

4. With Playwright Core available to Node and **SCR_TEST_CHROME** pointing to a local Chrome executable, run the browser smoke test.

       node tests/browser-smoke.cjs http://127.0.0.1:8874 PRODUCT_ID BLOCKS_ID

   Set **SCR_TEST_SCREENSHOT** to save a screenshot of the rule builder.

Repeat the HTTP test with legacy order storage and HPOS. Use only offline payment methods and fictional shopper details.
