"""Run against a disposable WooCommerce site seeded as described in tests/README.md."""
import argparse
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request


class Client:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.nonce = None

    def request(self, path, method="GET", data=None, headers=None):
        request = urllib.request.Request(
            self.base + path, data=data, headers=headers or {}, method=method
        )
        try:
            response = self.opener.open(request, timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read().decode("utf-8", errors="replace")
            return response.status, response.headers, body

    def rest(self, route, method="GET", data=None, headers=None):
        status, response_headers, body = self.request(
            "/?" + urllib.parse.urlencode({"rest_route": route}), method,
            json.dumps(data).encode() if data is not None else None,
            {"Content-Type": "application/json", **(headers or {})},
        )
        try:
            payload = json.loads(body)
        except json.JSONDecodeError as error:
            raise AssertionError((status, route, body[:500])) from error
        return status, response_headers, payload

    def store(self, route, method="GET", data=None):
        headers = {"Nonce": self.nonce} if self.nonce else {}
        code, response_headers, payload = self.rest("/wc/store/v1" + route, method, data, headers)
        self.nonce = response_headers.get("Nonce") or self.nonce
        return code, payload


def rule(name, module, action, conditions=(), message=""):
    return {
        "schema_version": 1, "name": name, "module": module, "enabled": True,
        "actions": [action], "conditions": {"all": list(conditions)}, "message": message,
    }


def value(status_payload):
    actual, payload = status_payload
    assert 200 <= actual < 300, (actual, payload)
    return payload


def assert_blocked(result, message):
    code, payload = result
    assert 400 <= code < 500, (code, payload)
    assert message in str(payload), payload


def admin_login(base):
    admin = Client(base)
    admin.request("/wp-login.php")
    admin.request("/wp-login.php", "POST", urllib.parse.urlencode({
        "log": os.environ["SCR_TEST_USER"], "pwd": os.environ["SCR_TEST_PASSWORD"],
        "testcookie": "1", "redirect_to": base + "/wp-admin/",
    }).encode(), {"Content-Type": "application/x-www-form-urlencoded"})
    code, _, html = admin.request("/wp-admin/admin.php?page=scr-rules")
    assert code == 200, code
    match = re.search(r"window\.scrAdmin\s*=\s*(\{.*?\});", html)
    assert match, html[:350]
    nonce = json.loads(match.group(1))["nonce"]
    return admin, {"X-WP-Nonce": nonce}


def add_product(shopper, product):
    value(shopper.store("/cart"))
    value(shopper.store("/cart/add-item", "POST", {"id": product, "quantity": 1}))


ADDRESS = {
    "first_name": "Fixture", "last_name": "Shopper", "company": "",
    "address_1": "123 Fixture Avenue", "address_2": "", "city": "Los Angeles",
    "state": "CA", "postcode": "90001", "country": "US", "phone": "3105550100",
}
BILLING = {**ADDRESS, "email": "shopper@example.test"}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("base")
    parser.add_argument("--product", type=int, required=True)
    parser.add_argument("--classic-page", type=int, required=True)
    parser.add_argument("--flat-rate", required=True)
    parser.add_argument("--free-rate", required=True)
    args = parser.parse_args()
    admin, headers = admin_login(args.base)
    route = "/scr/v1/configuration"
    shopper = Client(args.base)
    assert shopper.rest(route)[0] == 401
    assert admin.rest(route, "PUT", {})[0] == 401
    code, _, previous = admin.rest(route, headers=headers)
    assert code == 200, previous

    rules = [
        rule("High cart hides COD", "payment", {"type": "hide_gateway", "gateway_id": "cod"},
             [{"field": "cart.subtotal_after_discount_ex_tax", "operator": "gte", "value": "50"}]),
        rule("Minimum", "limits", {"type": "minimum", "amount": "50", "progress": True},
             message="Fixture minimum subtotal."),
        rule("Bank transfer requires reference", "validation", {"type": "required", "field": "scr_po_number"},
             [{"field": "checkout.payment_method", "operator": "in", "value": ["bacs"]}],
             "Fixture purchase order reference."),
        rule("Disable flat rate in California", "shipping", {"type": "hide_rate", "method_id": args.flat_rate},
             [{"field": "destination.state", "operator": "in", "value": ["CA"]}]),
    ]
    settings = previous["settings"]
    code, _, saved = admin.rest(route, "PUT", {"revision": previous["revision"], "rules": rules, "settings": settings}, headers)
    assert code == 200, saved
    assert admin.rest(route, "PUT", {"revision": previous["revision"], "rules": [], "settings": settings}, headers)[0] == 409
    invalid = [{**rules[0], "unexpected": "<script>example</script>"}]
    assert admin.rest(route, "PUT", {"revision": saved["revision"], "rules": invalid, "settings": settings}, headers)[0] == 400
    code, _, simulation = admin.rest("/scr/v1/simulate", "POST", {
        "rules": saved["rules"], "settings": settings,
        "scenario": {"values": {"shipping.state": "CA", "shipping.country": "US", "cart.subtotal_after_discount_ex_tax": "60"},
                     "gateways": ["cod", "bacs"], "rates": [args.flat_rate, args.free_rate]},
    }, headers)
    assert code == 200 and simulation["payment"]["available"] == ["bacs"] and simulation["shipping"]["available"] == [args.free_rate], simulation

    def update(shop, state="CA"):
        return value(shop.store("/cart/update-customer", "POST", {
            "billing_address": {**BILLING, "state": state},
            "shipping_address": {**ADDRESS, "state": state},
        }))

    def pay(shop, method, reference=None):
        data = {"billing_address": BILLING, "shipping_address": ADDRESS, "payment_method": method}
        if reference:
            data["additional_fields"] = {"smart-checkout-rules/po-number": reference}
        return shop.store("/checkout", "POST", data)

    try:
        add_product(shopper, args.product)
        cart = update(shopper)
        assert "cod" not in cart["payment_methods"] and "bacs" in cart["payment_methods"], cart["payment_methods"]
        rates = [rate["rate_id"] for package in cart["shipping_rates"] for rate in package["shipping_rates"]]
        assert args.flat_rate not in rates and args.free_rate in rates, rates
        code, result = shopper.store("/cart/select-shipping-rate", "POST", {"package_id": 0, "rate_id": args.flat_rate})
        if 200 <= code < 300:
            assert all(rate["rate_id"] != args.flat_rate for package in result["shipping_rates"] for rate in package["shipping_rates"])
        else:
            assert 400 <= code < 500, result
        assert_blocked(pay(shopper, "cod"), "not available for this order")
        assert_blocked(pay(shopper, "bacs"), "Fixture purchase order reference.")
        value(shopper.store("/cart/apply-coupon", "POST", {"code": "scr-fixture-coupon"}))
        assert_blocked(pay(shopper, "bacs", "FIXTURE-001"), "Fixture minimum subtotal.")
        value(shopper.store("/cart/remove-coupon", "POST", {"code": "scr-fixture-coupon"}))
        placed = value(pay(shopper, "bacs", "FIXTURE-001"))
        assert placed["payment_method"] == "bacs" and placed["order_id"], placed

        changed_address = Client(args.base)
        add_product(changed_address, args.product)
        update(changed_address, "NV")
        value(changed_address.store("/cart/select-shipping-rate", "POST", {"package_id": 0, "rate_id": args.flat_rate}))
        assert_blocked(pay(changed_address, "bacs", "FIXTURE-003"), "selected shipping method is unavailable")

        classic = Client(args.base)
        add_product(classic, args.product)
        update(classic)
        code, _, page = classic.request("/?page_id=" + str(args.classic_page))
        nonce = re.search(r'name="woocommerce-process-checkout-nonce"\s+value="([^"]+)"', page)
        assert code == 200 and nonce, page[:350]
        assert "payment_method_cod" not in page and "payment_method_bacs" in page
        fields = {f"billing_{key}": val for key, val in BILLING.items()}
        fields.update({"woocommerce-process-checkout-nonce": nonce.group(1), "shipping_method[0]": args.free_rate})
        def classic_submit(method, reference="", rate=args.free_rate):
            payload = {**fields, "payment_method": method, "scr_po_number": reference, "shipping_method[0]": rate}
            code, _, result = classic.request("/?wc-ajax=checkout", "POST", urllib.parse.urlencode(payload).encode(),
                                              {"Content-Type": "application/x-www-form-urlencoded"})
            assert code == 200, result
            return json.loads(result)

        hidden = classic_submit("cod")
        assert hidden["result"] == "failure" and "selected payment method is unavailable" in hidden["messages"], hidden
        missing = classic_submit("bacs")
        assert missing["result"] == "failure" and "Fixture purchase order reference." in missing["messages"], missing
        restricted = classic_submit("bacs", "FIXTURE-002", args.flat_rate)
        assert restricted["result"] == "failure" and "selected shipping method is unavailable" in restricted["messages"], restricted
        value(classic.store("/cart/apply-coupon", "POST", {"code": "scr-fixture-coupon"}))
        minimum = classic_submit("bacs", "FIXTURE-002")
        assert minimum["result"] == "failure" and "Fixture minimum subtotal." in minimum["messages"], minimum
        value(classic.store("/cart/remove-coupon", "POST", {"code": "scr-fixture-coupon"}))
        placed = classic_submit("bacs", "FIXTURE-002")
        assert placed["result"] == "success", placed
        print("PASS Classic and Store API shipping, crafted payment/shipping, validation, coupon minimum and order placement")
        print("PASS REST capability/nonce, stale revision, strict schema and simulator")
    finally:
        code, _, current = admin.rest(route, headers=headers)
        if code == 200:
            admin.rest(route, "PUT", {
                "revision": current["revision"], "rules": previous["rules"], "settings": previous["settings"],
            }, headers)


if __name__ == "__main__":
    main()
