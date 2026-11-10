<?php
namespace SmartCheckoutRules\Admin;

use SmartCheckoutRules\Context\CheckoutContextFactory;
use SmartCheckoutRules\Modules\CheckoutValidation;
use SmartCheckoutRules\Modules\OrderLimits;
use SmartCheckoutRules\Modules\PaymentRules;
use SmartCheckoutRules\Modules\ShippingRestrictions;
use SmartCheckoutRules\Rules\ConditionEvaluator;
use SmartCheckoutRules\Rules\RuleEngine;
use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

final class Simulator {
	public static function run( array $rules, array $settings, $scenario ): array {
		if ( ! is_array( $scenario ) || array_diff( array_keys( $scenario ), array( 'values', 'fields', 'gateways', 'rates', 'lines' ) ) ) {
			throw new \InvalidArgumentException( 'scenario' );
		}
		$values  = self::values( $scenario['values'] ?? array() );
		$values += array(
			'cart.currency'     => get_woocommerce_currency(),
			'customer.roles'    => array( 'guest' ),
			'cart.virtual_only' => false,
		);
		$fields  = self::fields( $scenario['fields'] ?? array() );
		$lines   = self::lines( $scenario['lines'] ?? array() );
		if ( $lines ) {
			$values  = CheckoutContextFactory::line_values( $lines, 'cart' ) + $values;
			$values += CheckoutContextFactory::line_values( $lines, 'package' );
			foreach ( array_diff( RuleSchema::metrics(), array( 'quantity' ) ) as $metric ) {
				$values[ 'cart.' . $metric ] = array_sum( array_column( $lines, $metric ) );
			}
		}
		$values                            += array(
			'cart.quantity'                         => 0,
			'cart.subtotal_after_discount_ex_tax'   => 0,
			'cart.subtotal_before_discount_ex_tax'  => 0,
			'cart.subtotal_after_discount_inc_tax'  => 0,
			'cart.subtotal_before_discount_inc_tax' => 0,
		);
		$values['checkout.company_present'] = '' !== trim( $fields['billing_company'] ?? '' );
		foreach ( array( 'country', 'state' ) as $key ) {
			$address                           = empty( $values['cart.virtual_only'] ) && ! empty( $values['shipping.country'] ) ? 'shipping' : 'billing';
			$values[ 'destination.' . $key ] ??= $values[ $address . '.' . $key ] ?? '';
		}

		$engine  = new RuleEngine( $rules, $settings['modules'], new ConditionEvaluator() );
		$context = array(
			'values'         => $values,
			'fields'         => $fields,
			'lines'          => $lines,
			'decimals'       => wc_get_price_decimals(),
			'store_currency' => get_option( 'woocommerce_currency' ),
			'needs_shipping' => ! $values['cart.virtual_only'],
		);
		$matched = array();
		foreach ( RuleSchema::MODULES as $module ) {
			$matched[ $module ] = $engine->matching( $module, $context );
		}
		$gateways = self::ids( $scenario['gateways'] ?? array() );
		$rates    = array();
		foreach ( self::ids( $scenario['rates'] ?? array() ) as $id ) {
			$parts        = explode( ':', $id );
			$rates[ $id ] = array(
				'method'   => $parts[0],
				'instance' => $parts[1] ?? '0',
			);
		}
		$shipping          = ShippingRestrictions::evaluate( $matched['shipping'], $rates );
		$payment           = PaymentRules::evaluate( $matched['payment'], $gateways );
		$limits            = OrderLimits::violations( $matched['limits'], $context );
		$validation        = CheckoutValidation::violations( $matched['validation'], $context );
		$selected_payment  = $values['checkout.payment_method'] ?? '';
		$selected_shipping = $values['checkout.shipping_methods'] ?? array();
		$blocked           = ( $gateways && ! $payment['available'] ) || isset( $payment['hidden'][ $selected_payment ] ) ||
			( $context['needs_shipping'] && ( ( $rates && ! $shipping['available'] ) || array_intersect( $selected_shipping, array_keys( $shipping['hidden'] ) ) ) ) ||
			$limits || $validation;
		return array(
			'blocked'    => (bool) $blocked,
			'matched'    => array_map(
				static fn( $group ) => array_map(
					static fn( $rule ) => array(
						'id'   => $rule['id'],
						'name' => $rule['name'],
					),
					$group
				),
				$matched
			),
			'payment'    => $payment,
			'shipping'   => array(
				'available' => array_keys( $shipping['available'] ),
				'hidden'    => $shipping['hidden'],
			),
			'limits'     => $limits,
			'validation' => $validation,
		);
	}

	private static function values( $input ): array {
		$types = RuleSchema::fields();
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( $types ) ) ) {
			throw new \InvalidArgumentException( 'scenario.values' );
		}
		$out = array();
		foreach ( $input as $field => $value ) {
			$type = $types[ $field ];
			if ( in_array( $type, array( 'ids', 'keys', 'coupons', 'methods' ), true ) ) {
				$out[ $field ] = self::ids( $value );
			} elseif ( in_array( $type, array( 'decimal', 'integer' ), true ) ) {
				$out[ $field ] = self::amount( $value, 'integer' === $type );
			} elseif ( 'boolean' === $type && is_bool( $value ) ) {
				$out[ $field ] = $value;
			} elseif ( in_array( $type, array( 'key', 'code' ), true ) && is_string( $value ) && strlen( $value ) <= 128 ) {
				$out[ $field ] = 'code' === $type ? strtoupper( sanitize_text_field( $value ) ) : strtolower( sanitize_text_field( $value ) );
			} else {
				throw new \InvalidArgumentException( 'scenario.values' );
			}
		}
		return $out;
	}

	private static function fields( $input ): array {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( RuleSchema::checkout_fields() ) ) ) {
			throw new \InvalidArgumentException( 'scenario.fields' );
		}
		foreach ( $input as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 1024 ) {
				throw new \InvalidArgumentException( 'scenario.fields' );
			}
		}
		return array_map( 'sanitize_text_field', $input );
	}

	private static function lines( $input ): array {
		if ( ! RuleSchema::is_list( $input ) || count( $input ) > 50 ) {
			throw new \InvalidArgumentException( 'scenario.lines' );
		}
		$lines = array();
		foreach ( $input as $line ) {
			if ( ! is_array( $line ) || array_diff( array_keys( $line ), array_merge( array( 'products', 'categories', 'shipping_classes' ), RuleSchema::metrics() ) ) ) {
				throw new \InvalidArgumentException( 'scenario.lines' );
			}
			$out = array();
			foreach ( array( 'products', 'categories', 'shipping_classes' ) as $key ) {
				$out[ $key ] = self::ids( $line[ $key ] ?? array() );
			}
			foreach ( RuleSchema::metrics() as $metric ) {
				$out[ $metric ] = self::amount( $line[ $metric ] ?? '0', 'quantity' === $metric );
			}
			$lines[] = $out;
		}
		return $lines;
	}

	private static function amount( $value, bool $integer ) {
		if ( ! is_string( $value ) || ! preg_match( $integer ? '/^[0-9]{1,10}$/D' : '/^[0-9]{1,10}(?:\.[0-9]{1,8})?$/D', $value ) ) {
			throw new \InvalidArgumentException( 'scenario.amount' );
		}
		return $integer ? (int) $value : (float) $value;
	}

	private static function ids( $input ): array {
		if ( ! RuleSchema::is_list( $input ) || count( $input ) > 100 ) {
			throw new \InvalidArgumentException( 'scenario.list' );
		}
		foreach ( $input as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 128 || ! preg_match( '/^[^<>,\x00-\x1f]+$/D', $value ) ) {
				throw new \InvalidArgumentException( 'scenario.list' );
			}
		}
		return array_map( 'strtolower', $input );
	}
}
