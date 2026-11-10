<?php
namespace SmartCheckoutRules\Context;

defined( 'ABSPATH' ) || exit;

final class CheckoutContextFactory {
	public static function build( array $fields = array() ): ?array {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}
		$cart     = WC()->cart;
		$customer = $cart->get_customer();
		$lines    = self::lines( $cart->get_cart() );
		$values   = self::line_values( $lines, 'cart' );
		$values['cart.subtotal_before_discount_ex_tax']  = $cart->get_subtotal();
		$values['cart.subtotal_before_discount_inc_tax'] = $cart->get_subtotal() + $cart->get_subtotal_tax();
		$values['cart.subtotal_after_discount_ex_tax']   = $cart->get_cart_contents_total();
		$values['cart.subtotal_after_discount_inc_tax']  = $cart->get_cart_contents_total() + $cart->get_cart_contents_tax();
		$values['cart.total']                            = $cart->get_total( 'edit' );
		$values['cart.coupons']                          = array_map( 'strtolower', $cart->get_applied_coupons() );
		$values['cart.virtual_only']                     = ! in_array( false, array_column( $lines, 'virtual' ), true );
		$values['cart.currency']                         = get_woocommerce_currency();
		$user                     = wp_get_current_user();
		$values['customer.roles'] = $user->exists() ? array_values( $user->roles ) : array( 'guest' );
		foreach ( array( 'billing', 'shipping' ) as $address ) {
			foreach ( array( 'country', 'state' ) as $key ) {
				$getter                    = 'get_' . $address . '_' . $key;
				$values[ "$address.$key" ] = strtoupper( (string) ( $fields[ $address . '_' . $key ] ?? ( $customer ? $customer->$getter() : '' ) ) );
			}
		}
		$destination = $cart->needs_shipping() && $values['shipping.country'] ? 'shipping' : 'billing';
		foreach ( array( 'country', 'state' ) as $key ) {
			$values[ 'destination.' . $key ] = $values[ "$destination.$key" ];
		}
		$values['checkout.company_present']   = '' !== trim( (string) ( $fields['billing_company'] ?? ( $customer ? $customer->get_billing_company() : '' ) ) );
		$values['checkout.shipping_separate'] = $fields['shipping_separate'] ?? false;
		$values['checkout.shipping_methods']  = array_map( 'strtolower', array_filter( (array) ( WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array() ), 'is_string' ) );
		$values['checkout.payment_method']    = strtolower( (string) ( $fields['payment_method'] ?? ( WC()->session ? WC()->session->get( 'chosen_payment_method', '' ) : '' ) ) );
		return array(
			'values'         => $values,
			'fields'         => $fields,
			'lines'          => $lines,
			'decimals'       => wc_get_price_decimals(),
			'store_currency' => (string) get_option( 'woocommerce_currency' ),
			'needs_shipping' => $cart->needs_shipping(),
		);
	}

	public static function for_package( array $context, array $package ): array {
		foreach ( array( 'country', 'state' ) as $key ) {
			if ( isset( $package['destination'][ $key ] ) ) {
				$context['values'][ 'destination.' . $key ] = strtoupper( (string) $package['destination'][ $key ] );
				$context['values'][ 'shipping.' . $key ]    = $context['values'][ 'destination.' . $key ];
			}
		}
		$context['values'] += self::line_values( self::lines( $package['contents'] ?? array() ), 'package' );
		return $context;
	}

	private static function lines( array $items ): array {
		$lines = array();
		foreach ( $items as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$parent     = $product->get_parent_id();
			$categories = $parent ? wc_get_product_cat_ids( $parent ) : wc_get_product_cat_ids( $product->get_id() );
			$lines[]    = array(
				'products'                         => array_values( array_unique( array_filter( array( (string) $product->get_id(), (string) $parent ), static fn( $id ) => '0' !== $id ) ) ),
				'categories'                       => array_map( 'strval', $categories ),
				'shipping_classes'                 => $product->get_shipping_class() ? array( $product->get_shipping_class() ) : array(),
				'virtual'                          => $product->is_virtual(),
				'quantity'                         => (int) $item['quantity'],
				'subtotal_after_discount_ex_tax'   => (float) ( $item['line_total'] ?? 0 ),
				'subtotal_before_discount_ex_tax'  => (float) ( $item['line_subtotal'] ?? 0 ),
				'subtotal_after_discount_inc_tax'  => (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ),
				'subtotal_before_discount_inc_tax' => (float) ( $item['line_subtotal'] ?? 0 ) + (float) ( $item['line_subtotal_tax'] ?? 0 ),
			);
		}
		return $lines;
	}

	public static function line_values( array $lines, string $scope ): array {
		$values = array(
			"$scope.products"         => array(),
			"$scope.categories"       => array(),
			"$scope.shipping_classes" => array(),
			"$scope.quantity"         => 0,
		);
		foreach ( $lines as $line ) {
			foreach ( array( 'products', 'categories', 'shipping_classes' ) as $key ) {
				foreach ( $line[ $key ] as $value ) {
					$values[ "$scope.$key" ][ $value ] = true;
				}
			}
			$values[ "$scope.quantity" ] += $line['quantity'];
		}
		foreach ( array( 'products', 'categories', 'shipping_classes' ) as $key ) {
			$values[ "$scope.$key" ] = array_map( 'strval', array_keys( $values[ "$scope.$key" ] ) );
		}
		return $values;
	}
}
