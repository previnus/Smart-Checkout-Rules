<?php
namespace SmartCheckoutRules\Modules;

use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

final class CheckoutValidation {
	public static function violations( array $rules, array $context ): array {
		$violations = array();
		foreach ( $rules as $rule ) {
			foreach ( $rule['actions'] as $action ) {
				$field = $action['field'] ?? '';
				if ( ! $context['needs_shipping'] && str_starts_with( $field, 'shipping_' ) ) {
					continue;
				}
				$value = trim( (string) ( $context['fields'][ $field ] ?? '' ) );
				if ( self::valid( $action, $field, $value, $context ) ) {
					continue;
				}
				$key                  = $field ? $field . '_scr' : 'scr_' . $rule['id'];
				$violations[ $key ] ??= array(
					'key'     => $key,
					'field'   => $field,
					'message' => $rule['message'] ?: self::message( $action ),
				);
			}
		}
		return array_values( $violations );
	}

	private static function valid( array $action, string $field, string $value, array $context ): bool {
		$type = $action['type'];
		if ( 'reject' === $type ) {
			return false;
		}
		if ( 'required' === $type || '' === $value ) {
			return 'required' !== $type || '' !== $value;
		}
		if ( 'length' === $type ) {
			$length = mb_strlen( $value, 'UTF-8' );
			return $length >= $action['min'] && $length <= $action['max'];
		}
		if ( 'email_allow' === $type || 'email_block' === $type ) {
			if ( ! is_email( $value ) ) {
				return false;
			}
			$listed = in_array( strtolower( substr( strrchr( $value, '@' ), 1 ) ), $action['domains'], true );
			return 'email_allow' === $type ? $listed : ! $listed;
		}
		if ( 'pattern' === $type ) {
			return true === RuleSchema::matches_pattern( $action['pattern'], $value );
		}
		if ( 'postcode' === $type ) {
			$address = str_starts_with( $field, 'shipping_' ) ? 'shipping' : 'billing';
			$country = $context['values'][ $address . '.country' ] ?? '';
			return ! $country || \WC_Validation::is_postcode( $value, $country );
		}
		return \WC_Validation::is_phone( $value );
	}

	private static function message( array $action ): string {
		$field = RuleSchema::checkout_fields()[ $action['field'] ?? '' ] ?? '';
		if ( 'reject' === $action['type'] ) {
			return __( 'This order cannot be placed with the current checkout details.', 'smart-checkout-rules' );
		}
		if ( 'required' === $action['type'] ) {
			/* translators: %s: checkout field label. */
			return sprintf( __( 'Please complete the %s field.', 'smart-checkout-rules' ), $field );
		}
		/* translators: %s: checkout field label. */
		return sprintf( __( 'Please enter a valid value for %s.', 'smart-checkout-rules' ), $field );
	}
}
