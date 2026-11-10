<?php
// Run via wp eval-file on an empty, disposable WooCommerce site.
defined( 'ABSPATH' ) || exit;

WC_Install::create_pages();
foreach ( array( 'bacs', 'cod' ) as $gateway ) {
	update_option( 'woocommerce_' . $gateway . '_settings', array( 'enabled' => 'yes' ) );
}
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_coupons', 'yes' );

$zone = new WC_Shipping_Zone( 0 );
$ids  = array();
foreach ( $zone->get_shipping_methods() as $method ) {
	$ids[ $method->id ] = $method->get_instance_id();
}
$flat = $ids['flat_rate'] ?? $zone->add_shipping_method( 'flat_rate' );
$free = $ids['free_shipping'] ?? $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_flat_rate_{$flat}_settings", array( 'title' => 'Fixture flat rate', 'cost' => '7', 'tax_status' => 'none' ) );
update_option( "woocommerce_free_shipping_{$free}_settings", array( 'title' => 'Fixture free shipping', 'requires' => '' ) );

$product_id = wc_get_product_id_by_sku( 'scr-fixture' );
if ( ! $product_id ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Fixture product' );
	$product->set_sku( 'scr-fixture' );
	$product->set_regular_price( '60' );
	$product_id = $product->save();
}

$coupon = new WC_Coupon( 'scr-fixture-coupon' );
$coupon->set_code( 'scr-fixture-coupon' );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( '15' );
$coupon->save();

$checkout = wc_get_page_id( 'checkout' );
$classic = get_page_by_path( 'classic-checkout' );
$classic_id = $classic ? $classic->ID : wp_insert_post(
	array(
		'post_title' => 'Classic Checkout',
		'post_name' => 'classic-checkout',
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_content' => '[woocommerce_checkout]',
	)
);
echo wp_json_encode( array( 'product' => $product_id, 'flat' => "flat_rate:$flat", 'free' => "free_shipping:$free", 'classic' => $classic_id, 'blocks' => $checkout ) ) . PHP_EOL;
