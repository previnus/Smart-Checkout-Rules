<?php
// Run inside a disposable WordPress site via wp eval-file tests/rules.php.
use SmartCheckoutRules\Admin\Simulator;
use SmartCheckoutRules\Modules\PaymentRules;
use SmartCheckoutRules\Rules\ConditionEvaluator;
use SmartCheckoutRules\Rules\RuleEngine;
use SmartCheckoutRules\Rules\RuleRepository;
use SmartCheckoutRules\Rules\RuleSchema;

defined( 'ABSPATH' ) || exit;

$check = static function ( bool $condition, string $description ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $description );
	}
};

$base = array(
	'schema_version' => 1,
	'id' => wp_generate_uuid4(),
	'name' => 'Test rule',
	'module' => 'payment',
	'enabled' => true,
	'conditions' => array(
		'all' => array(
			array( 'field' => 'cart.subtotal_after_discount_ex_tax', 'operator' => 'gte', 'value' => '50.00' ),
			array( 'any' => array(
				array( 'field' => 'destination.country', 'operator' => 'in', 'value' => array( 'US' ) ),
				array( 'field' => 'customer.roles', 'operator' => 'in', 'value' => array( 'wholesale' ) ),
			) ),
		),
	),
	'actions' => array( array( 'type' => 'hide_gateway', 'gateway_id' => 'cod' ) ),
);
$evaluator = new ConditionEvaluator();
$rule = RuleSchema::rule( $base );
$context = array( 'values' => array( 'cart.subtotal_after_discount_ex_tax' => 49.995, 'destination.country' => 'US' ), 'decimals' => 2 );
$check( $evaluator->matches( $rule['conditions'], $context['values'], 2 ), 'Nested rule matches a rounded threshold' );
$check( ! $evaluator->matches( $rule['conditions'], array( 'cart.subtotal_after_discount_ex_tax' => 50 ), 2 ), 'Missing destination and role cannot match' );
$allow = array_replace( $rule, array( 'id' => wp_generate_uuid4(), 'priority' => 10, 'actions' => array( array( 'type' => 'allow_gateway', 'gateway_id' => 'cod' ) ) ) );
$engine = new RuleEngine( array( $rule, $allow ), RuleRepository::DEFAULTS['modules'], $evaluator );
$result = PaymentRules::evaluate( $engine->matching( 'payment', $context ), array( 'cod', 'bacs' ) );
$check( array( 'bacs' ) === $result['available'], 'Hide wins over allow' );
$allow['stop_processing'] = true;
$engine = new RuleEngine( array( $rule, $allow ), RuleRepository::DEFAULTS['modules'], $evaluator );
$check( array( $allow ) === $engine->matching( 'payment', $context ), 'Priority and stop-processing' );
$check( ! PaymentRules::evaluate( array( $allow ), array() )['available'], 'Allow does not invent a gateway' );

try {
	RuleSchema::rule( $base + array( 'unexpected' => 'value' ) );
	throw new RuntimeException( 'Unknown rule key accepted' );
} catch ( InvalidArgumentException $expected ) {
	$check( 'rule' === $expected->getMessage(), 'Unknown rule key rejected' );
}
$check( true === RuleSchema::matches_pattern( '[A-Z]+[0-9]+', 'PO22' ), 'Full-field regex match' );
$check( false === RuleSchema::matches_pattern( 'PO22', 'XXXPO22' ), 'Partial regex match rejected' );
$check( null === RuleSchema::matches_pattern( '[', '' ), 'Malformed regex rejected' );
$check( true !== RuleSchema::matches_pattern( '(a+)+', str_repeat( 'a', 200 ) . '!' ), 'Pathological pattern denied within PCRE limits' );

$minimum = RuleSchema::rule( array_replace( $base, array(
	'module' => 'limits',
	'conditions' => array( 'all' => array() ),
	'actions' => array( array( 'type' => 'minimum', 'metric' => 'quantity', 'amount' => '5', 'scope' => array( 'categories' => array( '4' ) ) ) ),
) ) );
$simulation = Simulator::run( array( $minimum ), RuleRepository::DEFAULTS, array( 'lines' => array(
	array( 'quantity' => '4', 'categories' => array( '4' ) ),
	array( 'quantity' => '100', 'categories' => array( '5' ) ),
) ) );
$check( $simulation['blocked'] && 1 === count( $simulation['limits'] ), 'Scoped minimum ignores unrelated line items' );

foreach ( array( 10, 100, 500 ) as $count ) {
	$rules = array_fill( 0, $count, $rule );
	$start = hrtime( true );
	for ( $i = 0; $i < 30; ++$i ) {
		$engine = new RuleEngine( $rules, RuleRepository::DEFAULTS['modules'], $evaluator );
		$check( $count === count( $engine->matching( 'payment', $context ) ), 'All benchmark rules match' );
	}
	echo sprintf( "%d rules: %.2f ms per engine build and evaluation\n", $count, ( hrtime( true ) - $start ) / 30e6 );
}

echo "PASS schema, evaluator, priorities, conflict rules, limits, regex guardrails\n";
