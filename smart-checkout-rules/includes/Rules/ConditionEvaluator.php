<?php
namespace SmartCheckoutRules\Rules;

defined( 'ABSPATH' ) || exit;

final class ConditionEvaluator {
	public function matches( array $condition, array $values, int $decimals ): bool {
		if ( isset( $condition['all'] ) ) {
			foreach ( $condition['all'] as $child ) {
				if ( ! $this->matches( $child, $values, $decimals ) ) {
					return false;
				}
			}
			return true;
		}
		if ( isset( $condition['any'] ) ) {
			foreach ( $condition['any'] as $child ) {
				if ( $this->matches( $child, $values, $decimals ) ) {
					return true;
				}
			}
			return false;
		}

		$field    = $condition['field'];
		$operator = $condition['operator'];
		$expected = $condition['value'];
		$actual   = $values[ $field ] ?? null;
		$empty    = null === $actual || '' === $actual || array() === $actual;
		if ( 'empty' === $operator || 'not_empty' === $operator ) {
			return 'empty' === $operator ? $empty : ! $empty;
		}
		if ( $empty ) {
			return false;
		}
		$type = RuleSchema::fields()[ $field ];
		if ( 'boolean' === $type ) {
			return 'eq' === $operator ? $actual === $expected : $actual !== $expected;
		}
		if ( in_array( $operator, array( 'in', 'not_in' ), true ) ) {
			$found = (bool) array_intersect( (array) $actual, $expected );
			return 'in' === $operator ? $found : ! $found;
		}
		$scale    = 'integer' === $type ? 0 : $decimals;
		$actual   = self::precision( $actual, $scale );
		$expected = self::precision( $expected, $scale );
		return match ( $operator ) {
			'eq' => $actual === $expected,
			'neq' => $actual !== $expected,
			'lt' => $actual < $expected,
			'lte' => $actual <= $expected,
			'gt' => $actual > $expected,
			'gte' => $actual >= $expected,
			default => false,
		};
	}

	public static function precision( $amount, int $decimals ): float {
		return round( (float) $amount, $decimals, PHP_ROUND_HALF_UP );
	}
}
