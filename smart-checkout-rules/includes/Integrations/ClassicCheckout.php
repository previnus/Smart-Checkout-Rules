<?php
namespace SmartCheckoutRules\Integrations;

use SmartCheckoutRules\Rules\RuleEngine;

defined( 'ABSPATH' ) || exit;

final class ClassicCheckout {
	public function __construct( private CheckoutPolicy $policy, private RuleEngine $engine ) {}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 100, 2 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'notices' ), 20 );
		add_action( 'woocommerce_before_cart', array( $this, 'notices' ), 20 );
		if ( $this->engine->uses_field( 'scr_po_number' ) ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'fields' ) );
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_field' ), 10, 2 );
		}
	}

	public function fields( array $fields ): array {
		$fields['billing']['scr_po_number'] = array(
			'type'      => 'text',
			'label'     => __( 'Purchase order reference', 'smart-checkout-rules' ),
			'required'  => false,
			'maxlength' => 256,
			'priority'  => 130,
		);
		return $fields;
	}

	public function save_field( \WC_Order $order, array $data ): void {
		$value = $data['scr_po_number'] ?? '';
		if ( is_string( $value ) && '' !== $value ) {
			$order->update_meta_data( '_wc_other/smart-checkout-rules/po-number', mb_substr( $value, 0, 256, 'UTF-8' ) );
		}
	}

	public function validate( array $data, \WP_Error $errors ): void {
		if ( ! WC()->cart || ! empty( $data['woocommerce_checkout_update_totals'] ) ) {
			return;
		}
		$fields                      = array_filter( $data, 'is_string' );
		$fields['shipping_separate'] = ! empty( $data['ship_to_different_address'] ) && WC()->cart->needs_shipping_address();
		if ( ! $fields['shipping_separate'] ) {
			foreach ( $fields as $key => $value ) {
				if ( str_starts_with( $key, 'billing_' ) ) {
					$fields[ 'shipping_' . substr( $key, 8 ) ] = $value;
				}
			}
		}
		$this->policy->validate( $fields, $errors, WC()->cart->needs_payment() );
		$this->policy->shipping_errors( $errors, (array) ( $data['shipping_method'] ?? array() ) );
	}

	public function notices(): void {
		foreach ( $this->policy->preview_messages() as $message ) {
			wc_print_notice( esc_html( $message ), 'notice' );
		}
	}
}
