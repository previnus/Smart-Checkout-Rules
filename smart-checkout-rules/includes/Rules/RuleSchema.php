<?php
namespace SmartCheckoutRules\Rules;

defined( 'ABSPATH' ) || exit;

final class RuleSchema {
	const MODULES   = array( 'payment', 'validation', 'limits', 'shipping' );
	const MAX_RULES = 500;
	const PO_FIELD  = 'smart-checkout-rules/po-number';

	public static function fields(): array {
		return array(
			'cart.subtotal_after_discount_ex_tax'   => 'decimal',
			'cart.subtotal_before_discount_ex_tax'  => 'decimal',
			'cart.subtotal_after_discount_inc_tax'  => 'decimal',
			'cart.subtotal_before_discount_inc_tax' => 'decimal',
			'cart.total'                            => 'decimal',
			'cart.quantity'                         => 'integer',
			'cart.products'                         => 'ids',
			'cart.categories'                       => 'ids',
			'cart.shipping_classes'                 => 'keys',
			'cart.coupons'                          => 'coupons',
			'cart.virtual_only'                     => 'boolean',
			'cart.currency'                         => 'code',
			'customer.roles'                        => 'keys',
			'billing.country'                       => 'code',
			'billing.state'                         => 'code',
			'shipping.country'                      => 'code',
			'shipping.state'                        => 'code',
			'destination.country'                   => 'code',
			'destination.state'                     => 'code',
			'checkout.company_present'              => 'boolean',
			'checkout.shipping_separate'            => 'boolean',
			'checkout.shipping_methods'             => 'methods',
			'checkout.payment_method'               => 'key',
			'package.products'                      => 'ids',
			'package.categories'                    => 'ids',
			'package.shipping_classes'              => 'keys',
			'package.quantity'                      => 'integer',
		);
	}

	public static function operators(): array {
		return array(
			'decimal' => array( 'eq', 'neq', 'lt', 'lte', 'gt', 'gte' ),
			'integer' => array( 'eq', 'neq', 'lt', 'lte', 'gt', 'gte' ),
			'ids'     => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'keys'    => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'coupons' => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'methods' => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'code'    => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'key'     => array( 'in', 'not_in', 'empty', 'not_empty' ),
			'boolean' => array( 'eq', 'neq' ),
		);
	}

	public static function checkout_fields(): array {
		return array(
			'billing_company'    => __( 'Billing company', 'smart-checkout-rules' ),
			'billing_email'      => __( 'Billing email', 'smart-checkout-rules' ),
			'billing_phone'      => __( 'Billing phone', 'smart-checkout-rules' ),
			'billing_postcode'   => __( 'Billing postcode', 'smart-checkout-rules' ),
			'billing_address_2'  => __( 'Billing address line 2', 'smart-checkout-rules' ),
			'shipping_company'   => __( 'Shipping company', 'smart-checkout-rules' ),
			'shipping_phone'     => __( 'Shipping phone', 'smart-checkout-rules' ),
			'shipping_postcode'  => __( 'Shipping postcode', 'smart-checkout-rules' ),
			'shipping_address_2' => __( 'Shipping address line 2', 'smart-checkout-rules' ),
			'scr_po_number'      => __( 'Purchase order reference', 'smart-checkout-rules' ),
		);
	}

	public static function action_types(): array {
		return array(
			'payment'    => array( 'hide_gateway', 'allow_gateway' ),
			'shipping'   => array( 'hide_rate', 'quote_only' ),
			'limits'     => array( 'minimum', 'maximum' ),
			'validation' => array( 'required', 'length', 'email_allow', 'email_block', 'pattern', 'postcode', 'phone', 'reject' ),
		);
	}

	public static function metrics(): array {
		return array( 'quantity', 'subtotal_after_discount_ex_tax', 'subtotal_before_discount_ex_tax', 'subtotal_after_discount_inc_tax', 'subtotal_before_discount_inc_tax' );
	}

	/** Validate and normalize a rule. Throws a field path without echoing submitted values. */
	public static function rule( $input ): array {
		self::keys( $input, array( 'schema_version', 'id', 'name', 'module', 'enabled', 'priority', 'stop_processing', 'conditions', 'actions', 'message', 'created_at', 'updated_at' ), 'rule' );
		$module = $input['module'] ?? null;
		self::expect( 1 === ( $input['schema_version'] ?? null ), 'schema_version' );
		self::expect( in_array( $module, self::MODULES, true ), 'module' );
		$id = $input['id'] ?? '';
		self::expect( is_string( $id ) && ( '' === $id || wp_is_uuid( $id, 4 ) ), 'id' );
		$priority = $input['priority'] ?? 100;
		self::expect( is_int( $priority ) && $priority >= 0 && $priority <= 100000, 'priority' );
		$enabled = $input['enabled'] ?? false;
		$stop    = $input['stop_processing'] ?? false;
		self::expect( is_bool( $enabled ) && is_bool( $stop ), 'enabled/stop_processing' );
		$actions = $input['actions'] ?? null;
		self::expect( self::is_list( $actions ) && count( $actions ) >= 1 && count( $actions ) <= 8, 'actions' );
		$normalized = array();
		foreach ( $actions as $i => $action ) {
			$normalized[] = self::action( $action, $module, 'actions.' . ( $i + 1 ) );
		}
		$count      = 0;
		$conditions = self::condition( $input['conditions'] ?? array( 'all' => array() ), $module, 0, $count, 'conditions' );
		self::expect( isset( $conditions['all'] ) || isset( $conditions['any'] ), 'conditions' );
		$name = self::plain( $input['name'] ?? '', 120, 'name' );
		self::expect( '' !== $name, 'name' );
		$out = array(
			'schema_version'  => 1,
			'id'              => $id,
			'name'            => $name,
			'module'          => $module,
			'enabled'         => $enabled,
			'priority'        => $priority,
			'stop_processing' => $stop,
			'conditions'      => $conditions,
			'actions'         => $normalized,
			'message'         => self::plain( $input['message'] ?? '', 480, 'message' ),
		);
		foreach ( array( 'created_at', 'updated_at' ) as $key ) {
			$value = $input[ $key ] ?? '';
			self::expect( is_string( $value ) && ( '' === $value || preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', $value ) ), $key );
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function condition( $node, string $module, int $depth, int &$count, string $path ): array {
		self::expect( is_array( $node ) && $depth <= 4 && ++$count <= 40, $path );
		foreach ( array( 'all', 'any' ) as $group ) {
			if ( array_key_exists( $group, $node ) ) {
				self::keys( $node, array( $group ), $path );
				self::expect( self::is_list( $node[ $group ] ) && count( $node[ $group ] ) <= 40, $path );
				self::expect( ! empty( $node[ $group ] ) || ( 0 === $depth && 'all' === $group ), $path );
				$children = array();
				foreach ( $node[ $group ] as $i => $child ) {
					$children[] = self::condition( $child, $module, $depth + 1, $count, $path . '.' . ( $i + 1 ) );
				}
				return array( $group => $children );
			}
		}
		self::keys( $node, array( 'field', 'operator', 'value' ), $path );
		$field    = $node['field'] ?? '';
		$operator = $node['operator'] ?? '';
		$type     = is_string( $field ) ? ( self::fields()[ $field ] ?? '' ) : '';
		self::expect( '' !== $type && in_array( $operator, self::operators()[ $type ], true ), $path );
		self::expect( self::field_allowed( $module, $field ), $path . '.field' );
		$value = $node['value'] ?? null;
		if ( in_array( $operator, array( 'empty', 'not_empty' ), true ) ) {
			self::expect( null === $value, $path . '.value' );
		} elseif ( in_array( $type, array( 'decimal', 'integer' ), true ) ) {
			$value = self::number( $value, 'integer' === $type, $path . '.value' );
		} elseif ( 'boolean' === $type ) {
			self::expect( is_bool( $value ), $path . '.value' );
		} else {
			$value = self::list_of( $value, $type, $path . '.value' );
		}
		return array(
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	public static function field_allowed( string $module, string $field ): bool {
		if ( 'payment' === $module && in_array( $field, array( 'checkout.payment_method', 'checkout.shipping_separate', 'checkout.company_present' ), true ) ) {
			return false;
		}
		if ( 'shipping' === $module && in_array( $field, array( 'cart.total', 'checkout.shipping_methods', 'checkout.payment_method', 'checkout.shipping_separate', 'checkout.company_present' ), true ) ) {
			return false;
		}
		return 'shipping' === $module || 0 !== strpos( $field, 'package.' );
	}

	private static function action( $action, string $module, string $path ): array {
		$type = is_array( $action ) ? ( $action['type'] ?? '' ) : '';
		self::expect( in_array( $type, self::action_types()[ $module ], true ), $path . '.type' );
		$out = array( 'type' => $type );
		if ( 'payment' === $module ) {
			self::keys( $action, array( 'type', 'gateway_id' ), $path );
			$out['gateway_id'] = self::identifier( $action['gateway_id'] ?? '', 'key', $path );
		} elseif ( 'shipping' === $module ) {
			self::keys( $action, array( 'type', 'method_id' ), $path );
			$out['method_id'] = self::identifier( $action['method_id'] ?? '', 'methods', $path );
		} elseif ( 'limits' === $module ) {
			self::keys( $action, array( 'type', 'metric', 'amount', 'currency', 'scope', 'progress' ), $path );
			$metric = $action['metric'] ?? 'subtotal_after_discount_ex_tax';
			self::expect( in_array( $metric, self::metrics(), true ), $path . '.metric' );
			$out['metric'] = $metric;
			$out['amount'] = self::number( $action['amount'] ?? null, 'quantity' === $metric, $path . '.amount' );
			$currency      = $action['currency'] ?? 'store';
			self::expect( is_string( $currency ) && ( in_array( $currency, array( 'store', '*' ), true ) || preg_match( '/^[A-Z]{3}$/D', $currency ) ), $path . '.currency' );
			$out['currency'] = $currency;
			$scope           = $action['scope'] ?? array();
			self::keys( $scope, array( 'products', 'categories' ), $path . '.scope' );
			foreach ( $scope as $key => $values ) {
				$scope[ $key ] = self::list_of( $values, 'ids', $path . '.scope.' . $key );
			}
			$out['scope']    = $scope;
			$out['progress'] = $action['progress'] ?? false;
			self::expect( is_bool( $out['progress'] ), $path . '.progress' );
		} elseif ( 'reject' === $type ) {
			self::keys( $action, array( 'type' ), $path );
		} else {
			$field = $action['field'] ?? '';
			self::expect( is_string( $field ) && isset( self::checkout_fields()[ $field ] ), $path . '.field' );
			$out['field'] = $field;
			$keys         = array( 'type', 'field' );
			if ( 'length' === $type ) {
				$keys = array_merge( $keys, array( 'min', 'max' ) );
				$min  = $action['min'] ?? 0;
				$max  = $action['max'] ?? 256;
				self::expect( is_int( $min ) && is_int( $max ) && $min >= 0 && $max >= $min && $max <= 1024, $path . '.length' );
				$out['min'] = $min;
				$out['max'] = $max;
			} elseif ( in_array( $type, array( 'email_allow', 'email_block' ), true ) ) {
				$keys[] = 'domains';
				self::expect( 'billing_email' === $field, $path . '.field' );
				$out['domains'] = self::list_of( $action['domains'] ?? null, 'domain', $path . '.domains' );
			} elseif ( 'pattern' === $type ) {
				$keys[]  = 'pattern';
				$pattern = $action['pattern'] ?? null;
				self::expect( is_string( $pattern ) && strlen( $pattern ) <= 160 && '' !== $pattern && ! preg_match( '/[\x00-\x1f~]|\(\*/', $pattern ), $path . '.pattern' );
				self::expect( null !== self::matches_pattern( $pattern, '' ), $path . '.pattern' );
				$out['pattern'] = $pattern;
			} elseif ( in_array( $type, array( 'postcode', 'phone' ), true ) ) {
				self::expect( in_array( $field, array( 'billing_' . $type, 'shipping_' . $type ), true ), $path . '.field' );
			}
			self::keys( $action, $keys, $path );
		}
		return $out;
	}

	public static function matches_pattern( string $pattern, string $value ): ?bool {
		if ( strlen( $value ) > 256 ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid merchant patterns are reported as a schema error.
		$result = @preg_match( '~(*NO_JIT)(*LIMIT_MATCH=5000)(*LIMIT_DEPTH=100)\A(?:' . $pattern . ')\z~u', $value );
		return false === $result ? null : 1 === $result;
	}

	private static function list_of( $values, string $type, string $path ): array {
		self::expect( self::is_list( $values ) && count( $values ) >= 1 && count( $values ) <= 50, $path );
		$out = array();
		foreach ( $values as $value ) {
			$out[] = self::identifier( $value, $type, $path );
		}
		return array_values( array_unique( $out ) );
	}

	private static function identifier( $value, string $type, string $path ): string {
		self::expect( is_string( $value ) && strlen( $value ) <= 128, $path );
		$value    = trim( $value );
		$patterns = array(
			'ids'     => '/^[1-9][0-9]{0,17}$/D',
			'code'    => '/^[A-Z0-9_-]{1,16}$/iD',
			'methods' => '/^[a-z0-9_-]+(?::[a-z0-9_-]+){0,3}$/iD',
			'domain'  => '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD',
			'coupons' => '/^[^<>,\x00-\x1f]+$/D',
		);
		self::expect( preg_match( $patterns[ $type ] ?? '/^[a-z0-9_-]+$/iD', $value ), $path );
		return 'code' === $type ? strtoupper( $value ) : strtolower( $value );
	}

	private static function number( $value, bool $integer, string $path ): string {
		self::expect( is_string( $value ) && preg_match( $integer ? '/^[0-9]{1,10}$/D' : '/^[0-9]{1,10}(?:\.[0-9]{1,8})?$/D', $value ), $path );
		return $value;
	}

	private static function plain( $value, int $max, string $path ): string {
		self::expect( is_string( $value ) && strlen( $value ) <= $max * 4, $path );
		$value = sanitize_text_field( $value );
		self::expect( mb_strlen( $value, 'UTF-8' ) <= $max, $path );
		return $value;
	}

	public static function is_list( $value ): bool {
		return is_array( $value ) && array_is_list( $value );
	}

	private static function keys( $value, array $keys, string $path ): void {
		self::expect( is_array( $value ) && ! array_diff( array_keys( $value ), $keys ), $path );
	}

	private static function expect( $valid, string $path ): void {
		if ( ! $valid ) {
			throw new \InvalidArgumentException( esc_html( $path ) );
		}
	}
}
