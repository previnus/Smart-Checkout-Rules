<?php
namespace SmartCheckoutRules;

use SmartCheckoutRules\Admin\AdminPage;
use SmartCheckoutRules\Admin\RulesRestController;
use SmartCheckoutRules\Integrations\BlocksCheckout;
use SmartCheckoutRules\Integrations\ClassicCheckout;
use SmartCheckoutRules\Integrations\CheckoutPolicy;
use SmartCheckoutRules\Rules\ConditionEvaluator;
use SmartCheckoutRules\Rules\RuleEngine;
use SmartCheckoutRules\Rules\RuleRepository;
use SmartCheckoutRules\Support\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function boot(): void {
		global $wp_version;
		if ( PHP_VERSION_ID < 80300 || version_compare( $wp_version, '6.8', '<' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.5', '<' ) ) {
			add_action( 'admin_notices', array( self::class, 'requirements_notice' ) );
			return;
		}
		$repository = new RuleRepository();
		$controller = new RulesRestController( $repository );
		add_action( 'rest_api_init', array( $controller, 'register' ) );
		if ( is_admin() ) {
			$admin = new AdminPage();
			add_action( 'admin_menu', array( $admin, 'register' ) );
		}

		$snapshot = $repository->snapshot();
		$engine   = new RuleEngine( $snapshot['rules'], $snapshot['settings']['modules'], new ConditionEvaluator() );
		$policy   = new CheckoutPolicy( $engine, new Diagnostics( $snapshot['settings']['logging'] ) );
		$policy->register();
		( new ClassicCheckout( $policy, $engine ) )->register();
		( new BlocksCheckout( $policy, $engine ) )->register();
	}

	public static function requirements_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Smart Checkout Rules requires PHP 8.3, WordPress 6.8 and WooCommerce 10.5 or newer.', 'smart-checkout-rules' ) . '</p></div>';
		}
	}
}
