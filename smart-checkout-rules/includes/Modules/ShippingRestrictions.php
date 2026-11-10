<?php
namespace SmartCheckoutRules\Modules;

defined( 'ABSPATH' ) || exit;

final class ShippingRestrictions {
	public static function evaluate( array $rules, array $rates ): array {
		$hidden = array();
		foreach ( $rules as $rule ) {
			$quote_targets = array_column( array_filter( $rule['actions'], static fn( $action ) => 'quote_only' === $action['type'] ), 'method_id' );
			foreach ( $rates as $id => $rate ) {
				// A quote-only rule is applied even when its configured carrier returns no rate.
				if ( $quote_targets && ! self::matches( $quote_targets, (string) $id, $rate ) ) {
					$hidden[ $id ][] = $rule['id'];
				}
				foreach ( $rule['actions'] as $action ) {
					if ( 'hide_rate' === $action['type'] && self::matches( array( $action['method_id'] ), (string) $id, $rate ) ) {
						$hidden[ $id ][] = $rule['id'];
					}
				}
			}
		}
		return array(
			'available' => array_diff_key( $rates, $hidden ),
			'hidden'    => $hidden,
		);
	}

	private static function matches( array $targets, string $id, array $rate ): bool {
		return (bool) array_intersect( $targets, array( strtolower( $id ), $rate['method'], $rate['method'] . ':' . $rate['instance'] ) );
	}
}
