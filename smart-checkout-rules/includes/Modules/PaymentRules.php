<?php
namespace SmartCheckoutRules\Modules;

defined( 'ABSPATH' ) || exit;

final class PaymentRules {
	public static function evaluate( array $rules, array $available ): array {
		$hidden  = array();
		$allowed = array();
		foreach ( $rules as $rule ) {
			foreach ( $rule['actions'] as $action ) {
				$id = $action['gateway_id'];
				if ( 'hide_gateway' === $action['type'] ) {
					$hidden[ $id ][] = $rule['id'];
				} elseif ( in_array( $id, $available, true ) ) {
					$allowed[ $id ] = true;
				}
			}
		}
		return array(
			'available' => array_values( array_diff( $available, array_keys( $hidden ) ) ),
			'allowed'   => array_keys( array_diff_key( $allowed, $hidden ) ),
			'hidden'    => $hidden,
		);
	}
}
