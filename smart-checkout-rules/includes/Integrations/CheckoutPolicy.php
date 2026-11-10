<?php
namespace SmartCheckoutRules\Integrations;

use SmartCheckoutRules\Context\CheckoutContextFactory;
use SmartCheckoutRules\Modules\CheckoutValidation;
use SmartCheckoutRules\Modules\OrderLimits;
use SmartCheckoutRules\Modules\PaymentRules;
use SmartCheckoutRules\Modules\ShippingRestrictions;
use SmartCheckoutRules\Rules\RuleEngine;
use SmartCheckoutRules\Support\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class CheckoutPolicy {
	private array $blocked_packages = array();
	private array $hidden_rates     = array();
	private array $payment_notices  = array();
	private bool $no_gateways       = false;

	public function __construct( private RuleEngine $engine, private Diagnostics $diagnostics ) {}

	public function register(): void {
		if ( $this->engine->has( 'payment' ) ) {
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'gateways' ), PHP_INT_MAX );
		}
		if ( $this->engine->has( 'shipping' ) ) {
			// WooCommerce caches raw rates before this filter, including third-party carrier rates.
			add_filter( 'woocommerce_shipping_packages', array( $this, 'packages' ), PHP_INT_MAX );
		}
	}

	public function gateways( array $gateways ): array {
		$this->no_gateways     = false;
		$this->payment_notices = array();
		if ( ! self::cart_request() || ! $gateways ) {
			return $gateways;
		}
		$context = CheckoutContextFactory::build();
		if ( ! $context ) {
			return $gateways;
		}
		$rules   = $this->engine->matching( 'payment', $context );
		$result  = PaymentRules::evaluate( $rules, array_keys( $gateways ) );
		$removed = array_intersect_key( $result['hidden'], $gateways );
		if ( $removed ) {
			$ids = array_merge( ...array_values( $removed ) );
			foreach ( $rules as $rule ) {
				if ( $rule['message'] && in_array( $rule['id'], $ids, true ) ) {
					$this->payment_notices[] = $rule['message'];
				}
			}
			if ( ! $result['available'] ) {
				$this->no_gateways = true;
				$this->diagnostics->record( 'no_gateways', $ids );
			}
		}
		return array_diff_key( $gateways, $result['hidden'] );
	}

	public function packages( array $packages ): array {
		$this->blocked_packages = array();
		$this->hidden_rates     = array();
		if ( ! self::cart_request() || ! $packages ) {
			return $packages;
		}
		$context = CheckoutContextFactory::build();
		if ( ! $context ) {
			return $packages;
		}
		foreach ( $packages as $index => $package ) {
			$rules = $this->engine->matching( 'shipping', CheckoutContextFactory::for_package( $context, $package ) );
			if ( ! $rules ) {
				continue;
			}
			$rates = array();
			foreach ( $package['rates'] ?? array() as $id => $rate ) {
				$rates[ $id ] = array(
					'method'   => strtolower( $rate->get_method_id() ),
					'instance' => (string) $rate->get_instance_id(),
				);
			}
			$result                       = ShippingRestrictions::evaluate( $rules, $rates );
			$packages[ $index ]['rates']  = array_intersect_key( $package['rates'] ?? array(), $result['available'] );
			$this->hidden_rates[ $index ] = $result['hidden'];
			if ( ! $result['available'] && $result['hidden'] ) {
				$ids                              = array_merge( ...array_values( $result['hidden'] ) );
				$this->blocked_packages[ $index ] = self::shipping_message( $rules, $ids );
				$this->diagnostics->record( 'no_rates', $ids );
			}
		}
		return $packages;
	}

	public function shipping_errors( \WP_Error $errors, array $requested = array() ): void {
		foreach ( $requested as $index => $rate ) {
			if ( is_string( $rate ) && isset( $this->hidden_rates[ $index ][ $rate ] ) && ! isset( $this->blocked_packages[ $index ] ) ) {
				$errors->add( 'scr_shipping_selection', __( 'Your selected shipping method is unavailable. Choose another shipping method.', 'smart-checkout-rules' ) );
				break;
			}
		}
		foreach ( array_unique( $this->blocked_packages ) as $index => $message ) {
			$errors->add( 'scr_shipping_' . $index, $message );
		}
	}

	public function validate( array $fields, \WP_Error $errors, bool $needs_payment ): void {
		if ( ! $this->engine->has( 'payment' ) && ! $this->engine->has( 'limits' ) && ! $this->engine->has( 'validation' ) ) {
			return;
		}
		$context = CheckoutContextFactory::build( $fields );
		if ( ! $context ) {
			return;
		}
		$method = $context['values']['checkout.payment_method'];
		if ( $needs_payment && $this->engine->has( 'payment' ) ) {
			$rules  = $this->engine->matching( 'payment', $context );
			$hidden = PaymentRules::evaluate( $rules, array() )['hidden'];
			if ( isset( $hidden[ $method ] ) ) {
				$errors->add( 'scr_payment', $this->payment_notices[0] ?? __( 'Your selected payment method is unavailable for this order. Choose another method.', 'smart-checkout-rules' ) );
			} elseif ( $this->no_gateways ) {
				$errors->add( 'scr_payment', $this->no_gateways_message() );
			}
		}
		foreach ( $this->limits( $context ) as $index => $violation ) {
			$errors->add( 'scr_limit_' . $index, self::limit_message( $violation ) );
		}
		$rules = $this->engine->matching( 'validation', $context );
		foreach ( CheckoutValidation::violations( $rules, $context ) as $index => $violation ) {
			$field = $violation['field'];
			$errors->add( $field ? $field . '_scr' : 'scr_validation_' . $index, $violation['message'], $field ? array( 'id' => $field ) : array() );
		}
	}

	public function preview_messages(): array {
		$messages = $this->no_gateways ? array( $this->no_gateways_message() ) : $this->payment_notices;
		$context  = $this->engine->has( 'limits' ) ? CheckoutContextFactory::build() : null;
		if ( $context ) {
			foreach ( $this->limits( $context ) as $violation ) {
				if ( $violation['progress'] ) {
					$messages[] = self::limit_message( $violation );
				}
			}
		}
		return array_slice( array_values( array_unique( array_merge( $messages, $this->blocked_packages ) ) ), 0, 12 );
	}

	private function limits( array $context ): array {
		return OrderLimits::violations( $this->engine->matching( 'limits', $context ), $context );
	}

	private static function limit_message( array $violation ): string {
		if ( '' === $violation['progress'] ) {
			return $violation['message'];
		}
		/* translators: %s: additional merchandise amount or number of units. */
		return $violation['message'] . ' ' . sprintf( __( 'Still needed: %s.', 'smart-checkout-rules' ), $violation['progress'] );
	}

	private function no_gateways_message(): string {
		return $this->payment_notices[0] ?? __( 'No payment method is available for this order. Update your checkout details or contact the store for help.', 'smart-checkout-rules' );
	}

	private static function shipping_message( array $rules, array $ids ): string {
		foreach ( $rules as $rule ) {
			if ( $rule['message'] && in_array( $rule['id'], $ids, true ) ) {
				return $rule['message'];
			}
		}
		return __( 'No shipping method is available for part of this order. Update your delivery address or contact the store for help.', 'smart-checkout-rules' );
	}

	private static function cart_request(): bool {
		if ( wp_is_serving_rest_request() ) {
			global $wp;
			return str_starts_with( (string) ( $wp->query_vars['rest_route'] ?? '' ), '/wc/store/' );
		}
		return ( ! is_admin() || wp_doing_ajax() ) && ! is_wc_endpoint_url( 'order-pay' ) && ! is_add_payment_method_page();
	}
}
