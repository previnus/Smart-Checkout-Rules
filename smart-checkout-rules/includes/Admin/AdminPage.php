<?php
namespace SmartCheckoutRules\Admin;

use SmartCheckoutRules\Rules\RuleRepository;
use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

final class AdminPage {
	public function register(): void {
		$page = add_submenu_page(
			'woocommerce',
			__( 'Smart Checkout Rules', 'smart-checkout-rules' ),
			__( 'Checkout Rules', 'smart-checkout-rules' ),
			'manage_woocommerce',
			'scr-rules',
			array( $this, 'page' )
		);
		if ( $page ) {
			add_action(
				'admin_enqueue_scripts',
				function ( $hook ) use ( $page ): void {
					if ( $page === $hook ) {
						$this->assets();
					}
				}
			);
		}
	}

	public function assets(): void {
		wp_enqueue_script( 'scr-admin', plugins_url( 'assets/admin/admin.js', SCR_PLUGIN_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), SCR_VERSION, true );
		wp_set_script_translations( 'scr-admin', 'smart-checkout-rules', plugin_dir_path( SCR_PLUGIN_FILE ) . 'languages' );
		wp_add_inline_script( 'scr-admin', 'window.scrAdmin = ' . wp_json_encode( $this->config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		wp_enqueue_style( 'scr-admin', plugins_url( 'assets/admin/admin.css', SCR_PLUGIN_FILE ), array(), SCR_VERSION );
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Smart Checkout Rules', 'smart-checkout-rules' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set payment, validation, order limit, and shipping rules. Use the simulator to test your changes before saving.', 'smart-checkout-rules' ) . '</p>';
		echo '<div id="scr-admin"><noscript>' . esc_html__( 'Enable JavaScript to manage checkout rules.', 'smart-checkout-rules' ) . '</noscript></div></div>';
	}

	private function config(): array {
		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$gateways[ $id ] = wp_strip_all_tags( $gateway->get_method_title() );
		}
		$shipping = array();
		$zones    = \WC_Shipping_Zones::get_zones();
		$zones[]  = array(
			'zone_name'        => __( 'Locations outside your other zones', 'smart-checkout-rules' ),
			'shipping_methods' => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		);
		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				$shipping[ $method->id . ':' . $method->get_instance_id() ] = $zone['zone_name'] . ' / ' . $method->get_title();
			}
		}
		$roles = array( 'guest' => __( 'Guest', 'smart-checkout-rules' ) );
		foreach ( wp_roles()->roles as $id => $role ) {
			$roles[ $id ] = translate_user_role( $role['name'] );
		}
		return array(
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'fields'         => RuleSchema::fields(),
			'operators'      => RuleSchema::operators(),
			'actions'        => RuleSchema::action_types(),
			'checkoutFields' => RuleSchema::checkout_fields(),
			'metrics'        => RuleSchema::metrics(),
			'gateways'       => $gateways,
			'shipping'       => $shipping,
			'roles'          => $roles,
			'currency'       => get_option( 'woocommerce_currency' ),
			'maxRules'       => RuleSchema::MAX_RULES,
			'defaults'       => RuleRepository::DEFAULTS,
			'shippingFields' => array_values( array_filter( array_keys( RuleSchema::fields() ), static fn( $field ) => RuleSchema::field_allowed( 'shipping', $field ) ) ),
			'paymentFields'  => array_values( array_filter( array_keys( RuleSchema::fields() ), static fn( $field ) => RuleSchema::field_allowed( 'payment', $field ) ) ),
		);
	}
}
