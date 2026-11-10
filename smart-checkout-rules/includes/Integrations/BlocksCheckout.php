<?php
namespace SmartCheckoutRules\Integrations;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use SmartCheckoutRules\Rules\RuleEngine;
use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

final class BlocksCheckout {
	private ?string $submitted_method = null;
	private array $previous_rates     = array();

	public function __construct( private CheckoutPolicy $policy, private RuleEngine $engine ) {}

	public function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'refresh_cart' ), 100, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'track_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( $this, 'validate' ), 100, 2 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'cart_extension' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->cart_extension();
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		if ( $this->engine->uses_field( 'scr_po_number' ) ) {
			add_action( 'woocommerce_init', array( $this, 'register_field' ) );
		}
	}

	public function refresh_cart( \WC_Customer $customer, \WP_REST_Request $request ): void {
		$this->previous_rates = array();
		if ( ! self::is_cart_submission( $request ) || ! WC()->cart || ! $customer->get_changes() ) {
			return;
		}
		if ( $this->engine->has( 'shipping' ) ) {
			$this->previous_rates = (array) WC()->session->get( 'chosen_shipping_methods' );
		}
		if ( $this->engine->has( 'shipping' ) || $this->engine->has( 'payment' ) || $this->engine->has( 'limits' ) ) {
			// Store API can calculate before updating the submitted address. Refresh before it builds the order.
			WC()->cart->calculate_totals();
		}
	}

	public function track_checkout( \WC_Order $order, \WP_REST_Request $request ): void {
		$this->submitted_method = null;
		if ( self::is_cart_submission( $request ) ) {
			$this->submitted_method = (string) $request->get_param( 'payment_method' );
		}
	}

	public function validate( \WC_Order $order, \WP_Error $errors ): void {
		if ( null === $this->submitted_method || ! WC()->cart ) {
			return;
		}
		$billing                     = $order->get_address( 'billing' );
		$shipping                    = $order->get_address( 'shipping' );
		$fields                      = array(
			'payment_method' => $this->submitted_method ?: $order->get_payment_method(),
			'scr_po_number'  => (string) $order->get_meta( '_wc_other/' . RuleSchema::PO_FIELD ),
		);
		$this->submitted_method      = null;
		$shipping                    = empty( $shipping['country'] ) ? $billing : $shipping;
		$fields['shipping_separate'] = false;
		foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1', 'address_2' ) as $key ) {
			if ( ( $billing[ $key ] ?? '' ) !== ( $shipping[ $key ] ?? '' ) ) {
				$fields['shipping_separate'] = true;
				break;
			}
		}
		foreach ( array(
			'billing'  => $billing,
			'shipping' => $shipping,
		) as $type => $address ) {
			foreach ( $address as $key => $value ) {
				if ( is_string( $value ) ) {
					$fields[ $type . '_' . $key ] = $value;
				}
			}
		}
		$this->policy->validate( $fields, $errors, WC()->cart->needs_payment() );
		$this->policy->shipping_errors( $errors, $this->previous_rates );
		$this->previous_rates = array();
	}

	private static function is_cart_submission( \WP_REST_Request $request ): bool {
		return 'POST' === $request->get_method() && (bool) preg_match( '~/wc/store/v[0-9]+/checkout/?$~D', $request->get_route() );
	}

	public function register_field(): void {
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => RuleSchema::PO_FIELD,
				'label'             => __( 'Purchase order reference', 'smart-checkout-rules' ),
				'location'          => 'order',
				'type'              => 'text',
				'required'          => false,
				'attributes'        => array( 'maxLength' => 256 ),
				'sanitize_callback' => static fn( $value ) => mb_substr( sanitize_text_field( $value ), 0, 256, 'UTF-8' ),
			)
		);
	}

	public function cart_extension(): void {
		if ( ! $this->engine->has( 'payment' ) && ! $this->engine->has( 'shipping' ) && ! $this->engine->has( 'limits' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => 'smart-checkout-rules',
				'data_callback'   => fn() => array( 'messages' => $this->policy->preview_messages() ),
				'schema_callback' => static fn() => array(
					'messages' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'readonly' => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	public function assets(): void {
		if ( ( is_checkout() || is_cart() ) && ! is_wc_endpoint_url() && ( $this->engine->has( 'limits' ) || $this->engine->has( 'shipping' ) || $this->engine->has( 'payment' ) ) ) {
			wp_enqueue_script( 'scr-blocks', plugins_url( 'assets/frontend/blocks.js', SCR_PLUGIN_FILE ), array( 'wp-data', 'wp-notices', 'wc-blocks-data-store' ), SCR_VERSION, true );
		}
	}
}
