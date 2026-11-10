<?php
namespace SmartCheckoutRules\Rules;

defined( 'ABSPATH' ) || exit;

final class RuleEngine {
	private array $modules = array();

	public function __construct( array $rules, array $modules, private ConditionEvaluator $evaluator ) {
		usort( $rules, static fn( $left, $right ) => ( $left['priority'] <=> $right['priority'] ) ?: strcmp( $left['id'], $right['id'] ) );
		foreach ( $rules as $rule ) {
			if ( $rule['enabled'] && ( $modules[ $rule['module'] ] ?? false ) ) {
				$this->modules[ $rule['module'] ][] = $rule;
			}
		}
	}

	public function has( string $module ): bool {
		return ! empty( $this->modules[ $module ] );
	}

	public function matching( string $module, array $context ): array {
		$matched = array();
		foreach ( $this->modules[ $module ] ?? array() as $rule ) {
			if ( $this->evaluator->matches( $rule['conditions'], $context['values'], $context['decimals'] ) ) {
				$matched[] = $rule;
				if ( $rule['stop_processing'] ) {
					break;
				}
			}
		}
		return $matched;
	}

	public function uses_field( string $field ): bool {
		foreach ( $this->modules['validation'] ?? array() as $rule ) {
			foreach ( $rule['actions'] as $action ) {
				if ( ( $action['field'] ?? '' ) === $field ) {
					return true;
				}
			}
		}
		return false;
	}
}
