<?php
namespace SmartCheckoutRules\Support;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {
	private array $events = array();

	public function __construct( private bool $enabled ) {}

	/** Log only fixed event codes and schema-validated rule IDs. */
	public function record( string $event, array $rule_ids ): void {
		$ids = array_values( array_unique( $rule_ids ) );
		sort( $ids );
		$key = $event . implode( ',', $ids );
		if ( ! $this->enabled || ! $ids || isset( $this->events[ $key ] ) ) {
			return;
		}
		$this->events[ $key ] = true;
		$payload              = array(
			'event'    => $event,
			'rule_ids' => $ids,
		);
		wc_get_logger()->warning( wp_json_encode( $payload ), array( 'source' => 'smart-checkout-rules' ) );
		// Save a small redacted summary for the admin dashboard.
		set_transient( 'scr_last_diagnostic', $payload, DAY_IN_SECONDS );
	}
}
