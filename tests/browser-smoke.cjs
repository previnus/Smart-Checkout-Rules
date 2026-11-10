const assert = require('node:assert/strict');
const { chromium } = require('playwright-core');

async function run() {
    const [base, product, checkoutPage] = process.argv.slice(2);
    const browser = await chromium.launch({ executablePath: process.env.SCR_TEST_CHROME, headless: true });
    const admin = await browser.newPage({ viewport: { width: 1440, height: 950 } });
    const shopper = await browser.newPage({ viewport: { width: 1440, height: 950 } });
    const route = '/?rest_route=/scr/v1/configuration';
    const errors = [];
    admin.on('pageerror', (error) => errors.push(error.message));
    shopper.on('pageerror', (error) => errors.push(error.message));
    let previous;
    let headers;

    try {
        await admin.goto(base + '/wp-login.php');
        await admin.locator('#user_login').fill(process.env.SCR_TEST_USER);
        await admin.locator('#user_pass').fill(process.env.SCR_TEST_PASSWORD);
        await admin.locator('#wp-submit').click();
        await admin.waitForURL('**/wp-admin/**');
        await admin.goto(base + '/wp-admin/admin.php?page=scr-rules');
        await admin.getByRole('button', { name: 'Add rule', exact: true }).waitFor();
        headers = { 'X-WP-Nonce': await admin.evaluate(() => window.scrAdmin.nonce) };
        previous = await (await admin.request.get(base + route, { headers })).json();
        await admin.getByRole('button', { name: 'Dashboard', exact: true }).click();
        await admin.getByRole('button', { name: 'Hide cash on delivery on large orders' }).click();
        await admin.getByLabel('Name', { exact: true }).fill('Hide cash on delivery above 50');
        await admin.getByLabel('Value', { exact: true }).fill('50');
        await admin.getByLabel('Enabled', { exact: true }).check();
        await admin.getByRole('button', { name: 'Save rules and settings' }).click();
        await admin.getByText('Rules and settings saved.', { exact: true }).waitFor();
        if (process.env.SCR_TEST_SCREENSHOT) {
            await admin.locator('#scr-admin').screenshot({ path: process.env.SCR_TEST_SCREENSHOT });
        }

        const cart = await shopper.request.get(base + '/?rest_route=/wc/store/v1/cart');
        const added = await shopper.request.post(base + '/?rest_route=/wc/store/v1/cart/add-item', {
            headers: { Nonce: cart.headers().nonce },
            data: { id: Number(product), quantity: 1 },
        });
        assert(added.ok(), await added.text());
        assert.deepEqual((await added.json()).payment_methods, ['bacs']);
        const address = {
            first_name: 'Fixture', last_name: 'Shopper', address_1: '123 Fixture Avenue',
            city: 'Los Angeles', state: 'CA', postcode: '90001', country: 'US', phone: '3105550100',
        };
        const updated = await shopper.request.post(base + '/?rest_route=/wc/store/v1/cart/update-customer', {
            headers: { Nonce: added.headers().nonce },
            data: { shipping_address: address, billing_address: { ...address, email: 'shopper@example.test' } },
        });
        assert(updated.ok(), await updated.text());

        await shopper.goto(base + '/?page_id=' + checkoutPage);
        try {
            await shopper.locator('input[value="bacs"]').first().waitFor({ state: 'attached', timeout: 30000 });
        } catch (error) {
            throw new Error(error.message + '\n' + (await shopper.locator('body').innerText()).slice(0, 1600) + '\n' + errors.join('\n'));
        }
        assert.equal(await shopper.locator('input[value="cod"]').count(), 0);
        assert.deepEqual(errors, []);
        console.log('PASS browser admin rule creation and Checkout Block gateway visibility');
    } finally {
        if (previous && headers) {
            const current = await (await admin.request.get(base + route, { headers })).json();
            await admin.request.put(base + route, {
                headers,
                data: { revision: current.revision, rules: previous.rules, settings: previous.settings },
            });
        }
        await browser.close();
    }
}

run().catch((error) => { console.error(error.message); process.exitCode = 1; });
