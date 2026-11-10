<?php
namespace SmartCheckoutRules;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

final class Compatibility {
	public static function declare_features(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', SCR_PLUGIN_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SCR_PLUGIN_FILE, true );
		}
	}
}
