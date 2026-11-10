<?php
namespace SmartCheckoutRules\Modules;

use SmartCheckoutRules\Rules\ConditionEvaluator;

defined( 'ABSPATH' ) || exit;

final class OrderLimits {
	public static function violations( array $rules, array $context ): array {
		$violations = array();
		$currency   = $context['values']['cart.currency'];
		foreach ( $rules as $rule ) {
			foreach ( $rule['actions'] as $index => $action ) {
				$metric          = $action['metric'];
				$is_quantity     = 'quantity' === $metric;
				$target_currency = 'store' === $action['currency'] ? $context['store_currency'] : $action['currency'];
				if ( ! $is_quantity && '*' !== $target_currency && $target_currency !== $currency ) {
					continue;
				}
				$decimals = $is_quantity ? 0 : $context['decimals'];
				$actual   = ConditionEvaluator::precision( self::measure( $action, $context ), $decimals );
				$target   = ConditionEvaluator::precision( $action['amount'], $decimals );
				$is_min   = 'minimum' === $action['type'];
				if ( $is_min ? $actual >= $target : $actual <= $target ) {
					continue;
				}
				$bound        = self::format( $target, $is_quantity, $currency, $decimals );
				$message      = $rule['message'] ?: self::message( $is_quantity, $is_min, $bound );
				$violations[] = array(
					'key'      => 'scr_limit_' . $rule['id'] . '_' . $index,
					'message'  => $message,
					'progress' => $is_min && $action['progress'] ? self::format( $target - $actual, $is_quantity, $currency, $decimals ) : '',
				);
			}
		}
		return $violations;
	}

	private static function measure( array $action, array $context ): float {
		$metric = $action['metric'];
		if ( ! $action['scope'] ) {
			return (float) ( $context['values'][ 'cart.' . $metric ] ?? 0 );
		}
		$sum = 0.0;
		foreach ( $context['lines'] as $line ) {
			foreach ( $action['scope'] as $key => $ids ) {
				if ( ! array_intersect( $line[ $key ], $ids ) ) {
					continue 2;
				}
			}
			$sum += $line[ $metric ];
		}
		return $sum;
	}

	private static function format( float $amount, bool $quantity, string $currency, int $decimals ): string {
		if ( $quantity ) {
			return number_format_i18n( $amount );
		}
		return html_entity_decode(
			wp_strip_all_tags(
				wc_price(
					$amount,
					array(
						'currency' => $currency,
						'decimals' => $decimals,
					)
				)
			),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	private static function message( bool $quantity, bool $minimum, string $bound ): string {
		if ( $quantity && $minimum ) {
			/* translators: %s: number of units. */
			return sprintf( __( 'Add at least %s qualifying units before checking out.', 'smart-checkout-rules' ), $bound );
		}
		if ( $quantity ) {
			/* translators: %s: number of units. */
			return sprintf( __( 'Your order can contain at most %s qualifying units.', 'smart-checkout-rules' ), $bound );
		}
		if ( $minimum ) {
			/* translators: %s: price with currency. */
			return sprintf( __( 'Your qualifying merchandise subtotal must be at least %s.', 'smart-checkout-rules' ), $bound );
		}
		/* translators: %s: price with currency. */
		return sprintf( __( 'Your qualifying merchandise subtotal can be at most %s.', 'smart-checkout-rules' ), $bound );
	}
}
