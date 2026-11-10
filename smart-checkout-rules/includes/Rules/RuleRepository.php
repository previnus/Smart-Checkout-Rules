<?php
namespace SmartCheckoutRules\Rules;

defined( 'ABSPATH' ) || exit;

final class RuleRepository {
	const OPTION   = 'scr_rules_v2';
	const LEGACY   = 'scr_rules_v1';
	const DEFAULTS = array(
		'modules'             => array(
			'payment'    => true,
			'shipping'   => true,
			'limits'     => true,
			'validation' => true,
		),
		'logging'             => false,
		'delete_on_uninstall' => false,
	);

	private ?array $snapshot = null;
	private ?string $raw     = null;

	public function snapshot(): array {
		if ( null !== $this->snapshot ) {
			return $this->snapshot;
		}
		$raw       = get_option( self::OPTION, null );
		$this->raw = is_string( $raw ) ? $raw : null;
		$stored    = $this->raw ? json_decode( $this->raw, true, 20 ) : $this->legacy();
		$invalid   = 0;
		if ( ! is_array( $stored ) || 1 !== ( $stored['schema_version'] ?? null ) ) {
			$stored = array();
			++$invalid;
		}
		$rules = array();
		foreach ( array_slice( (array) ( $stored['rules'] ?? array() ), 0, RuleSchema::MAX_RULES ) as $rule ) {
			try {
				$rule = RuleSchema::rule( $rule );
				if ( ! $rule['id'] || isset( $rules[ $rule['id'] ] ) ) {
					throw new \InvalidArgumentException( 'id' );
				}
				$rules[ $rule['id'] ] = $rule;
			} catch ( \InvalidArgumentException $exception ) {
				++$invalid;
			}
		}
		$settings = self::DEFAULTS;
		try {
			$settings = self::settings( $stored['settings'] ?? self::DEFAULTS );
		} catch ( \InvalidArgumentException $exception ) {
			++$invalid;
		}
		$this->snapshot = array(
			'schema_version' => 1,
			'rules'          => array_values( $rules ),
			'settings'       => $settings,
			'invalid'        => $invalid + (int) ( $stored['invalid_legacy'] ?? 0 ),
			'revision'       => hash( 'sha256', $this->raw ?? wp_json_encode( $stored ) ),
		);
		return $this->snapshot;
	}

	public static function settings( $input ): array {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( self::DEFAULTS ) ) ||
			! isset( $input['modules'] ) || ! is_array( $input['modules'] ) ||
			array_diff( array_keys( $input['modules'] ), RuleSchema::MODULES ) ) {
			throw new \InvalidArgumentException( 'settings' );
		}
		$settings            = array_replace( self::DEFAULTS, $input );
		$settings['modules'] = array_replace( self::DEFAULTS['modules'], $input['modules'] );
		foreach ( array_merge( $settings['modules'], array( $settings['logging'], $settings['delete_on_uninstall'] ) ) as $value ) {
			if ( ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( 'settings' );
			}
		}
		return $settings;
	}

	/**
	 * Store one revision atomically. Only this plugin owns the non-autoloaded option.
	 */
	public function save( string $revision, array $rules, array $settings ): bool {
		global $wpdb;
		$current = $this->snapshot();
		if ( ! hash_equals( $current['revision'], $revision ) ) {
			return false;
		}
		$data = wp_json_encode(
			array(
				'schema_version' => 1,
				'rules'          => $rules,
				'settings'       => $settings,
				'write_id'       => wp_generate_uuid4(),
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $data ) || strlen( $data ) > 1500000 ) {
			throw new \InvalidArgumentException( 'size' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Options API has no compare-and-swap. Invalidate the two relevant caches below.
		if ( null === $this->raw ) {
			$saved = $wpdb->query(
				$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION, $data, 'no' )
			);
		} else {
			$saved = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => $data ),
				array(
					'option_name'  => self::OPTION,
					'option_value' => $this->raw,
				),
				array( '%s' ),
				array( '%s', '%s' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$this->snapshot = null;
		return 1 === $saved;
	}

	private function legacy(): array {
		$rules   = array();
		$invalid = 0;
		$rows    = get_option( self::LEGACY, array() );
		foreach ( array_slice( (array) $rows, 0, RuleSchema::MAX_RULES ) as $index => $row ) {
			try {
				$rules[] = $this->legacy_rule( $row, (int) $index );
			} catch ( \InvalidArgumentException $exception ) {
				++$invalid;
			}
		}
		return array(
			'schema_version' => 1,
			'rules'          => $rules,
			'settings'       => self::DEFAULTS,
			'invalid_legacy' => $invalid,
		);
	}

	private function legacy_rule( $row, int $index ): array {
		$type   = is_array( $row ) ? ( $row['action'] ?? '' ) : '';
		$target = $row['target'] ?? '';
		switch ( $type ) {
			case 'payment':
				$module = 'payment';
				$action = array(
					'type'       => 'hide_gateway',
					'gateway_id' => $target,
				);
				break;
			case 'shipping':
				$module = 'shipping';
				$action = array(
					'type'      => 'hide_rate',
					'method_id' => $target,
				);
				break;
			case 'minimum':
				$module = 'limits';
				$action = array(
					'type'     => 'minimum',
					'metric'   => 'subtotal_before_discount_ex_tax',
					'amount'   => $this->legacy_amount( $row['minimum'] ?? null ),
					'currency' => '*',
				);
				break;
			case 'required':
				$module = 'validation';
				$action = array(
					'type'  => 'required',
					'field' => $target,
				);
				break;
			default:
				throw new \InvalidArgumentException( 'legacy.action' );
		}

		$conditions = array();
		foreach ( array(
			'country' => 'destination.country',
			'role'    => 'customer.roles',
		) as $key => $field ) {
			if ( ! empty( $row[ $key ] ) ) {
				$conditions[] = array(
					'field'    => $field,
					'operator' => 'in',
					'value'    => array( $row[ $key ] ),
				);
			}
		}
		if ( in_array( $row['operator'] ?? '', array( 'lt', 'gte' ), true ) ) {
			$conditions[] = array(
				'field'    => 'cart.subtotal_before_discount_ex_tax',
				'operator' => $row['operator'],
				'value'    => $this->legacy_amount( $row['threshold'] ?? null ),
			);
		}
		if ( 'required' === $type && is_string( $target ) && str_starts_with( $target, 'shipping_' ) ) {
			$conditions[] = array(
				'field'    => 'checkout.shipping_separate',
				'operator' => 'eq',
				'value'    => true,
			);
		}
		// Stable IDs keep an unmodified migration reproducible across concurrent requests.
		$id = sprintf( '%08x-0000-4000-8000-000000000001', $index + 1 );
		return RuleSchema::rule(
			array(
				'schema_version' => 1,
				'id'             => $id,
				'name'           => ( $row['name'] ?? '' ) ?: sprintf( 'Legacy rule %d', $index + 1 ),
				'module'         => $module,
				'enabled'        => ! empty( $row['enabled'] ),
				'priority'       => 100,
				'conditions'     => array( 'all' => $conditions ),
				'actions'        => array( $action ),
				'message'        => in_array( $type, array( 'minimum', 'required' ), true ) ? ( $row['message'] ?? '' ) : '',
			)
		);
	}

	private function legacy_amount( $value ): string {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
			throw new \InvalidArgumentException( 'legacy.amount' );
		}
		return number_format( (float) $value, 8, '.', '' );
	}
}
