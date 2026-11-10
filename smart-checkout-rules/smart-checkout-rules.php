<?php
/**
 * Plugin Name: Smart Checkout Rules
 * Plugin URI: https://previn.me
 * Description: Conditional payment methods, checkout validation, order limits, and shipping restrictions for WooCommerce.
 * Version: 2.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 10.5
 * WC tested up to: 11.2
 * Author: Previn Kalisetty Appadu
 * Author URI: https://previn.me
 * License: GPL-2.0-or-later
 * Text Domain: smart-checkout-rules
 */

defined( 'ABSPATH' ) || exit;

define( 'SCR_PLUGIN_FILE', __FILE__ );
define( 'SCR_VERSION', '2.0.0' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SmartCheckoutRules\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$name = substr( $class, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z]+(?:\\\\[A-Za-z]+)*$/D', $name ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', $name ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

add_action( 'before_woocommerce_init', array( \SmartCheckoutRules\Compatibility::class, 'declare_features' ) );
add_action( 'plugins_loaded', array( \SmartCheckoutRules\Plugin::class, 'boot' ) );
